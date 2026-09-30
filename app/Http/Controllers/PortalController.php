<?php

namespace App\Http\Controllers;

use App\Models\HotspotGuest;
use App\Models\MikrotikRouter;
use App\Models\PortalMedia;
use App\Models\SplashPage;
use App\Rules\MobileOrEmail;
use App\Rules\PersonName;
use App\Services\Mikrotik\HotspotProvisioner;
use App\Services\Portal\GuestCredentials;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Public captive portal:
 *   1. Router's login.html sends the phone here (mac, ip, link-login-only...).
 *   2. Login page: the user enters their details and accepts the Terms.
 *   3. Details are saved; the phone goes to the advertisement page.
 *   4. User taps Connect; the server logs the phone in on the router through
 *      the MikroTik API, then sends it to the page after login.
 *      If the API can't be reached, the phone logs itself in instead (fallback).
 */
class PortalController extends Controller
{
    public function show(Request $request, MikrotikRouter $router)
    {
        if ($request->hasAny(['mac', 'link-login-only', 'error'])) {
            $request->session()->put($this->key($router), $this->context($request, $router));

            // Clean URL, so a refresh doesn't resubmit the router's parameters.
            return redirect()->route('portal.show', ['router' => $router->portal_code]);
        }

        return $this->render($router, SplashPage::current(), preview: false);
    }

    public function login(Request $request, MikrotikRouter $router, GuestCredentials $credentials)
    {
        $ctx = $request->session()->get($this->key($router), []);
        $page = SplashPage::current();

        // Per device, not per IP: every phone behind a router shares one public IP.
        $limitKey = 'portal:'.$router->id.':'.($ctx['mac'] ?? $request->session()->getId());
        if (RateLimiter::tooManyAttempts($limitKey, 8)) {
            return back()->withInput()->withErrors(['form' => 'Too many attempts. Please wait a minute and try again.']);
        }
        RateLimiter::hit($limitKey, 60);

        $resident = $request->boolean('resident');
        $rules = ['accept' => ['accepted']];
        if ($resident) {
            $rules['citizen_number'] = ['required', 'string', 'max:40', function ($attr, $value, $fail) use ($page) {
                if (! preg_match($page->citizenRegex(), trim((string) $value))) {
                    $fail("Enter a valid {$page->citizen_label}.");
                }
            }];
        } else {
            $rules['name'] = ['required', 'string', 'max:80', new PersonName($page->blockedWords())];
            $rules['contact'] = ['required', 'string', 'max:254', new MobileOrEmail];
        }

        $data = $request->validate($rules, [
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

        try {
            [$username, $password] = $credentials->issue($router, $ctx['mac'] ?? null);
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->withErrors(['form' => 'We could not connect you right now. Please try again in a moment.']);
        }

        [$contactType, $contact] = $resident ? [null, null] : MobileOrEmail::normalize($data['contact']);

        $guest = HotspotGuest::create([
            'mikrotik_router_id' => $router->id,
            'resident' => $resident,
            'name' => $resident ? null : PersonName::clean($data['name']),
            'contact' => $contact,
            'contact_type' => $contactType,
            'citizen_number' => $resident ? trim($data['citizen_number']) : null,
            'mac' => $ctx['mac'] ?? null,
            'ip' => $ctx['ip'] ?? null,
            'username' => $username,
            'terms_hash' => $page->termsHash(),
            'expires_at' => now()->addHours(config('hotspot.credential_hours')),
        ]);

        RateLimiter::clear($limitKey);

        // Credentials wait in the session (encrypted) until the user taps Connect.
        $request->session()->put($this->key($router).'.pending', [
            'guest_id' => $guest->id,
            'username' => $username,
            'password' => Crypt::encryptString($password),
            'at' => now()->getTimestamp(),
        ]);

        return redirect()->route('portal.welcome', ['router' => $router->portal_code]);
    }

    /** Advertisement page with the Connect button. Only after the details are saved. */
    public function welcome(Request $request, MikrotikRouter $router)
    {
        $pending = $request->session()->get($this->key($router).'.pending');
        if (! $pending) {
            return redirect()->route('portal.show', ['router' => $router->portal_code]);
        }

        $page = SplashPage::current();
        $wait = max(0, $page->ad_min_seconds - (now()->getTimestamp() - $pending['at']));

        return self::renderAd($router, $page, preview: false, wait: $wait);
    }

    /** The Connect button: log the phone in on the router, then open the page after login. */
    public function connect(Request $request, MikrotikRouter $router, HotspotProvisioner $routerApi)
    {
        $key = $this->key($router);
        $ctx = $request->session()->get($key, []);
        $pending = $ctx['pending'] ?? null;
        if (! $pending) {
            return redirect()->route('portal.show', ['router' => $router->portal_code]);
        }

        $page = SplashPage::current();
        if (now()->getTimestamp() - $pending['at'] < $page->ad_min_seconds) {
            return redirect()->route('portal.welcome', ['router' => $router->portal_code]);
        }

        $password = Crypt::decryptString($pending['password']);
        $destination = $page->success_url ?: ($ctx['link_orig'] ?? null);
        $guest = HotspotGuest::find($pending['guest_id']);

        // Server -> router: authenticate this MAC and IP.
        if (! empty($ctx['mac']) && ! empty($ctx['ip'])) {
            try {
                $routerApi->hotspotLogin($router, $pending['username'], $password, $ctx['mac'], $ctx['ip']);
                $guest?->update(['connected_at' => now(), 'login_method' => 'api']);
                $request->session()->forget($key);

                return $destination
                    ? redirect()->away($destination)
                    : view('portal.connected', ['page' => $page, 'router' => $router]);
            } catch (Throwable $e) {
                report($e); // fall through to the browser login below
            }
        }

        // Fallback: the phone logs itself in on the router (same credentials, PAP).
        if (empty($ctx['link_login_only'])) {
            return redirect()->route('portal.welcome', ['router' => $router->portal_code])
                ->withErrors(['connect' => 'We could not reach the WiFi router. Please try again in a moment.']);
        }

        $guest?->update(['connected_at' => now(), 'login_method' => 'browser']);
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

    /** Renders the advertisement HTML with the Connect button at [[connect]]. Also used by the editor preview. */
    public static function renderAd(MikrotikRouter $router, SplashPage $page, bool $preview, int $wait = 0)
    {
        $wait = $preview ? (int) $page->ad_min_seconds : $wait;

        $button = view('portal.connect', [
            'router' => $router,
            'page' => $page,
            'preview' => $preview,
            'wait' => $wait,
            'action' => $preview ? '#' : route('portal.connect', ['router' => $router->portal_code]),
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
            '[[site_name]]' => e($page->site_name),
            '[[router_name]]' => e($router->name),
            '[[location]]' => e((string) $router->location),
        ]);

        return response($html)->header('Cache-Control', 'no-store');
    }

    public function terms(MikrotikRouter $router)
    {
        $page = SplashPage::current();

        return view('portal.terms', ['page' => $page, 'router' => $router]);
    }

    /** The login.html the router downloads. Replaces the router's own login page. */
    public function routerLoginFile(MikrotikRouter $router)
    {
        abort_unless($router->usesExternalLogin(), 404);

        return response()
            ->view('portal.router-login', ['target' => $router->loginTarget()])
            ->header('Content-Type', 'text/html; charset=utf-8');
    }

    /** Renders the admin's HTML with the form injected. Also used for the editor preview. */
    public static function render(MikrotikRouter $router, SplashPage $page, bool $preview)
    {
        $ctx = $preview ? [] : session('portal.'.$router->portal_code, []);

        $form = view('portal.form', [
            'router' => $router,
            'page' => $page,
            'preview' => $preview,
            'routerError' => $ctx['error'] ?? null,
            'action' => $preview ? '#' : route('portal.login', ['router' => $router->portal_code]),
        ])->render();

        $html = strtr($page->html, [
            '[[form]]' => $form,
            '[[site_name]]' => e($page->site_name),
            '[[router_name]]' => e($router->name),
            '[[location]]' => e((string) $router->location),
        ]);

        return response($html)->header('Cache-Control', 'no-store');
    }

    private function key(MikrotikRouter $router): string
    {
        return 'portal.'.$router->portal_code;
    }

    /** Keeps only well-formed router parameters; the login link must point at this router. */
    private function context(Request $request, MikrotikRouter $router): array
    {
        $mac = strtoupper((string) $request->query('mac'));
        $ip = (string) $request->query('ip');
        $login = (string) $request->query('link-login-only');
        $loginHost = parse_url($login, PHP_URL_HOST);
        $scheme = parse_url($login, PHP_URL_SCHEME);
        $allowedHosts = array_filter([$router->gateway, config('hotspot.dns_name')]);

        return [
            'mac' => preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac) ? $mac : null,
            'ip' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null,
            'link_login_only' => in_array($scheme, ['http', 'https'], true) && in_array($loginHost, $allowedHosts, true) ? $login : null,
            'link_orig' => filter_var($request->query('link-orig'), FILTER_VALIDATE_URL) ?: null,
            'error' => mb_substr(strip_tags((string) $request->query('error')), 0, 200) ?: null,
        ];
    }
}
