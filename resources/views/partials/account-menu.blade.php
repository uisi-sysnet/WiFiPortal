{{--
  Account menu for the top-right corner. Each page themes it with:
  --acct-trigger (icon colour), --acct-trigger-line, --acct-bg, --acct-fg,
  --acct-muted, --acct-line, --acct-hover, --acct-danger, --acct-shadow
--}}
@php
  $user = auth()->user();
  $initials = collect(preg_split('/\s+/', trim((string) $user->name)))
      ->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
@endphp
<div class="acct" data-acct>
  <button type="button" class="acct-trigger" id="acct-button" aria-haspopup="menu" aria-expanded="false" aria-controls="acct-menu"
          aria-label="Account menu for {{ $user->name }}">
    <span class="acct-avatar" aria-hidden="true">
      @if ($initials !== '')
        {{ $initials }}
      @else
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
      @endif
    </span>
    <svg class="acct-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
  </button>

  <div class="acct-menu" id="acct-menu" role="menu" aria-labelledby="acct-button" hidden>
    <div class="acct-who">
      <strong>{{ $user->name }}</strong>
      <span>{{ $user->email }}</span>
    </div>
    <a role="menuitem" href="{{ route('settings') }}" tabindex="-1" @if(request()->routeIs('settings')) aria-current="page" @endif>
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>
      Settings
    </a>
    <a role="menuitem" href="{{ route('logs') }}" tabindex="-1" @if(request()->routeIs('logs')) aria-current="page" @endif>
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
      Logs
    </a>
    <form method="POST" action="{{ route('logout') }}" class="acct-signout">
      @csrf
      <button type="submit" role="menuitem" tabindex="-1">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
        Sign out
      </button>
    </form>
  </div>
</div>

@once
<style>
.acct{position:relative;flex:none}
/* Light theme: subtle gray border, dark icon/text, green hover ring */
.acct-trigger{display:flex;align-items:center;gap:6px;padding:3px 6px 3px 3px;border:1px solid var(--acct-trigger-line,#D0D8D4);border-radius:999px;background:none;color:var(--acct-trigger,#0F1A1F);cursor:pointer;font:inherit;transition:border-color .15s,background .15s}
.acct-trigger:hover,.acct-trigger[aria-expanded="true"]{border-color:var(--acct-accent,#0e670d)}
.acct-avatar{display:grid;place-items:center;width:32px;height:32px;border-radius:50%;background:var(--acct-avatar-bg,color-mix(in srgb,#0e670d 12%,#fff));color:var(--acct-avatar-fg,#0e670d);font-weight:600;font-size:.85rem;letter-spacing:.02em}
.acct-chevron{transition:transform .15s;color:var(--acct-muted,#5c6b66)}
.acct-trigger[aria-expanded="true"] .acct-chevron{transform:rotate(180deg)}
.acct-menu{position:absolute;right:0;top:calc(100% + 8px);z-index:100;min-width:230px;padding:6px;border:1px solid var(--acct-line,#D0D8D4);border-radius:8px;background:var(--acct-bg,#fff);color:var(--acct-fg,#0F1A1F);box-shadow:var(--acct-shadow,0 12px 32px rgba(22,36,46,.14))}
.acct-menu[hidden]{display:none}
.acct-who{display:grid;gap:1px;padding:8px 10px 10px;margin-bottom:4px;border-bottom:1px solid var(--acct-line,#D0D8D4)}
.acct-who strong{font-size:.92rem}
.acct-who span{font-size:.8rem;color:var(--acct-muted,#5c6b66);word-break:break-all}
.acct-menu a,.acct-menu button{display:flex;align-items:center;gap:10px;width:100%;padding:9px 10px;border:0;border-radius:5px;background:none;color:inherit;font:inherit;font-size:.92rem;text-align:left;text-decoration:none;cursor:pointer}
.acct-menu a:hover,.acct-menu button:hover,.acct-menu a:focus,.acct-menu button:focus{background:var(--acct-hover,#F0F3F1);outline:none}
.acct-menu a[aria-current="page"]{font-weight:600;color:var(--acct-accent,#0e670d);background:var(--acct-current-bg,color-mix(in srgb,#0e670d 8%,#fff))}
.acct-menu svg{flex:none;color:var(--acct-muted,#5c6b66)}
.acct-menu a[aria-current="page"] svg{color:var(--acct-accent,#0e670d)}
.acct-signout{margin:4px 0 0;padding-top:4px;border-top:1px solid var(--acct-line,#D0D8D4)}
.acct-signout button,.acct-signout button svg{color:var(--acct-danger,#B3372E)}
@media (prefers-reduced-motion:reduce){.acct-chevron{transition:none}}
</style>
<script>
document.querySelectorAll('[data-acct]').forEach(function (root) {
  var button = root.querySelector('.acct-trigger');
  var menu = root.querySelector('.acct-menu');
  var items = function () { return Array.prototype.slice.call(menu.querySelectorAll('[role="menuitem"]')); };

  function open(focusIndex) {
    menu.hidden = false;
    button.setAttribute('aria-expanded', 'true');
    if (focusIndex !== undefined) { var list = items(); list[(focusIndex + list.length) % list.length].focus(); }
  }
  function close(returnFocus) {
    if (menu.hidden) return;
    menu.hidden = true;
    button.setAttribute('aria-expanded', 'false');
    if (returnFocus) button.focus();
  }

  button.addEventListener('click', function () { menu.hidden ? open(0) : close(false); });
  button.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowDown') { e.preventDefault(); open(0); }
    if (e.key === 'ArrowUp') { e.preventDefault(); open(-1); }
  });
  menu.addEventListener('keydown', function (e) {
    var list = items(), i = list.indexOf(document.activeElement);
    if (e.key === 'ArrowDown') { e.preventDefault(); list[(i + 1) % list.length].focus(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); list[(i - 1 + list.length) % list.length].focus(); }
    else if (e.key === 'Home') { e.preventDefault(); list[0].focus(); }
    else if (e.key === 'End') { e.preventDefault(); list[list.length - 1].focus(); }
    else if (e.key === 'Escape') { e.preventDefault(); close(true); }
    else if (e.key === 'Tab') { close(false); }
  });
  document.addEventListener('click', function (e) { if (!root.contains(e.target)) close(false); });
});
</script>
@endonce
