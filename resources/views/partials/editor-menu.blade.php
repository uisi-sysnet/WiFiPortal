@php
  $editorActive = request()->routeIs('splash.*');
  $editorDesign = request()->route('page');
  $loginEditorUrl = $editorDesign instanceof \App\Models\SplashPage ? route('splash.login.design', $editorDesign) : route('splash.login');
  $adEditorUrl = $editorDesign instanceof \App\Models\SplashPage ? route('splash.advertisement.design', $editorDesign) : route('splash.advertisement');
@endphp
<div class="dm" data-dm>
  <button type="button" class="dm-trigger" id="editor-menu-button" aria-haspopup="menu" aria-expanded="false" aria-controls="editor-menu"
          @if($editorActive) data-active @endif>
    Editor
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
  </button>
  <div class="dm-menu" id="editor-menu" role="menu" aria-labelledby="editor-menu-button" hidden>
    <div role="group" aria-labelledby="editor-pages-label">
      <p class="dm-group" id="editor-pages-label">Captive portal</p>
      <a role="menuitem" tabindex="-1" href="{{ $loginEditorUrl }}" @if(request()->routeIs('splash.login*')) aria-current="page" @endif>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="5" y="2" width="14" height="20" rx="2"/><path d="M9 6h6M8 11h8M8 15h5"/></svg>
        Login page
      </a>
      <a role="menuitem" tabindex="-1" href="{{ $adEditorUrl }}" @if(request()->routeIs('splash.advertisement*')) aria-current="page" @endif>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9h10M7 13h6M7 17h10"/></svg>
        Advertisement page
      </a>
    </div>
  </div>
</div>

@once
<script>
var editorButton = document.getElementById('editor-menu-button');
if (editorButton) {
  var root = editorButton.closest('[data-dm]');
  var button = root.querySelector('.dm-trigger'), menu = root.querySelector('.dm-menu');
  var items = function () { return Array.prototype.slice.call(menu.querySelectorAll('[role="menuitem"]')); };
  function open(i) { menu.hidden = false; button.setAttribute('aria-expanded', 'true'); if (i !== undefined) { var list = items(); list[(i + list.length) % list.length].focus(); } }
  function close(focusButton) { if (menu.hidden) return; menu.hidden = true; button.setAttribute('aria-expanded', 'false'); if (focusButton) button.focus(); }
  button.addEventListener('click', function () { menu.hidden ? open(0) : close(false); });
  button.addEventListener('keydown', function (e) { if (e.key === 'ArrowDown') { e.preventDefault(); open(0); } if (e.key === 'ArrowUp') { e.preventDefault(); open(-1); } });
  menu.addEventListener('keydown', function (e) {
    var list = items(), i = list.indexOf(document.activeElement);
    if (e.key === 'ArrowDown') { e.preventDefault(); list[(i + 1) % list.length].focus(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); list[(i - 1 + list.length) % list.length].focus(); }
    else if (e.key === 'Home') { e.preventDefault(); list[0].focus(); }
    else if (e.key === 'End') { e.preventDefault(); list[list.length - 1].focus(); }
    else if (e.key === 'Escape') { e.preventDefault(); close(true); }
    else if (e.key === 'Tab') close(false);
  });
  document.addEventListener('click', function (e) { if (!root.contains(e.target)) close(false); });
}
</script>
@endonce
