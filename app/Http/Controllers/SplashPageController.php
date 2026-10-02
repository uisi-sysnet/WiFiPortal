<?php

namespace App\Http\Controllers;

use App\Models\HotspotNetwork;
use App\Models\MikrotikRouter;
use App\Models\PortalMedia;
use App\Models\SplashPage;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Captive portal designs. Each design has a login page and an advertisement
 * page; each hotspot network picks one design for each (see HotspotNetwork).
 */
class SplashPageController extends Controller
{
    /** The menu link opens the default design. */
    public function index()
    {
        return redirect()->route('splash.login');
    }

    public function edit(SplashPage $page)
    {
        return redirect()->route('splash.login.design', $page);
    }

    public function loginDefault()
    {
        return $this->editor(SplashPage::current(), 'login');
    }

    public function advertisementDefault()
    {
        return $this->editor(SplashPage::current(), 'advertisement');
    }

    public function login(SplashPage $page)
    {
        return $this->editor($page, 'login');
    }

    public function advertisement(SplashPage $page)
    {
        return $this->editor($page, 'advertisement');
    }

    private function editor(SplashPage $page, string $editorPage)
    {
        SplashPage::current(); // makes sure a default design exists

        return view('splash.'.$editorPage, [
            'page' => $page,
            'editorPage' => $editorPage,
            'designs' => SplashPage::query()->orderBy('id')->get(['id', 'name']),
            'loginNetworks' => $this->networksUsing($page, 'login_page_id'),
            'adNetworks' => $this->networksUsing($page, 'ad_page_id'),
            'media' => PortalMedia::query()->latest()->get()->map->toEditor()->values(),
            'mediaConfig' => config('hotspot.media') + ['ffmpeg_ready' => (bool) config('hotspot.media.ffmpeg')],
        ]);
    }

    /** New design: a copy of an existing one, or the default templates. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'design_name' => ['required', 'string', 'max:80', Rule::unique('splash_pages', 'name')],
            'from' => ['nullable', 'integer', Rule::exists('splash_pages', 'id')],
        ], [], ['design_name' => 'design name']);
        $data['name'] = $data['design_name'];

        $source = isset($data['from']) ? SplashPage::find($data['from']) : null;
        $page = $source
            ? tap($source->replicate()->fill(['name' => $data['name']]))->save()
            : SplashPage::create(['name' => $data['name']] + SplashPage::defaults());

        return $this->editorRoute($page, $request->input('editor_page'))
            ->with('status', "Design \"{$page->name}\" created. Choose it for a hotspot network on the router's page.");
    }

    public function update(Request $request, SplashPage $page)
    {
        $page->update($this->validated($request, $page));

        return redirect()->route('splash.design', $page)->with('status', 'Design saved. Phones see the new version on their next visit.');
    }

    public function updateLogin(Request $request, SplashPage $page)
    {
        $page->update($this->validatedLogin($request, $page));

        return $this->editorRoute($page, 'login')->with('status', 'Login page saved. Phones see the new version on their next visit.');
    }

    public function updateAdvertisement(Request $request, SplashPage $page)
    {
        $page->update($this->validatedAdvertisement($request, $page));

        return $this->editorRoute($page, 'advertisement')->with('status', 'Advertisement page saved. Phones see the new version on their next visit.');
    }

    public function destroy(Request $request, SplashPage $page)
    {
        if ($page->isDefault()) {
            return back()->withErrors(['design' => 'The first design is the default and cannot be deleted.']);
        }
        $users = $this->networksUsing($page, 'login_page_id')->merge($this->networksUsing($page, 'ad_page_id'))->unique('id');
        if ($users->isNotEmpty()) {
            return back()->withErrors(['design' => 'Still used by '.$users->map(fn ($n) => $n->router->name.' / '.$n->name)->implode(', ')
                .'. Choose another design for those networks first.']);
        }

        $name = $page->name;
        $page->delete();

        $route = $request->input('editor_page') === 'advertisement' ? 'splash.advertisement' : 'splash.login';

        return redirect()->route($route)->with('status', "Design \"{$name}\" deleted.");
    }

    /** Preview the editor's current (unsaved) content in a new tab. */
    public function preview(Request $request, SplashPage $page)
    {
        $editorPage = $request->input('editor_page', 'login');
        $data = $editorPage === 'advertisement'
            ? $this->validatedAdvertisement($request, $page)
            : $this->validatedLogin($request, $page);
        $page = $page->replicate()->fill($data);
        $router = new MikrotikRouter(['name' => 'Sample router', 'location' => 'Sample location']);
        $network = new HotspotNetwork(['name' => 'Sample network']);
        $network->portal_code = 'preview';
        $network->setRelation('router', $router);

        return $request->input('preview_page') === 'ad'
            ? PortalController::renderAd($network, $page, preview: true)
            : PortalController::render($network, $page, preview: true);
    }

    public function resetTemplate(Request $request, SplashPage $page)
    {
        if ($request->input('which') === 'ad') {
            $page->update(['ad_html' => SplashPage::defaultAdHtml()]);

            return $this->editorRoute($page, 'advertisement')->with('status', 'Advertisement page reset to the default template.');
        }

        $page->update(['html' => SplashPage::defaultHtml()]);

        return $this->editorRoute($page, 'login')->with('status', 'Login page reset to the default template. Terms and form settings were kept.');
    }

    private function editorRoute(SplashPage $page, ?string $editorPage = null)
    {
        return $editorPage === 'advertisement'
            ? redirect()->route('splash.advertisement.design', $page)
            : redirect()->route('splash.login.design', $page);
    }

    private function networksUsing(SplashPage $page, string $column)
    {
        $query = HotspotNetwork::query()->with('router:id,name')->orderBy('mikrotik_router_id')->orderBy('id');

        // Networks without a choice use the default design.
        return $page->isDefault()
            ? $query->where(fn ($q) => $q->where($column, $page->id)->orWhereNull($column))->get()
            : $query->where($column, $page->id)->get();
    }

    private function validated(Request $request, SplashPage $page): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('splash_pages', 'name')->ignore($page->id)],
            'site_name' => ['required', 'string', 'max:80'],
            'html' => ['required', 'string', 'max:200000', function ($attr, $value, $fail) {
                if (! str_contains($value, '[[form]]')) {
                    $fail('The page HTML must contain [[form]] where the login form goes.');
                }
            }],
            'terms' => ['required', 'string', 'max:50000'],
            'citizen_label' => ['required', 'string', 'max:60'],
            'citizen_hint' => ['nullable', 'string', 'max:160'],
            'citizen_pattern' => ['required', 'string', 'max:200', function ($attr, $value, $fail) {
                $regex = '~^(?:'.str_replace('~', '\~', $value).')$~u';
                if (@preg_match($regex, '') === false) {
                    $fail('The citizen number pattern is not a valid regular expression.');
                }
            }],
            'blocked_words' => ['nullable', 'string', 'max:20000'],
            'success_url' => ['nullable', 'url:http,https', 'max:255'],
            'ad_html' => ['required', 'string', 'max:200000', function ($attr, $value, $fail) {
                if (! str_contains($value, '[[connect]]')) {
                    $fail('The advertisement page must contain [[connect]] where the Connect button goes.');
                }
            }],
            'ad_button_label' => ['required', 'string', 'max:40'],
            'ad_min_seconds' => ['required', 'integer', 'between:0,120'],
        ], [], [
            'name' => 'design name',
            'html' => 'login page HTML',
            'ad_html' => 'advertisement page HTML',
            'ad_button_label' => 'button label',
            'ad_min_seconds' => 'wait before Connect',
            'success_url' => 'page after login',
        ]);
    }

    private function validatedLogin(Request $request, SplashPage $page): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('splash_pages', 'name')->ignore($page->id)],
            'site_name' => ['required', 'string', 'max:80'],
            'html' => ['required', 'string', 'max:200000', function ($attribute, $value, $fail) {
                if (! str_contains($value, '[[form]]')) {
                    $fail('The page HTML must contain [[form]] where the login form goes.');
                }
            }],
            'terms' => ['required', 'string', 'max:50000'],
            'citizen_label' => ['required', 'string', 'max:60'],
            'citizen_hint' => ['nullable', 'string', 'max:160'],
            'citizen_pattern' => ['required', 'string', 'max:200', function ($attribute, $value, $fail) {
                $regex = '~^(?:'.str_replace('~', '\\~', $value).')$~u';
                if (@preg_match($regex, '') === false) {
                    $fail('The citizen number pattern is not a valid regular expression.');
                }
            }],
            'blocked_words' => ['nullable', 'string', 'max:20000'],
        ], [], ['name' => 'design name', 'html' => 'login page HTML']);
    }

    private function validatedAdvertisement(Request $request, SplashPage $page): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('splash_pages', 'name')->ignore($page->id)],
            'site_name' => ['required', 'string', 'max:80'],
            'ad_html' => ['required', 'string', 'max:200000', function ($attribute, $value, $fail) {
                if (! str_contains($value, '[[connect]]')) {
                    $fail('The advertisement page must contain [[connect]] where the Connect button goes.');
                }
            }],
            'ad_button_label' => ['required', 'string', 'max:40'],
            'ad_min_seconds' => ['required', 'integer', 'between:0,120'],
            'success_url' => ['nullable', 'url:http,https', 'max:255'],
        ], [], ['name' => 'design name', 'ad_html' => 'advertisement page HTML']);
    }
}
