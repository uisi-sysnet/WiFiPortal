{{--
  "Devices" dropdown in the main nav. Themed per page with:
  --dm-link, --dm-link-active, --dm-accent (trigger), --dm-bg, --dm-fg,
  --dm-muted, --dm-line, --dm-hover, --dm-shadow (menu)
--}}
@php $devicesActive = request()->routeIs('routers.*', 'aps.*', 'switches.*'); @endphp
<div class="dm" data-dm>
  <button type="button" class="dm-trigger" id="dm-button" aria-haspopup="menu" aria-expanded="false" aria-controls="dm-menu"
          @if($devicesActive) data-active @endif>
    Devices
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
  </button>

  <div class="dm-menu" id="dm-menu" role="menu" aria-labelledby="dm-button" hidden>
    <div role="group" aria-labelledby="dm-view">
      <p class="dm-group" id="dm-view">View</p>
      <a role="menuitem" tabindex="-1" href="{{ route('routers.index') }}" @if(request()->routeIs('routers.index', 'routers.show')) aria-current="page" @endif>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="2" y="13" width="20" height="8" rx="2"/><path d="M6 17h.01M10 17h.01M12 13V9M8 7a6 6 0 0 1 8 0M5 4a10 10 0 0 1 14 0"/></svg>
        Routers
      </a>
      <a role="menuitem" tabindex="-1" href="{{ route('aps.index') }}" @if(request()->routeIs('aps.index')) aria-current="page" @endif>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M2 8.5a15 15 0 0 1 20 0M5.5 12.5a10 10 0 0 1 13 0M9 16.3a5 5 0 0 1 6 0"/><circle cx="12" cy="20" r="1" fill="currentColor"/></svg>
        Access points
      </a>
      <a role="menuitem" tabindex="-1" href="{{ route('switches.index') }}" @if(request()->routeIs('switches.index')) aria-current="page" @endif>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="2" y="7" width="20" height="10" rx="2"/><path d="M6 11v2M9 11v2M12 11v2M15 11v2M18 11v2"/></svg>
        Switches
      </a>
    </div>
  </div>
</div>

@once
<style>
.dm{position:relative}
/* Light theme: muted dark gray links, dark on hover, green when active */
.dm-trigger{display:inline-flex;align-items:center;gap:6px;padding:4px 0;border:0;border-bottom:2px solid transparent;background:none;color:var(--dm-link,#5c6b66);font:inherit;cursor:pointer;white-space:nowrap;transition:color .15s,border-color .15s}
.dm-trigger:hover,.dm-trigger[aria-expanded="true"]{color:var(--dm-link-active,#0F1A1F)}
.dm-trigger[data-active]{color:var(--dm-accent,#0e670d);border-bottom-color:var(--dm-accent,#0e670d)}
.dm-trigger svg{transition:transform .15s}
.dm-trigger[aria-expanded="true"] svg{transform:rotate(180deg)}
.dm-menu{position:absolute;left:-10px;top:calc(100% + 8px);z-index:100;min-width:220px;padding:6px;border:1px solid var(--dm-line,#D0D8D4);border-radius:8px;background:var(--dm-bg,#fff);color:var(--dm-fg,#0F1A1F);box-shadow:var(--dm-shadow,0 12px 32px rgba(22,36,46,.14))}
.dm-menu[hidden]{display:none}
.dm-group{margin:0;padding:8px 10px 4px;font-size:.78rem;font-weight:600;color:var(--dm-muted,#5c6b66)}
.dm-sep{margin-top:4px;padding-top:2px;border-top:1px solid var(--dm-line,#D0D8D4)}
/* .dm prefix outranks the nav bar's own link rules (.topbar nav a, .bar nav a) */
.dm .dm-menu a,.dm .dm-menu a:hover,.dm .dm-menu a:focus,.dm .dm-menu a[aria-current="page"]{display:flex;align-items:center;gap:10px;padding:8px 10px;border:0;border-radius:5px;box-shadow:none;color:var(--dm-fg,#0F1A1F);text-decoration:none;font-size:.92rem;white-space:nowrap}
.dm .dm-menu a:hover,.dm .dm-menu a:focus{background:var(--dm-hover,#F0F3F1);outline:none}
.dm .dm-menu a[aria-current="page"]{font-weight:600;color:var(--dm-accent,#0e670d);background:var(--dm-current-bg,color-mix(in srgb,#0e670d 8%,#fff))}
.dm-menu svg{flex:none;color:var(--dm-muted,#5c6b66)}
.dm .dm-menu a[aria-current="page"] svg{color:var(--dm-accent,#0e670d)}
@media (prefers-reduced-motion:reduce){.dm-trigger svg{transition:none}}
</style>
<script>
document.querySelectorAll('[data-dm]').forEach(function (root) {
  var button = root.querySelector('.dm-trigger'), menu = root.querySelector('.dm-menu');
  var items = function () { return Array.prototype.slice.call(menu.querySelectorAll('[role="menuitem"]')); };
  function open(i) {
    menu.hidden = false;
    button.setAttribute('aria-expanded', 'true');
    if (i !== undefined) { var l = items(); l[(i + l.length) % l.length].focus(); }
  }
  function close(focusButton) {
    if (menu.hidden) return;
    menu.hidden = true;
    button.setAttribute('aria-expanded', 'false');
    if (focusButton) button.focus();
  }
  button.addEventListener('click', function () { menu.hidden ? open(0) : close(false); });
  button.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowDown') { e.preventDefault(); open(0); }
    if (e.key === 'ArrowUp') { e.preventDefault(); open(-1); }
  });
  menu.addEventListener('keydown', function (e) {
    var l = items(), i = l.indexOf(document.activeElement);
    if (e.key === 'ArrowDown') { e.preventDefault(); l[(i + 1) % l.length].focus(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); l[(i - 1 + l.length) % l.length].focus(); }
    else if (e.key === 'Home') { e.preventDefault(); l[0].focus(); }
    else if (e.key === 'End') { e.preventDefault(); l[l.length - 1].focus(); }
    else if (e.key === 'Escape') { e.preventDefault(); close(true); }
    else if (e.key === 'Tab') { close(false); }
  });
  document.addEventListener('click', function (e) { if (!root.contains(e.target)) close(false); });
});
</script>
@endonce
