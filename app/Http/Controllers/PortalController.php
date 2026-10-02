<?php

namespace App\Http\Controllers;

use App\Models\HotspotGuest;
use App\Models\HotspotNetwork;
use App\Models\PortalMedia;
use App\Models\SplashPage;
use App\Rules\MobileOrEmail;
use App\Rules\PersonName;
use App\Services\Mikrotik\HotspotProvisioner;
use App\Services\Portal\AccessValidity;
use App\Services\Portal\GuestCredentials;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Public captive portal, one per hotspot network (/portal/{portal_code}):
 *   1. Router's login.html sends the phone here (mac, ip, link-login-only...).
 *      The network decides which design supplies the login page and the ad page.
 *   2. Login page: the user enters their details and accepts the Terms.
 *   3. Details are saved; the phone goes to the advertisement page.
 *   4. User taps Connect; the server logs the phone in on the router through
 *      the MikroTik API, then sends it to the page after login.
 *      If the API can't be reached, the phone logs itself in instead (fallback).
 *
 * Roaming: a phone whose registration is still valid (its validity depends on the
 * type of user, see Settings) skips the pages on every router and network and goes
 * straight online. With RADIUS the router already does this by MAC before the phone
 * ever reaches the portal; this covers routers without RADIUS and any MAC miss.
 */
class PortalController extends Controller
{
    public function show(Request $request, HotspotNetwork $network, GuestCredentials $credentials, HotspotProvisioner $routerApi)
    {
        if ($request->hasAny(['mac', 'link-login-only', 'error'])) {
            $ctx = $this->context($request, $network);
            $request->session()->put($this->key($network), $ctx);

            if ($online = $this->roam($request, $network, $ctx, $credentials, $routerApi)) {
                return $online;
            }

            // Clean URL, so a refresh doesn't resubmit the router's parameters.
            return redirect()->route('portal.show', ['network' => $network->portal_code]);
        }

        return $this->render($network, $network->loginDesign(), preview: false);
    }

    public function login(Request $request, HotspotNetwork $network, GuestCredentials $credentials)
    {
        $ctx = $request->session()->get($this->key($network), []);
        $page = $network->loginDesign();
        $router = $network->router;

        // Per device, not per IP: every phone behind a router shares one public IP.
        $limitKey = 'portal:'.$network->id.':'.($ctx['mac'] ?? $request->session()->getId());
        if (RateLimiter::tooManyAttempts($limitKey, 8)) {
            return back()->withInput()->withErrors(['form' => 'Too many attempts. Please wait a minute and try again.']);
        }
        RateLimiter::hit($limitKey, 60);

        // Older login pages only send resident=1
        $category = (string) $request->input('category', $request->boolean('resident') ? 'resident' : 'visitor');
        $resident = $category === 'resident';
        $rules = ['accept' => ['accepted'], 'category' => ['required', 'in:resident,visitor,student']];
        if ($resident) {
            $rules['citizen_number'] = ['required', 'string', 'max:40', function ($attr, $value, $fail) use ($page) {
                if (! preg_match($page->citizenRegex(), trim((string) $value))) {
                    $fail("Enter a valid {$page->citizen_label}.");
                }
            }];
        } else {
            $rules['name'] = ['required', 'string', 'max:80', new PersonName($page->blockedWords())];
        }
        if ($category === 'visitor') {
            $rules['contact'] = ['required', 'string', 'max:254', new MobileOrEmail];
        }
        if ($category === 'student') {
            $rules['school'] = ['required', 'string', 'min:3', 'max:120'];
            $rules['student_number'] = ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9\-\/ ]{2,39}$/'];
        }

        $request->merge(['category' => $category]);
        $data = $request->validate($rules, [
            'category.in' => 'Choose resident, visitor or student.',
            'school.required' => 'Enter the name of your school.',
            'school.min' => 'Enter the name of your school.',
            'student_number.required' => 'Enter your student ID number.',
            'student_number.regex' => 'Use letters, numbers and dashes only.',
            'accept.accepted' => 'Please read and accept the Terms and Conditions.',
            'name.required' => 'Enter your full name.',
            'contact.required' => 'Enter your mobile number or email.',
            'citizen_number.required' => "Enter your {$page->citizen_label}.",
        ]);

        if (empty($ctx['link_login_only'])) {
            return back()->withInput()->withErrors([
                'form' => 'Connect to the WiFi first, then open any website to reach this page.',
            ]);
        }

        $expiresAt = app(AccessValidity::class)->expiresAt($category);

        try {
            [$username, $password] = $credentials->issue($router, $ctx['mac'] ?? null, $expiresAt);
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->withErrors(['form' => 'We could not connect you right now. Please try again in a moment.']);
        }

        [$contactType, $contact] = $category === 'visitor' ? MobileOrEmail::normalize($data['contact']) : [null, null];

        $guest = HotspotGuest::create([
            'mikrotik_router_id' => $router->id,
            'hotspot_network_id' => $network->id,
            'resident' => $resident,
            'category' => $category,
            'school' => $category === 'student' ? preg_replace('/\s+/', ' ', trim($data['school'])) : null,
            'student_number' => $category === 'student' ? strtoupper(trim($data['student_number'])) : null,
            'password' => $password,
            'name' => $resident ? null : PersonName::clean($data['name']),
            'contact' => $contact,
            'contact_type' => $contactType,
            'citizen_number' => $resident ? trim($data['citizen_number']) : null,
            'mac' => $ctx['mac'] ?? null,
            'ip' => $ctx['ip'] ?? null,
            'username' => $username,
            'terms_hash' => $page->termsHash(),
            'expires_at' => $expiresAt,
        ]);

        RateLimiter::clear($limitKey);

        // Credentials wait in the session (encrypted) until the user taps Connect.
        $request->session()->put($this->key($network).'.pending', [
            'guest_id' => $guest->id,
            'username' => $username,
            'password' => Crypt::encryptString($password),
            'at' => now()->getTimestamp(),
        ]);

        return redirect()->route('portal.welcome', ['network' => $network->portal_code]);
    }

    /** Advertisement page with the Connect button. Only after the details are saved. */
    public function welcome(Request $request, HotspotNetwork $network)
    {
        $pending = $request->session()->get($this->key($network).'.pending');
        if (! $pending) {
            return redirect()->route('portal.show', ['network' => $network->portal_code]);
        }

        $page = $network->adDesign();
        $wait = max(0, $page->ad_min_seconds - (now()->getTimestamp() - $pending['at']));

        return self::renderAd($network, $page, preview: false, wait: $wait);
    }

    /** The Connect button: log the phone in on the router, then open the page after login. */
    public function connect(Request $request, HotspotNetwork $network, HotspotProvisioner $routerApi)
    {
        $key = $this->key($network);
        $ctx = $request->session()->get($key, []);
        $pending = $ctx['pending'] ?? null;
        if (! $pending) {
            return redirect()->route('portal.show', ['network' => $network->portal_code]);
        }

        $page = $network->adDesign();
        $router = $network->router;
        if (now()->getTimestamp() - $pending['at'] < $page->ad_min_seconds) {
            return redirect()->route('portal.welcome', ['network' => $network->portal_code]);
        }

        $password = Crypt::decryptString($pending['password']);
        $destination = $page->success_url ?: ($ctx['link_orig'] ?? null);
        $guest = HotspotGuest::find($pending['guest_id']);

        // Server -> router: authenticate this MAC and IP.
        if (! empty($ctx['mac']) && ! empty($ctx['ip'])) {
            try {
                $routerApi->hotspotLogin($router, $pending['username'], $password, $ctx['mac'], $ctx['ip']);
                $guest?->update(['connected_at' => now(), 'last_connected_at' => now(), 'login_method' => 'api']);
                $request->session()->forget($key);

                return $destination
                    ? redirect()->away($destination)
                    : view('portal.connected', ['page' => $page, 'router' => $router, 'network' => $network]);
            } catch (Throwable $e) {
                report($e); // fall through to the browser login below
            }
        }

        // Fallback: the phone logs itself in on the router (same credentials, PAP).
        if (empty($ctx['link_login_only'])) {
            return redirect()->route('portal.welcome', ['network' => $network->portal_code])
                ->withErrors(['connect' => 'We could not reach the WiFi router. Please try again in a moment.']);
        }

        $guest?->update(['connected_at' => now(), 'last_connected_at' => now(), 'login_method' => 'browser']);
        $request->session()->forget($key);

        $login = $ctx['link_login_only'];
        $query = http_build_query([
            'username' => $pending['username'],
            'password' => $password,
            'dst' => $destination ?? '',
            'popup' => 'false',
        ]);

        return redirect()->away($login.(str_contains($login, '?') ? '&' : '?').$query);
    }

    /**
     * A phone that is still registered goes straight online: no login page, no ads.
     * Returns null when the portal should be shown (unknown phone, expired, or the
     * router just rejected a login, which would otherwise loop).
     */
    private function roam(Request $request, HotspotNetwork $network, array $ctx, GuestCredentials $credentials, HotspotProvisioner $routerApi)
    {
        if (! empty($ctx['error']) || empty($ctx['mac'])) {
            return null;
        }
        $guest = HotspotGuest::validFor($ctx['mac']);
        if (! $guest) {
            return null;
        }

        $router = $network->router;
        $page = $network->adDesign();
        $destination = $page->success_url ?: ($ctx['link_orig'] ?? null);
        $done = function (string $method) use ($guest, $request, $network) {
            $guest->forceFill([
                'connected_at' => $guest->connected_at ?? now(),
                'last_connected_at' => now(),
                'login_method' => $guest->login_method ?? $method,
                'roams' => $guest->roams + 1,
            ])->save();
            $request->session()->forget($this->key($network));
        };

        try {
            $credentials->roam($guest, $router); // without RADIUS: copy the login to this router
        } catch (Throwable $e) {
            report($e);

            return null; // router unreachable: let them register here instead
        }

        if (! empty($ctx['ip'])) {
            try {
                $routerApi->hotspotLogin($router, $guest->username, $guest->password, $ctx['mac'], $ctx['ip']);
                $done('api');

                return $destination
                    ? redirect()->away($destination)
                    : view('portal.connected', ['page' => $page, 'router' => $router, 'network' => $network]);
            } catch (Throwable $e) {
                report($e);
            }
        }

        if (empty($ctx['link_login_only'])) {
            return null;
        }
        $done('browser');
        $login = $ctx['link_login_only'];

        return redirect()->away($login.(str_contains($login, '?') ? '&' : '?').http_build_query([
            'username' => $guest->username,
            'password' => $guest->password,
            'dst' => $destination ?? '',
            'popup' => 'false',
        ]));
    }

    /** Renders the advertisement HTML with the Connect button at [[connect]]. Also used by the editor preview. */
    public static function renderAd(HotspotNetwork $network, SplashPage $page, bool $preview, int $wait = 0)
    {
        $wait = $preview ? (int) $page->ad_min_seconds : $wait;

        $button = view('portal.connect', [
            'router' => $network->router,
            'network' => $network,
            'page' => $page,
            'preview' => $preview,
            'wait' => $wait,
            'action' => $preview ? '#' : route('portal.connect', ['network' => $network->portal_code]),
        ])->render();

        // [[media:ID]] -> optimized <img> or <video>. Only the first photo loads eagerly.
        preg_match_all('/\[\[media:(\d+)\]\]/', (string) $page->ad_html, $m);
        $items = PortalMedia::query()->whereIn('id', array_unique($m[1] ?? []))->get()->keyBy('id');
        $first = true;
        $withMedia = preg_replace_callback('/\[\[media:(\d+)\]\]/', function ($match) use ($items, &$first, $preview) {
            $item = $items->get((int) $match[1]);
            if (! $item || $item->status !== 'ready') {
                return $preview
                    ? '<p style="padding:14px;border:1px dashed #A7B4AD;border-radius:12px;color:#56666E;font:14px system-ui">Media #'.(int) $match[1].($item ? ' is still being prepared.' : ' was deleted. Remove this placeholder.').'</p>'
                    : '';
            }
            $html = $item->markup($first);
            $first = false;

            return $html;
        }, (string) $page->ad_html);

        $html = strtr($withMedia, [
            '[[connect]]' => $button,
            ...self::placeholders($network, $page),
        ]);

        return response($html)->header('Cache-Control', 'no-store');
    }

    public function terms(HotspotNetwork $network)
    {
        return view('portal.terms', ['page' => $network->loginDesign(), 'router' => $network->router, 'network' => $network]);
    }

    /**
     * The login.html the router downloads. One file serves every hotspot network
     * on that router: it maps the hotspot server name to the network's target.
     */
    public function routerLoginFile(HotspotNetwork $network)
    {
        $networks = $network->router->hotspotNetworks;
        abort_unless($networks->contains(fn (HotspotNetwork $n) => $n->usesExternalLogin()), 404);

        $targets = $networks->mapWithKeys(fn (HotspotNetwork $n) => [$n->serverName() => $n->loginTarget()])->all();

        return response()
            ->view('portal.router-login', [
                'targets' => $targets,
                'fallback' => $networks->first(fn (HotspotNetwork $n) => $n->usesExternalLogin())->loginTarget(),
                'plain' => in_array(null, $targets, true),
            ])
            ->header('Content-Type', 'text/html; charset=utf-8');
    }

    /** Renders the admin's HTML with the form injected. Also used for the editor preview. */
    public static function render(HotspotNetwork $network, SplashPage $page, bool $preview)
    {
        $ctx = $preview ? [] : session('portal.'.$network->portal_code, []);

        $form = view('portal.form', [
            'router' => $network->router,
            'network' => $network,
            'page' => $page,
            'preview' => $preview,
            'routerError' => $ctx['error'] ?? null,
            'action' => $preview ? '#' : route('portal.login', ['network' => $network->portal_code]),
        ])->render();

        $html = strtr($page->html, [
            '[[form]]' => $form,
            ...self::placeholders($network, $page),
        ]);

        return response($html)->header('Cache-Control', 'no-store');
    }

    /** Values for the placeholders both pages share. */
    private static function placeholders(HotspotNetwork $network, SplashPage $page): array
    {
        return [
            '[[site_name]]' => e($page->site_name),
            '[[network_name]]' => e((string) $network->name),
            '[[router_name]]' => e((string) $network->router?->name),
            '[[location]]' => e((string) $network->router?->location),
        ];
    }

    private function key(HotspotNetwork $network): string
    {
        return 'portal.'.$network->portal_code;
    }

    /** Keeps only well-formed router parameters; the login link must point at this network's gateway. */
    private function context(Request $request, HotspotNetwork $network): array
    {
        $mac = strtoupper((string) $request->query('mac'));
        $ip = (string) $request->query('ip');
        $login = (string) $request->query('link-login-only');
        $loginHost = parse_url($login, PHP_URL_HOST);
        $scheme = parse_url($login, PHP_URL_SCHEME);
        $allowedHosts = array_filter([$network->gateway, config('hotspot.dns_name')]);

        return [
            'mac' => preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac) ? $mac : null,
            'ip' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null,
            'link_login_only' => in_array($scheme, ['http', 'https'], true) && in_array($loginHost, $allowedHosts, true) ? $login : null,
            'link_orig' => filter_var($request->query('link-orig'), FILTER_VALIDATE_URL) ?: null,
            'error' => mb_substr(strip_tags((string) $request->query('error')), 0, 200) ?: null,
        ];
    }
}
