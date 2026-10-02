{{--
  Main menu as a left sidebar, on every signed-in page (layouts/app and the dashboard).
  Collapsible to an icon strip (remembered in this browser); on phones it slides in from
  the menu button. Shows only what the person's role can open.
  $theme: 'light' (default, green) or 'dark' (the dashboard).
--}}
@php
  $me = auth()->user();
  $isAdmin = (bool) $me?->isAdmin();
  $isUser = (bool) $me?->hasRole('user');
  $editorDesign = request()->route('page');
  $loginEditorUrl = $editorDesign instanceof \App\Models\SplashPage ? route('splash.login.design', $editorDesign) : route('splash.login');
  $adEditorUrl = $editorDesign instanceof \App\Models\SplashPage ? route('splash.advertisement.design', $editorDesign) : route('splash.advertisement');
  $devicesOpen = request()->routeIs('routers.*', 'aps.*', 'switches.*', 'devices.*', 'networks.*');
  $portalOpen = request()->routeIs('splash.*');
  $icon = fn ($d) => '<svg class="sb-ico" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$d.'</svg>';
  $icons = [
      'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
      'devices' => '<rect x="2" y="14" width="20" height="7" rx="2"/><path d="M6 17.5h.01M10 17.5h.01M12 14V9M8.5 6.5a5 5 0 0 1 7 0M6 4a8.5 8.5 0 0 1 12 0"/>',
      'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
      'radius' => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4M12 15v2"/>',
      'portal' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>',
      'logs' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
      'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.8 1.2V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-2.8-1.2l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0-1.2-2.8H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.2-2.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 2.8-1.2V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 2.8 1.2l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0 1.2 2.8H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
  ];
  $cur = fn (...$names) => request()->routeIs(...$names) ? 'aria-current=page' : '';
@endphp

{{-- Before anything is drawn: apply the remembered collapsed state, so the page doesn't jump --}}
<script>
  document.documentElement.classList.add('has-sb');
  try { if (localStorage.getItem('sb.collapsed') === '1') document.documentElement.classList.add('sb-collapsed'); } catch (e) {}
</script>

<style>
:root{--sb-full:268px;--sb-rail:68px;--sb-w:var(--sb-full)}
html.sb-collapsed{--sb-w:var(--sb-rail)}
html.has-sb body{padding-left:var(--sb-w);transition:padding-left .18s ease}
.sb{position:fixed;top:0;bottom:0;left:0;z-index:1200;width:var(--sb-w);display:flex;flex-direction:column;overflow:hidden;
  background:linear-gradient(180deg,#0a4d0a 0%,#063506 100%);color:#fff;border-right:1px solid #052b05;box-shadow:4px 0 18px -10px rgba(0,0,0,.45);
  transition:width .18s ease,transform .2s ease;font-family:"Public Sans",system-ui,-apple-system,"Segoe UI",sans-serif}
.sb.sb-dark{background:linear-gradient(180deg,#071722 0%,#030A12 100%);border-right-color:#14303F}
.sb a,.sb button{color:inherit;font:inherit}
.sb-brand{display:flex;align-items:center;gap:10px;padding:16px 10px 14px 12px;text-decoration:none;color:#fff;min-height:72px;border-bottom:1px solid rgba(242,184,75,.22)}
.sb-logo{flex:none;display:grid;place-items:center;width:38px;height:38px;border-radius:50%;background:rgba(242,184,75,.18);border:2px solid #F2B84B;box-shadow:0 0 12px rgba(242,184,75,.4)}
.sb-dark .sb-logo{background:rgba(44,213,255,.12);border-color:#2CD5FF;box-shadow:0 0 12px rgba(44,213,255,.45)}
.sb-name{display:flex;flex-direction:column;line-height:1.15;white-space:nowrap;min-width:0}
.sb-name b{font-size:14px;font-weight:700}
.sb-name i{font-style:normal;display:inline-block;margin-left:4px;padding:1px 5px;border-radius:999px;background:#F2B84B;color:#063506;font-size:8.5px;font-weight:700;letter-spacing:.06em;vertical-align:2px}
.sb-name small{font-size:9px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:rgba(242,184,75,.85);margin-top:3px}
.sb-dark .sb-name small{color:rgba(44,213,255,.8)}
.sb-nav{flex:1;overflow-y:auto;overflow-x:hidden;padding:10px 10px 12px;scrollbar-width:thin}
.sb-sec{margin:14px 10px 6px;font-size:10px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:rgba(255,255,255,.5);white-space:nowrap}
.sb-sec:first-child{margin-top:4px}
.sb-item{position:relative;display:flex;align-items:center;gap:12px;width:100%;min-height:42px;padding:9px 12px;margin:1px 0;border:0;border-radius:8px;background:none;
  color:rgba(255,255,255,.88);text-decoration:none;font-size:14px;font-weight:500;text-align:left;cursor:pointer;white-space:nowrap}
.sb-item:hover{background:rgba(255,255,255,.08);color:#fff}
.sb-item[aria-current=page]{background:rgba(242,184,75,.16);color:#F2B84B;font-weight:600}
.sb-item[aria-current=page]::before{content:"";position:absolute;left:-10px;top:8px;bottom:8px;width:3px;border-radius:0 3px 3px 0;background:#F2B84B;box-shadow:0 0 8px rgba(242,184,75,.8)}
.sb-dark .sb-item[aria-current=page]{background:rgba(44,213,255,.14);color:#2CD5FF}
.sb-dark .sb-item[aria-current=page]::before{background:#2CD5FF;box-shadow:0 0 8px rgba(44,213,255,.8)}
.sb-ico{flex:none}
.sb-label{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis}
.sb-caret{flex:none;transition:transform .15s ease;opacity:.7}
.sb-group[aria-expanded=true] .sb-caret{transform:rotate(90deg)}
.sb-sub{margin:0 0 4px;padding:0 0 0 22px}
.sb-sub[hidden]{display:none}
.sb-sub .sb-item{min-height:36px;padding:7px 12px;font-size:13.5px;color:rgba(255,255,255,.78)}
.sb-sub .sb-item::after{content:"";position:absolute;left:-6px;top:50%;width:8px;height:1px;background:rgba(255,255,255,.25)}
.sb-foot{padding:10px;border-top:1px solid rgba(255,255,255,.1)}
.sb-toggle .sb-ico{transition:transform .18s ease}
html.sb-collapsed .sb-toggle .sb-ico{transform:rotate(180deg)}
/* Collapsed: icons only; the name shows on hover */
html.sb-collapsed .sb-name,html.sb-collapsed .sb-label,html.sb-collapsed .sb-caret,html.sb-collapsed .sb-sec,html.sb-collapsed .sb-sub{display:none}
html.sb-collapsed .sb-brand{justify-content:center;padding-left:0;padding-right:0}
html.sb-collapsed .sb-item{justify-content:center;padding-left:0;padding-right:0}
html.sb-collapsed .sb-nav{padding-left:8px;padding-right:8px}
html.sb-collapsed .sb-item:hover::after,html.sb-collapsed .sb-item:focus-visible::after{content:attr(data-tip);position:fixed;left:calc(var(--sb-rail) + 6px);
  margin-top:0;padding:5px 10px;border-radius:6px;background:#0F1A1F;color:#fff;font-size:12.5px;font-weight:600;white-space:nowrap;box-shadow:0 6px 16px rgba(0,0,0,.3);pointer-events:none;z-index:1300}
.sb-burger,.sb-shade{display:none}
/* Phones and small tablets: hidden until the menu button is pressed */
@media (max-width:900px){
  html.has-sb{--sb-w:0px}
  html.has-sb body{padding-left:0}
  .sb{width:var(--sb-full);transform:translateX(-100%)}
  html.sb-open .sb{transform:none}
  html.sb-collapsed .sb-name,html.sb-collapsed .sb-label,html.sb-collapsed .sb-caret,html.sb-collapsed .sb-sec{display:revert}
  html.sb-collapsed .sb-item{justify-content:flex-start;padding:9px 12px}
  .sb-toggle{display:none}
  .sb-burger{display:grid;place-items:center;position:fixed;top:12px;left:12px;z-index:1250;width:42px;height:42px;border-radius:10px;border:1px solid rgba(242,184,75,.5);
    background:#063506;color:#F2B84B;cursor:pointer;box-shadow:0 4px 14px rgba(0,0,0,.3)}
  .sb-burger.sb-dark{background:#071722;border-color:rgba(44,213,255,.5);color:#2CD5FF}
  html.sb-open .sb-shade{display:block;position:fixed;inset:0;z-index:1190;background:rgba(5,15,20,.5)}
}
@media (prefers-reduced-motion:reduce){.sb,html.has-sb body,.sb-caret,.sb-toggle .sb-ico{transition:none}}
</style>

<button type="button" class="sb-burger {{ ($theme ?? 'light') === 'dark' ? 'sb-dark' : '' }}" id="sb-burger" aria-controls="sb" aria-expanded="false" aria-label="Open the menu">
  <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
</button>
<div class="sb-shade" id="sb-shade" aria-hidden="true"></div>

<aside class="sb {{ ($theme ?? 'light') === 'dark' ? 'sb-dark' : '' }}" id="sb" aria-label="Main menu">
  <a class="sb-brand" href="{{ route('dashboard') }}" title="Public WiFi Control">
    <span class="sb-logo">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="{{ ($theme ?? 'light') === 'dark' ? '#2CD5FF' : '#F2B84B' }}" stroke-width="2.4" stroke-linecap="round" aria-hidden="true">
        <path d="M2 8.5a15 15 0 0 1 20 0"/><path d="M5.5 12.5a10 10 0 0 1 13 0"/><path d="M9 16.3a5 5 0 0 1 6 0"/><circle cx="12" cy="20" r="1.3" fill="currentColor" stroke="none"/>
      </svg>
    </span>
    <span class="sb-name">
      <span><b>Public WiFi Control</b><i>BETA v0.1</i></span>
      <small>Uplink Integrated Solutions Inc.</small>
    </span>
  </a>

  <nav class="sb-nav" aria-label="Main">
    <p class="sb-sec">Monitoring</p>
    <a class="sb-item" href="{{ route('dashboard') }}" data-tip="Dashboard" {{ $cur('dashboard') }}>{!! $icon($icons['dashboard']) !!}<span class="sb-label">Dashboard</span></a>

    @if ($isAdmin)
      <button type="button" class="sb-item sb-group" data-tip="Devices" aria-expanded="{{ $devicesOpen ? 'true' : 'false' }}" aria-controls="sb-devices">
        {!! $icon($icons['devices']) !!}<span class="sb-label">Devices</span>
        <svg class="sb-caret" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
      </button>
      <div class="sb-sub" id="sb-devices" @unless($devicesOpen) hidden @endunless>
        <a class="sb-item" href="{{ route('routers.index') }}" {{ $cur('routers.*', 'networks.*') }}><span class="sb-label">Routers</span></a>
        <a class="sb-item" href="{{ route('aps.index') }}" {{ request()->routeIs('aps.*') || (request()->routeIs('devices.*') && request()->route('device')?->type === 'ap') ? 'aria-current=page' : '' }}><span class="sb-label">Access points</span></a>
        <a class="sb-item" href="{{ route('switches.index') }}" {{ request()->routeIs('switches.*') || (request()->routeIs('devices.*') && request()->route('device')?->type === 'switch') ? 'aria-current=page' : '' }}><span class="sb-label">Switches</span></a>
      </div>
      <a class="sb-item" href="{{ route('radius') }}" data-tip="RADIUS" {{ $cur('radius') }}>{!! $icon($icons['radius']) !!}<span class="sb-label">RADIUS</span></a>
    @endif

    @if ($isUser)
      <p class="sb-sec">Hotspot users</p>
      <a class="sb-item" href="{{ route('users.index') }}" data-tip="Users" {{ $cur('users.*') }}>{!! $icon($icons['users']) !!}<span class="sb-label">Users</span></a>
    @endif

    @if ($isAdmin)
      <button type="button" class="sb-item sb-group" data-tip="Captive portal" aria-expanded="{{ $portalOpen ? 'true' : 'false' }}" aria-controls="sb-portal">
        {!! $icon($icons['portal']) !!}<span class="sb-label">Captive portal</span>
        <svg class="sb-caret" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
      </button>
      <div class="sb-sub" id="sb-portal" @unless($portalOpen) hidden @endunless>
        <a class="sb-item" href="{{ $loginEditorUrl }}" {{ $cur('splash.login', 'splash.login.design') }}><span class="sb-label">Login page</span></a>
        <a class="sb-item" href="{{ $adEditorUrl }}" {{ $cur('splash.advertisement', 'splash.advertisement.design') }}><span class="sb-label">Advertisement page</span></a>
      </div>

      <p class="sb-sec">System</p>
      <a class="sb-item" href="{{ route('logs') }}" data-tip="Logs" {{ $cur('logs', 'logs.*') }}>{!! $icon($icons['logs']) !!}<span class="sb-label">Logs</span></a>
      <a class="sb-item" href="{{ route('settings') }}" data-tip="Settings" {{ $cur('settings') }}>{!! $icon($icons['settings']) !!}<span class="sb-label">Settings</span></a>
    @endif
  </nav>

  <div class="sb-foot">
    <button type="button" class="sb-item sb-toggle" id="sb-toggle" data-tip="Expand menu" aria-pressed="false">
      <svg class="sb-ico" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m11 17-5-5 5-5M18 17l-5-5 5-5"/></svg>
      <span class="sb-label">Collapse menu</span>
    </button>
  </div>
</aside>

<script>
(function () {
  var root = document.documentElement, toggle = document.getElementById('sb-toggle');
  var burger = document.getElementById('sb-burger'), shade = document.getElementById('sb-shade');
  var narrow = window.matchMedia('(max-width: 900px)');

  function sync() {
    var collapsed = root.classList.contains('sb-collapsed');
    toggle.setAttribute('aria-pressed', String(collapsed));
    toggle.setAttribute('aria-label', collapsed ? 'Expand menu' : 'Collapse menu');
    toggle.dataset.tip = collapsed ? 'Expand menu' : 'Collapse menu';
  }
  function setCollapsed(on) {
    root.classList.toggle('sb-collapsed', on);
    try { localStorage.setItem('sb.collapsed', on ? '1' : '0'); } catch (e) {}
    sync();
    window.dispatchEvent(new Event('resize')); // maps and charts re-measure
  }
  toggle.addEventListener('click', function () { setCollapsed(!root.classList.contains('sb-collapsed')); });

  // Devices / Captive portal: open or close the list; in the icon strip, open the menu first
  document.querySelectorAll('.sb-group').forEach(function (g) {
    g.addEventListener('click', function () {
      var list = document.getElementById(g.getAttribute('aria-controls'));
      if (root.classList.contains('sb-collapsed') && !narrow.matches) {
        setCollapsed(false);
        list.hidden = false;
        g.setAttribute('aria-expanded', 'true');
        return;
      }
      list.hidden = !list.hidden;
      g.setAttribute('aria-expanded', String(!list.hidden));
    });
  });

  // Phones: the menu button slides it in; tapping outside or Esc closes it
  function setOpen(on) {
    root.classList.toggle('sb-open', on);
    burger.setAttribute('aria-expanded', String(on));
    if (on) document.querySelector('#sb .sb-item').focus();
  }
  burger.addEventListener('click', function () { setOpen(!root.classList.contains('sb-open')); });
  shade.addEventListener('click', function () { setOpen(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && root.classList.contains('sb-open')) { setOpen(false); burger.focus(); } });

  sync();
})();
</script>
