@extends('layouts.app')

@section('title', $page->name.' | Captive portal | Public WiFi Control')
@section('body-class', 'wide')

@push('head')
<style>
body.wide main{max-width:1600px}
.studio{display:grid;grid-template-columns:minmax(0,6fr) minmax(380px,5fr);gap:28px;align-items:start}

/* Live preview (left) */
.preview{position:sticky;top:16px;display:flex;flex-direction:column;height:calc(100vh - 32px);min-height:560px;border:1px solid var(--line);border-radius:var(--radius);background:var(--panel);overflow:hidden}
.preview-bar{display:flex;flex-wrap:wrap;align-items:center;gap:8px 10px;padding:10px 12px;border-bottom:1px solid var(--line);background:#F5F7F6}
.seg{display:inline-flex;border:1px solid var(--line);border-radius:5px;overflow:hidden;background:#fff}
.seg button,.bar-btn{font:inherit;font-size:.84rem;padding:5px 10px;border:0;background:#fff;color:var(--ink);cursor:pointer}
.seg button+button{border-left:1px solid var(--line)}
.seg button[aria-pressed="true"],.bar-btn[aria-pressed="true"]{background:var(--ink);color:#fff}
.bar-btn{border:1px solid var(--line);border-radius:5px}
.preview-meta{display:flex;justify-content:space-between;gap:12px;padding:6px 12px;font-size:.8rem;color:var(--ink-2);border-bottom:1px solid var(--line)}
#preview-status.bad{color:var(--fail);font-weight:600}
.stage{flex:1;overflow:hidden;display:flex;justify-content:center;padding:18px;background:repeating-conic-gradient(#E4E9E6 0 25%,#EDF1EF 0 50%) 0 0/18px 18px}
.frame-wrap{position:relative;flex:none}
.frame-wrap iframe{position:absolute;top:0;left:0;border:0;background:#fff;transform-origin:0 0;box-shadow:0 8px 28px rgba(22,36,46,.2);transition:opacity .15s}
.stage[data-device="phone"] iframe{border-radius:22px}
.stage[data-device="tablet"] iframe{border-radius:14px}
.frame-wrap iframe.loading{opacity:.55}

/* Editor (right) */
textarea.code{font:13px/1.55 "IBM Plex Mono",ui-monospace,Menlo,Consolas,monospace;width:100%;min-height:420px;padding:12px 14px;border:1px solid #A7B4AD;border-radius:4px;background:#FBFCFB;color:var(--ink);tab-size:2;resize:vertical}
textarea.prose{font:inherit;width:100%;min-height:300px;padding:12px 14px;border:1px solid #A7B4AD;border-radius:4px;resize:vertical}
textarea.list{font:13px/1.6 "IBM Plex Mono",ui-monospace,monospace;width:100%;min-height:160px;padding:10px 12px;border:1px solid #A7B4AD;border-radius:4px;resize:vertical}
textarea[aria-invalid="true"]{border-color:var(--fail)}
.tokens{display:flex;flex-wrap:wrap;gap:6px;margin:0;padding:0;list-style:none}
.tokens button{font:500 .8rem "IBM Plex Mono",monospace;padding:3px 8px;border:1px solid var(--line);border-radius:4px;background:#F5F7F6;color:var(--ink);cursor:pointer}
.tokens button:hover{background:var(--signal-soft)}
.sticky-actions{position:sticky;bottom:0;z-index:5;display:flex;flex-wrap:wrap;gap:12px;align-items:center;padding:14px 0;background:linear-gradient(to top,var(--paper) 75%,transparent)}
#dirty{font-size:.88rem;color:var(--warn);font-weight:600}

/* Photo and video library */
.media-lib{margin-bottom:18px}
.media-up{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr) auto;gap:8px;align-items:center}
.media-up input[type=file]{font:inherit;font-size:.88rem}
.media-up input[type=text]{padding:8px 10px}
@media (max-width:640px){.media-up{grid-template-columns:1fr}}
.media-bar{height:6px;margin-top:8px;border-radius:3px;background:#E3E8E5;overflow:hidden}
.media-bar[hidden]{display:none}
.media-bar span{display:block;height:100%;width:0;background:var(--signal);transition:width .2s}
#media-msg{margin:8px 0 0;font-size:.88rem;color:var(--ink-2)}
#media-msg.bad{color:var(--fail)}
.media-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin:12px 0 0;padding:0;list-style:none}
.media-grid li{display:flex;flex-direction:column;border:1px solid var(--line);border-radius:6px;background:#fff;overflow:hidden}
.media-grid .thumb{position:relative;aspect-ratio:16/10;background:#1E2F3A center/cover no-repeat;display:grid;place-items:center;color:#B9C9C2;font-size:.8rem}
.media-grid .thumb .kind{position:absolute;left:6px;top:6px;padding:1px 6px;border-radius:3px;background:rgba(22,36,46,.8);color:#fff;font-size:.72rem;font-weight:600}
.media-grid .meta{padding:8px 9px 4px;font-size:.8rem;line-height:1.35}
.media-grid .meta b{display:block;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;font-weight:600}
.media-grid .meta span{color:var(--ink-2)}
.media-grid .meta .bad{color:var(--fail)}
.media-grid .acts{display:flex;gap:6px;padding:6px 9px 9px;margin-top:auto}
.media-grid .acts button{flex:1;font:inherit;font-size:.8rem;padding:5px 6px;border:1px solid var(--line);border-radius:4px;background:#fff;color:var(--ink);cursor:pointer}
.media-grid .acts button.del{flex:0 0 auto;color:var(--fail)}
.media-grid .acts button:disabled{opacity:.45;cursor:not-allowed}

/* Design switcher */
.designs{border:1px solid var(--line);background:var(--panel);border-radius:var(--radius);padding:14px 16px;margin-bottom:22px}
.designs-row{display:flex;flex-wrap:wrap;gap:10px 14px;align-items:center}
.designs-row > label{font-weight:600}
.designs-row select{font:inherit;min-width:220px;padding:7px 10px;border:1px solid #A7B4AD;border-radius:4px;background:#fff}
.new-design{position:relative}
.new-design summary{list-style:none}
.new-design summary::-webkit-details-marker{display:none}
.new-design form{position:absolute;z-index:20;top:calc(100% + 6px);left:0;width:300px;display:grid;gap:10px;padding:14px;background:#fff;border:1px solid var(--line);border-radius:6px;box-shadow:0 8px 24px rgba(22,36,46,.16)}
.new-design input[type=text]{padding:8px 10px}
.used-by{margin:10px 0 0;display:grid;gap:4px;font-size:.88rem;color:var(--ink-2)}
.used-by b{color:var(--ink);font-weight:600}

/* Load budget before Connect */
.budget{display:flex;align-items:center;gap:12px;margin-top:12px;padding:10px 12px;border-radius:6px;background:#F5F7F6;border:1px solid var(--line);font-size:.88rem}
.budget .meter{flex:0 0 120px;height:8px;border-radius:4px;background:#E3E8E5;overflow:hidden}
.budget .meter span{display:block;height:100%;background:var(--signal)}
.budget.warn .meter span{background:var(--warn)}
.budget.bad .meter span{background:var(--fail)}
.budget.bad b{color:var(--fail)}

@media (max-width:1050px){
  .studio{grid-template-columns:1fr}
  .preview{position:static;height:78vh;order:-1}
}
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1>Captive portal</h1>
    <p class="lede">The two pages phones see before they get internet: the login page, where people enter their details, then the advertisement page with the Connect button. Keep several designs and choose, per hotspot network, which one shows each page. Edit on the left, see it on the right as you type.</p>
  </div>
</div>

@if (session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
@error('design')<div class="alert" role="alert">{{ $message }}</div>@enderror
@if ($errors->any() && ! $errors->hasAny(['design', 'design_name']))<div class="alert" role="alert">Fix the highlighted fields. Nothing was saved.</div>@endif

@php
  $networkList = fn ($list) => $list->map(fn ($n) => $n->router->name.' / '.$n->name)->implode(', ');
@endphp
<section class="designs" aria-label="Designs">
  <div class="designs-row">
    <label for="design-pick">Design</label>
    <select id="design-pick">
      @foreach ($designs as $d)
        <option value="{{ route('splash.design', $d) }}" @selected($d->id === $page->id)>{{ $d->name }}{{ $loop->first ? ' (default)' : '' }}</option>
      @endforeach
    </select>
    <details class="new-design" @error('design_name') open @enderror>
      <summary class="btn quiet sm">New design</summary>
      <form method="POST" action="{{ route('splash.store') }}">
        @csrf
        <label for="new-design-name" style="font-weight:600">Name</label>
        <input id="new-design-name" name="design_name" type="text" maxlength="80" required placeholder="e.g. School WiFi" value="{{ old('design_name') }}">
        @error('design_name')<p class="error">{{ $message }}</p>@enderror
        <label class="check"><input type="checkbox" name="from" value="{{ $page->id }}" checked> Start from a copy of {{ $page->name }}</label>
        <button class="btn sm" type="submit">Create design</button>
      </form>
    </details>
    @unless ($page->isDefault())
      <form method="POST" action="{{ route('splash.destroy', $page) }}" onsubmit="return confirm('Delete the design {{ addslashes($page->name) }}?')">
        @csrf @method('DELETE')
        <button class="btn danger sm" type="submit">Delete design</button>
      </form>
    @endunless
  </div>
  <div class="used-by">
    <span><b>Login page</b> shown on: {{ $loginNetworks->isEmpty() ? 'no network yet' : $networkList($loginNetworks) }}</span>
    <span><b>Advertisement page</b> shown on: {{ $adNetworks->isEmpty() ? 'no network yet' : $networkList($adNetworks) }}</span>
    <span class="hint">Choose which design each network uses on its router's page.@if ($page->isDefault()) Networks that haven't chosen use this default design.@endif</span>
  </div>
</section>

<div class="studio">
  {{-- ---------- Editor ---------- --}}
  <div>
    <form method="POST" action="{{ route('splash.update', $page) }}" id="splash-form" novalidate>
      @csrf @method('PUT')

      <fieldset>
        <legend>Design</legend>
        <div class="field">
          <label for="design_name">Design name</label>
          <input id="design_name" name="name" type="text" maxlength="80" value="{{ old('name', $page->name) }}" required
                 @error('name') aria-invalid="true" @enderror>
          <p class="hint">Only admins see this, when choosing a design for a hotspot network.</p>
          @error('name')<p class="error">{{ $message }}</p>@enderror
        </div>
      </fieldset>

      <fieldset>
        <legend>Login page</legend>
        <div class="field">
          <label for="site_name">Site name</label>
          <input id="site_name" name="site_name" type="text" maxlength="80" value="{{ old('site_name', $page->site_name) }}" required
                 @error('site_name') aria-invalid="true" @enderror>
          @error('site_name')<p class="error">{{ $message }}</p>@enderror
        </div>
        <div class="field">
          <label for="html">Login page HTML</label>
          <ul class="tokens" aria-label="Insert placeholder">
            @foreach (\App\Models\SplashPage::PLACEHOLDERS as $token => $what)
              <li><button type="button" data-token="{{ $token }}" data-target="html" title="Insert: {{ $what }}">{{ $token }}</button></li>
            @endforeach
          </ul>
          <textarea id="html" name="html" class="code" spellcheck="false" autocapitalize="off" required
                    @error('html') aria-invalid="true" @enderror>{{ old('html', $page->html) }}</textarea>
          <p class="hint">A full HTML page; <span class="mono">[[form]]</span> is required. The form takes its colours from the page's <span class="mono">--accent</span> and <span class="mono">--ink</span>. Keep images inline and fonts system-only: anything hosted elsewhere is blocked until the user logs in.</p>
          @error('html')<p class="error">{{ $message }}</p>@enderror
        </div>

      </fieldset>

      <fieldset id="ad-fields">
        <legend>Advertisement page</legend>
        <p class="hint" style="margin:0 0 14px">Shown after the details are saved. Internet opens when the user taps the button.</p>
        <div class="field">
          <label for="ad_html">Advertisement page HTML</label>
          <ul class="tokens" aria-label="Insert placeholder">
            @foreach (\App\Models\SplashPage::AD_PLACEHOLDERS as $token => $what)
              <li><button type="button" data-token="{{ $token }}" data-target="ad_html" title="Insert: {{ $what }}">{{ $token }}</button></li>
            @endforeach
          </ul>
          <textarea id="ad_html" name="ad_html" class="code" spellcheck="false" autocapitalize="off" required
                    @error('ad_html') aria-invalid="true" @enderror>{{ old('ad_html', $page->ad_html) }}</textarea>
          <p class="hint">Put <span class="mono">[[connect]]</span> where the button goes. Banners must be inline images (data: URLs); nothing hosted elsewhere loads before the user is connected.</p>
          @error('ad_html')<p class="error">{{ $message }}</p>@enderror
        </div>

        {{-- Uploads go straight to the server (no page reload), so unsaved edits are kept. --}}
        <div class="field media-lib" id="media-lib">
          <label for="media-file">Photos and videos</label>
          <div class="media-up">
            <input type="file" id="media-file" form="no-form" accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm,video/x-matroska">
            <input type="text" id="media-alt" form="no-form" maxlength="160" placeholder="Short description, e.g. Barangay fiesta poster">
            <button type="button" class="btn quiet" id="media-upload">Upload</button>
          </div>
          <div class="media-bar" id="media-bar" hidden><span></span></div>
          <p id="media-msg" aria-live="polite">
            Photos are resized to {{ $mediaConfig['image_max_width'] }} px and compressed to about {{ $mediaConfig['image_target_kb'] }} KB.
            @if ($mediaConfig['ffmpeg_ready'])
              Videos are converted to 640 px and cut to {{ $mediaConfig['video_max_seconds'] }} s.
            @else
              Videos: MP4 up to {{ $mediaConfig['video_raw_max_mb'] }} MB (set FFMPEG_PATH to convert any video automatically).
            @endif
            Then use Insert to place one in the page.
          </p>
          <ul class="media-grid" id="media-grid" aria-label="Uploaded photos and videos"></ul>
          <div class="budget" id="budget" role="status"></div>
        </div>
        <div class="row">
          <div class="field">
            <label for="ad_button_label">Button label</label>
            <input id="ad_button_label" name="ad_button_label" type="text" maxlength="40" value="{{ old('ad_button_label', $page->ad_button_label) }}" required
                   @error('ad_button_label') aria-invalid="true" @enderror>
            @error('ad_button_label')<p class="error">{{ $message }}</p>@enderror
          </div>
          <div class="field">
            <label for="ad_min_seconds">Wait before the button works</label>
            <input id="ad_min_seconds" name="ad_min_seconds" type="number" min="0" max="120" value="{{ old('ad_min_seconds', $page->ad_min_seconds) }}" required
                   @error('ad_min_seconds') aria-invalid="true" @enderror>
            <p class="hint">Seconds, 0 to 120. Use it so sponsors' ads are seen before connecting.</p>
            @error('ad_min_seconds')<p class="error">{{ $message }}</p>@enderror
          </div>
        </div>
        <div class="field">
          <label for="success_url">Page after connecting <span class="hint">(optional)</span></label>
          <input id="success_url" name="success_url" type="text" class="mono" value="{{ old('success_url', $page->success_url) }}" placeholder="https://www.example.gov.ph"
                 @error('success_url') aria-invalid="true" @enderror>
          <p class="hint">Opens right after Connect. Leave empty to send people to the website they were trying to open.</p>
          @error('success_url')<p class="error">{{ $message }}</p>@enderror
        </div>
      </fieldset>

      <fieldset>
        <legend>Terms and Conditions</legend>
        <div class="field">
          <label for="terms">Text shown in the pop-up</label>
          <textarea id="terms" name="terms" class="prose" required @error('terms') aria-invalid="true" @enderror>{{ old('terms', $page->terms) }}</textarea>
          <p class="hint">Markdown: <span class="mono">## Heading</span>, <span class="mono">**bold**</span>, <span class="mono">- item</span>, blank line between paragraphs. Each registration records which version was accepted.</p>
          @error('terms')<p class="error">{{ $message }}</p>@enderror
        </div>
      </fieldset>

      <fieldset>
        <legend>Login form</legend>
        <div class="row">
          <div class="field">
            <label for="citizen_label">Resident ID label</label>
            <input id="citizen_label" name="citizen_label" type="text" maxlength="60" value="{{ old('citizen_label', $page->citizen_label) }}" required
                   @error('citizen_label') aria-invalid="true" @enderror>
            @error('citizen_label')<p class="error">{{ $message }}</p>@enderror
          </div>
          <div class="field">
            <label for="citizen_pattern">Resident ID format</label>
            <input id="citizen_pattern" name="citizen_pattern" type="text" class="mono" maxlength="200" value="{{ old('citizen_pattern', $page->citizen_pattern) }}" required
                   @error('citizen_pattern') aria-invalid="true" @enderror>
            <p class="hint">Regular expression, e.g. <span class="mono">\d{4}-\d{4}-\d{4}</span></p>
            @error('citizen_pattern')<p class="error">{{ $message }}</p>@enderror
          </div>
        </div>
        <div class="field">
          <label for="citizen_hint">Resident ID help text <span class="hint">(optional)</span></label>
          <input id="citizen_hint" name="citizen_hint" type="text" maxlength="160" value="{{ old('citizen_hint', $page->citizen_hint) }}">
        </div>
        <div class="field">
          <label for="blocked_words">Names that are never accepted</label>
          <textarea id="blocked_words" name="blocked_words" class="list" spellcheck="false">{{ old('blocked_words', $page->blocked_words) }}</textarea>
          <p class="hint">One word or phrase per line. Numbers, symbols and keyboard mashing are always rejected.</p>
        </div>
      </fieldset>

      <div class="sticky-actions">
        <button class="btn" type="submit" id="save-btn">Save design</button>
        <input type="hidden" name="preview_page" id="preview-page-field" value="login">
        <button class="btn quiet" type="submit" formaction="{{ route('splash.preview', $page) }}" formtarget="_blank">Open preview in new tab</button>
        <span id="dirty" hidden>Unsaved changes</span>
      </div>
    </form>

    <div class="actions" style="flex-wrap:wrap">
      <form method="POST" action="{{ route('splash.reset', $page) }}" onsubmit="return confirm('Replace the login page HTML with the default template? Terms and form settings stay as they are.')">
        @csrf
        <button class="btn danger" type="submit">Reset login page HTML</button>
      </form>
      <form method="POST" action="{{ route('splash.reset', $page) }}" onsubmit="return confirm('Replace the advertisement page HTML with the default template?')">
        @csrf
        <input type="hidden" name="which" value="ad">
        <button class="btn danger" type="submit">Reset advertisement page HTML</button>
      </form>
    </div>
  </div>

  {{-- ---------- Live preview ---------- --}}
  <section class="preview" aria-label="Live preview">
    <div class="preview-bar">
      <div class="seg" role="group" aria-label="Page">
        <button type="button" data-page="login" aria-pressed="true">Login page</button>
        <button type="button" data-page="ad" aria-pressed="false">Advertisement</button>
      </div>
      <div class="seg" role="group" aria-label="Screen size">
        <button type="button" data-device="phone" aria-pressed="true">Phone</button>
        <button type="button" data-device="tablet" aria-pressed="false">Tablet</button>
        <button type="button" data-device="desktop" aria-pressed="false">Desktop</button>
      </div>
      <div class="seg" role="group" aria-label="Form view">
        <button type="button" data-view="visitor" aria-pressed="true">Visitor</button>
        <button type="button" data-view="resident" aria-pressed="false">Resident</button>
        <button type="button" data-view="student" aria-pressed="false">Student</button>
      </div>
      <button type="button" class="bar-btn" id="terms-toggle" aria-pressed="false">Terms pop-up</button>
    </div>
    <div class="preview-meta">
      <span id="preview-status" aria-live="polite">Loading preview...</span>
      <span id="scale-label"></span>
    </div>
    <div class="stage" id="stage" data-device="phone">
      <div class="frame-wrap" id="frame-wrap">
        <iframe id="preview-frame" title="Splash page preview" sandbox="allow-scripts"></iframe>
      </div>
    </div>
  </section>
</div>

<script>
(function () {
  const $ = (id) => document.getElementById(id);
  const form = $('splash-form'), frame = $('preview-frame'), stage = $('stage'), wrap = $('frame-wrap');
  const statusEl = $('preview-status'), scaleEl = $('scale-label'), termsBtn = $('terms-toggle'), dirtyEl = $('dirty');
  const previewUrl = @json(route('splash.preview', $page));

  // Switching design leaves the page (the unsaved-changes warning still applies).
  $('design-pick').addEventListener('change', (e) => { location.href = e.target.value; });
  const DEVICES = { phone: 390, tablet: 768, desktop: 1280 };
  const state = { device: 'phone', category: 'visitor', terms: false, scroll: 0, page: 'login' };
  let timer = null, seq = 0, controller = null, dirty = false;

  /* Scale the device frame to fit the panel */
  function fit() {
    const width = DEVICES[state.device];
    const availW = stage.clientWidth - 36, availH = stage.clientHeight - 36;
    const scale = Math.min(1, availW / width);
    frame.style.width = width + 'px';
    frame.style.height = (availH / scale) + 'px';
    frame.style.transform = 'scale(' + scale + ')';
    wrap.style.width = (width * scale) + 'px';
    wrap.style.height = availH + 'px';
    scaleEl.textContent = width + ' px wide' + (scale < 1 ? ', shown at ' + Math.round(scale * 100) + '%' : '');
  }
  new ResizeObserver(fit).observe(stage);

  /* Render the unsaved form through the real portal renderer */
  async function refresh() {
    const mine = ++seq;
    if (controller) controller.abort();
    controller = new AbortController();
    statusEl.className = '';
    statusEl.textContent = 'Updating...';
    frame.classList.add('loading');
    try {
      const res = await fetch(previewUrl, {
        method: 'POST',
        body: new FormData(form), // includes preview_page
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        signal: controller.signal,
      });
      if (res.status === 422) {
        const data = await res.json();
        const first = data.errors ? Object.values(data.errors)[0][0] : data.message;
        throw new Error(first);
      }
      if (!res.ok) throw new Error('the server answered ' + res.status);
      const html = await res.text();
      if (mine !== seq) return;
      frame.srcdoc = html;
      statusEl.textContent = dirty ? 'Showing unsaved changes' : 'Showing the saved page';
    } catch (err) {
      if (err.name === 'AbortError') return;
      frame.classList.remove('loading');
      statusEl.className = 'bad';
      statusEl.textContent = 'Preview paused: ' + err.message;
    }
  }

  /* Keep view, Terms pop-up and scroll position across refreshes */
  function pushState() {
    if (frame.contentWindow) frame.contentWindow.postMessage({ pw: 'state', ...state }, '*');
  }
  frame.addEventListener('load', () => { frame.classList.remove('loading'); pushState(); });
  window.addEventListener('message', (e) => {
    if (e.source !== frame.contentWindow || !e.data) return;
    if (e.data.pw === 'scroll') state.scroll = e.data.y;
    if (e.data.pw === 'terms') setTerms(e.data.open, false);
    if (e.data.pw === 'category') setView(e.data.value, false);
  });

  function press(group, attr, value) {
    document.querySelectorAll(group + ' button').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset[attr] === value)));
  }
  function setView(view, push = true) {
    state.category = view;
    press('[aria-label="Form view"]', 'view', view);
    if (push) pushState();
  }
  function setTerms(open, push = true) {
    state.terms = open;
    termsBtn.setAttribute('aria-pressed', String(open));
    if (push) pushState();
  }

  document.querySelectorAll('[data-device]').forEach((b) => b.addEventListener('click', () => {
    state.device = b.dataset.device;
    stage.dataset.device = state.device;
    press('[aria-label="Screen size"]', 'device', state.device);
    fit();
  }));
  document.querySelectorAll('[data-view]').forEach((b) => b.addEventListener('click', () => setView(b.dataset.view)));

  /* Which page the preview shows. Visitor/Resident and Terms only apply to the login page. */
  function setPage(page) {
    if (state.page === page) return;
    state.page = page;
    state.scroll = 0;
    $('preview-page-field').value = page;
    press('[aria-label="Page"]', 'page', page);
    document.querySelector('[aria-label="Form view"]').hidden = page !== 'login';
    termsBtn.hidden = page !== 'login';
    refresh();
  }
  document.querySelectorAll('[data-page]').forEach((b) => b.addEventListener('click', () => setPage(b.dataset.page)));
  // Editing a field switches the preview to the page it belongs to.
  form.addEventListener('focusin', (e) => {
    if (!e.target.matches('input, textarea, select')) return;
    setPage(e.target.closest('#ad-fields') ? 'ad' : 'login');
  });
  termsBtn.addEventListener('click', () => setTerms(!state.terms));

  /* Placeholder buttons insert at the cursor in their HTML box */
  document.querySelectorAll('[data-token]').forEach((b) => b.addEventListener('click', () => {
    const box = $(b.dataset.target);
    box.setRangeText(b.dataset.token, box.selectionStart, box.selectionEnd, 'end');
    box.focus();
    box.dispatchEvent(new Event('input', { bubbles: true }));
  }));

  /* Tab inserts two spaces in the HTML boxes instead of leaving them */
  document.querySelectorAll('textarea.code').forEach((box) => box.addEventListener('keydown', (e) => {
    if (e.key === 'Tab' && !e.shiftKey && !e.ctrlKey && !e.metaKey) {
      e.preventDefault();
      box.setRangeText('  ', box.selectionStart, box.selectionEnd, 'end');
      box.dispatchEvent(new Event('input', { bubbles: true }));
    }
  }));

  /* ---------- Photos and videos ---------- */
  const media = new Map(@json($media).map((m) => [m.id, m]));
  const mediaCfg = @json($mediaConfig);
  const grid = $('media-grid'), mediaMsg = $('media-msg'), bar = $('media-bar'), budgetEl = $('budget');
  const adBox = $('ad_html');
  const token = document.querySelector('meta[name=csrf-token]')?.content || form.querySelector('input[name=_token]').value;
  const mediaBase = @json(url('/splash/media'));
  const kb = (b) => b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
  const secs = (b) => b * 8 / (mediaCfg.budget_kbps * 1000);
  const fmtS = (s) => s < 10 ? s.toFixed(1) + ' s' : Math.round(s) + ' s';
  const usedIds = () => [...adBox.value.matchAll(/\[\[media:(\d+)\]\]/g)].map((m) => +m[1]);

  function card(m) {
    const li = document.createElement('li');
    const used = usedIds().includes(m.id);
    const info = m.status === 'processing' ? 'Converting...'
      : m.status === 'failed' ? '<span class="bad">' + esc(m.error || 'Failed') + '</span>'
      : m.kind === 'image'
        ? kb(m.bytes) + ', ' + fmtS(secs(m.bytes)) + ' at ' + (mediaCfg.budget_kbps / 1000) + ' Mbps'
        : kb(m.bytes) + (m.duration ? ', ' + Math.round(m.duration) + ' s' : '') + '. Loads only when played';
    li.innerHTML =
      '<div class="thumb"' + (m.thumb ? ' style="background-image:url(\'' + m.thumb + '\')"' : '') + '>'
      + '<span class="kind">' + (m.kind === 'image' ? 'Photo' : 'Video') + (used ? ', on page' : '') + '</span>'
      + (m.thumb ? '' : (m.kind === 'video' ? 'Video' : '')) + '</div>'
      + '<div class="meta"><b title="' + esc(m.name) + '">' + esc(m.alt || m.name) + '</b><span>' + info + '</span></div>'
      + '<div class="acts"><button type="button" class="ins"' + (m.status === 'ready' ? '' : ' disabled') + '>Insert</button>'
      + '<button type="button" class="del" aria-label="Delete ' + esc(m.alt || m.name) + '">Delete</button></div>';
    li.querySelector('.ins').addEventListener('click', () => {
      setPage('ad');
      adBox.focus();
      adBox.setRangeText('\n' + m.token + '\n', adBox.selectionStart, adBox.selectionEnd, 'end');
      adBox.dispatchEvent(new Event('input', { bubbles: true }));
    });
    li.querySelector('.del').addEventListener('click', () => removeMedia(m));
    return li;
  }
  function esc(v) { return String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

  function drawMedia() {
    grid.replaceChildren(...[...media.values()].map(card));
    drawBudget();
  }

  /* What a phone downloads on the advertisement page before tapping anything */
  function drawBudget() {
    const html = new Blob([adBox.value]).size;
    const items = [...new Set(usedIds())].map((id) => media.get(id)).filter(Boolean);
    const total = html + items.reduce((sum, m) => sum + (m.upfront || 0), 0);
    const s = secs(total);
    const videos = items.filter((m) => m.kind === 'video').length;
    budgetEl.className = 'budget' + (s > 6 ? ' bad' : s > 3 ? ' warn' : '');
    budgetEl.innerHTML = '<div class="meter" aria-hidden="true"><span style="width:' + Math.min(100, s / 6 * 100) + '%"></span></div>'
      + '<span>Loads <b>' + kb(total) + '</b> before Connect, about <b>' + fmtS(s) + '</b> at ' + (mediaCfg.budget_kbps / 1000) + ' Mbps.'
      + (videos ? ' Videos download only when played.' : '')
      + (s > 6 ? ' Too heavy: remove a photo.' : s > 3 ? ' Keep it under 3 s if you can.' : '') + '</span>';
  }
  adBox.addEventListener('input', drawMedia); // refreshes the "on page" tags and the meter

  function upload() {
    const file = $('media-file').files[0];
    if (!file) { mediaMsg.className = 'bad'; mediaMsg.textContent = 'Choose a photo or video first.'; return; }
    const fd = new FormData();
    fd.append('file', file);
    fd.append('alt', $('media-alt').value.trim());
    fd.append('_token', token);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', mediaBase);
    xhr.setRequestHeader('Accept', 'application/json');
    bar.hidden = false;
    bar.firstElementChild.style.width = '0%';
    mediaMsg.className = '';
    mediaMsg.textContent = 'Uploading ' + file.name + '...';
    $('media-upload').disabled = true;
    xhr.upload.onprogress = (e) => {
      if (!e.lengthComputable) return;
      const p = Math.round(e.loaded / e.total * 100);
      bar.firstElementChild.style.width = p + '%';
      mediaMsg.textContent = p < 100 ? 'Uploading ' + p + '%...' : 'Optimizing...';
    };
    xhr.onload = () => {
      $('media-upload').disabled = false;
      bar.hidden = true;
      let data = {};
      try { data = JSON.parse(xhr.responseText); } catch (e) {}
      if (xhr.status !== 201) {
        mediaMsg.className = 'bad';
        mediaMsg.textContent = data.errors ? Object.values(data.errors)[0][0] : (data.message || 'Upload failed (' + xhr.status + ').');
        return;
      }
      media.set(data.id, data);
      const saved = data.kind === 'image' ? ' Now ' + kb(data.bytes) + '.' : '';
      mediaMsg.textContent = data.status === 'processing'
        ? 'Uploaded. Converting the video; you can keep editing.'
        : 'Uploaded.' + saved + ' Use Insert to place it in the page.';
      $('media-file').value = '';
      $('media-alt').value = '';
      drawMedia();
    };
    xhr.onerror = () => {
      $('media-upload').disabled = false;
      bar.hidden = true;
      mediaMsg.className = 'bad';
      mediaMsg.textContent = 'Upload failed. Check the connection and try again.';
    };
    xhr.send(fd);
  }
  $('media-upload').addEventListener('click', upload);

  async function removeMedia(m) {
    const onPage = usedIds().includes(m.id);
    if (!confirm('Delete ' + (m.alt || m.name) + '?' + (onPage ? ' It is on the page: its placeholder will show nothing until you remove it.' : ''))) return;
    const res = await fetch(mediaBase + '/' + m.id, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' } });
    if (res.ok) { media.delete(m.id); drawMedia(); refresh(); }
  }

  // Videos being converted: check every few seconds until they are ready or failed.
  setInterval(async () => {
    for (const m of media.values()) {
      if (m.status !== 'processing') continue;
      const res = await fetch(mediaBase + '/' + m.id, { headers: { Accept: 'application/json' } });
      if (!res.ok) continue;
      const fresh = await res.json();
      if (fresh.status !== 'processing') {
        media.set(fresh.id, fresh);
        drawMedia();
        refresh();
        mediaMsg.className = fresh.status === 'failed' ? 'bad' : '';
        mediaMsg.textContent = fresh.status === 'failed' ? 'Video conversion failed: ' + fresh.error : 'Video ready: ' + kb(fresh.bytes) + '. Use Insert to place it.';
      }
    }
  }, 4000);

  drawMedia();

  form.addEventListener('input', (e) => {
    if (e.target.closest('#media-lib')) return; // library inputs aren't part of the saved page
    dirty = true;
    dirtyEl.hidden = false;
    clearTimeout(timer);
    timer = setTimeout(refresh, 450);
  });

  /* Ctrl/Cmd+S saves; warn before leaving with unsaved changes */
  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
      e.preventDefault();
      form.requestSubmit($('save-btn'));
    }
  });
  form.addEventListener('submit', (e) => { if (!e.submitter || e.submitter.id === 'save-btn') dirty = false; });
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  fit();
  refresh();
})();
</script>
@endsection
