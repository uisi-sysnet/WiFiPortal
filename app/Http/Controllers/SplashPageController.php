<?php

namespace App\Http\Controllers;

use App\Models\MikrotikRouter;
use App\Models\PortalMedia;
use App\Models\SplashPage;
use Illuminate\Http\Request;

class SplashPageController extends Controller
{
    public function edit()
    {
        return view('splash.edit', [
            'page' => SplashPage::current(),
            'portalRouters' => MikrotikRouter::query()->where('login_mode', 'portal')->orderBy('name')->get(),
            'media' => PortalMedia::query()->latest()->get()->map->toEditor()->values(),
            'mediaConfig' => config('hotspot.media') + ['ffmpeg_ready' => (bool) config('hotspot.media.ffmpeg')],
        ]);
    }

    public function update(Request $request)
    {
        $page = SplashPage::current();
        $page->update($this->validated($request));

        return redirect()->route('splash.edit')->with('status', 'Captive portal saved. Phones see the new version on their next visit.');
    }

    /** Preview the editor's current (unsaved) content in a new tab. */
    public function preview(Request $request)
    {
        $page = SplashPage::current()->replicate()->fill($this->validated($request));
        $router = new MikrotikRouter(['name' => 'Sample router', 'location' => 'Sample location']);
        $router->portal_code = 'preview';

        return $request->input('preview_page') === 'ad'
            ? PortalController::renderAd($router, $page, preview: true)
            : PortalController::render($router, $page, preview: true);
    }

    public function resetTemplate(Request $request)
    {
        if ($request->input('which') === 'ad') {
            SplashPage::current()->update(['ad_html' => SplashPage::defaultAdHtml()]);

            return redirect()->route('splash.edit')->with('status', 'Advertisement page reset to the default template.');
        }

        SplashPage::current()->update(['html' => SplashPage::defaultHtml()]);

        return redirect()->route('splash.edit')->with('status', 'Login page reset to the default template. Terms and form settings were kept.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
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
            'html' => 'login page HTML',
            'ad_html' => 'advertisement page HTML',
            'ad_button_label' => 'button label',
            'ad_min_seconds' => 'wait before Connect',
            'success_url' => 'page after login',
        ]);
    }
}