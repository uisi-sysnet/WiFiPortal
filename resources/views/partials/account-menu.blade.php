{{--
  Account menu for the top-right corner.
  Themed to match the "Devices" dropdown design.
--}}
@php
  $user = auth()->user();
  $initials = collect(preg_split('/\s+/', trim((string) $user->name)))
      ->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
@endphp
<div class="acct" data-acct>
  {{-- Trigger button --}}
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

  {{-- Dropdown menu --}}
  <div class="acct-menu" id="acct-menu" role="menu" aria-labelledby="acct-button" hidden>
    {{-- Section label --}}
    <div class="acct-section-label">{{ $user->name }} · {{ $user->roleLabel() }}</div>

    {{-- Menu items --}}
    @if ($user->isAdmin())
    <a role="menuitem" href="{{ route('settings') }}" tabindex="-1" @if(request()->routeIs('settings')) aria-current="page" @endif>
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>
      Settings
    </a>
    <a role="menuitem" href="{{ route('logs') }}" tabindex="-1" @if(request()->routeIs('logs')) aria-current="page" @endif>
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
      Logs
    </a>
    @endif

    {{-- Sign out --}}
    <div class="acct-signout">
      <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" role="menuitem" tabindex="-1">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
          Sign out
        </button>
      </form>
    </div>
  </div>
</div>

@once
<style>
/* ===== Container ===== */
.acct{position:relative;flex:none}

/* ===== Trigger (on the green topbar) ===== */
.acct-trigger{
  display:flex;align-items:center;gap:6px;
  padding:3px 8px 3px 3px;
  border:1px solid rgba(255,255,255,.30);
  border-radius:999px;
  background:none;
  color:#fff;
  cursor:pointer;font:inherit;
  transition:border-color .15s,background .15s;
}
.acct-trigger:hover,
.acct-trigger[aria-expanded="true"]{
  border-color:#F2B84B;
  background:rgba(255,255,255,.10);
}

/* Avatar */
.acct-avatar{
  display:grid;place-items:center;
  width:30px;height:30px;border-radius:50%;
  background:rgba(255,255,255,.18);
  color:#fff;
  font-weight:600;font-size:.8rem;letter-spacing:.02em;
}

.acct-chevron{transition:transform .15s;color:rgba(255,255,255,.75)}
.acct-trigger[aria-expanded="true"] .acct-chevron{transform:rotate(180deg)}

/* ===== Dropdown menu ===== */
.acct-menu{
  position:absolute;right:0;top:calc(100% + 10px);z-index:100;
  min-width:240px;
  padding:6px;
  border:1px solid #D0D8D4;
  border-radius:10px;
  background:#fff;
  color:#0F1A1F;
  box-shadow:0 16px 40px -12px rgba(10,20,26,.28), 0 4px 12px -4px rgba(10,20,26,.12);
  animation:acct-in .14s ease-out;
}
.acct-menu[hidden]{display:none}
@keyframes acct-in{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:translateY(0)}}

/* Section label */
.acct-section-label{
  padding:8px 10px 6px;
  font-size:.68rem;
  font-weight:700;
  text-transform:uppercase;
  letter-spacing:.14em;
  color:#5c6b66;
}

/* Menu items — matching the Devices dropdown style */
.acct-menu a,
.acct-menu button{
  display:flex;align-items:center;gap:10px;
  width:100%;
  padding:10px 12px;
  border:0;
  border-radius:7px;
  background:none;
  color:#16242E;
  font:inherit;
  font-size:.9rem;
  font-weight:500;
  text-align:left;
  text-decoration:none;
  cursor:pointer;
  transition:background .12s,color .12s;
}
.acct-menu a:hover,
.acct-menu button:hover,
.acct-menu a:focus,
.acct-menu button:focus{
  background:#EEF1EF;
  outline:none;
}
.acct-menu a[aria-current="page"]{
  font-weight:700;
  color:#0e670d;
  background:#E6F0E6;
}

.acct-menu svg{
  flex:none;
  color:#5c6b66;
  transition:color .12s;
}
.acct-menu a:hover svg,
.acct-menu button:hover svg{color:#16242E}
.acct-menu a[aria-current="page"] svg{color:#0e670d}

/* Sign out section */
.acct-signout{
  margin:6px 0 0;
  padding-top:6px;
  border-top:1px solid #E5EAE7;
}
.acct-signout button,
.acct-signout button svg{color:#B3372E}
.acct-signout button:hover{background:#F7E4E2}
.acct-signout button:hover svg{color:#B3372E}

@media (prefers-reduced-motion:reduce){
  .acct-chevron{transition:none}
  .acct-menu{animation:none}
}
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