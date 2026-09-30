{{-- Injected at [[connect]] in the advertisement page. Themed with the page's --accent. --}}
<div class="pwc">
<style>
.pwc{--pwc-accent:var(--accent,#0E7C66)}
.pwc form{margin:0}
.pwc-btn{display:block;width:100%;min-height:54px;border:0;border-radius:12px;background:var(--pwc-accent);color:#fff;font:inherit;font-size:1.08rem;font-weight:700;cursor:pointer}
.pwc-btn:disabled{opacity:.55;cursor:default}
.pwc-note{margin:10px 0 0;text-align:center;font-size:.85rem;color:#56666E}
.pwc-alert{margin:0 0 12px;padding:10px 12px;border-left:4px solid #B3372E;background:#F7E4E2;border-radius:6px;font-size:.92rem;color:#16242E}
.pwc :focus-visible{outline:3px solid #F2B84B;outline-offset:2px}
</style>

@if ($preview)
  <p class="pwc-alert" style="border-color:#A8660F;background:#FBF1DF">Preview. Connect does nothing here.</p>
@endif
@error('connect')<p class="pwc-alert" role="alert">{{ $message }}</p>@enderror

<form method="POST" action="{{ $action }}" id="pwc-form">
  @csrf
  <button type="submit" class="pwc-btn" id="pwc-btn" data-label="{{ $page->ad_button_label }}" @disabled($wait > 0)>
    {{ $wait > 0 ? $page->ad_button_label.' in '.$wait : $page->ad_button_label }}
  </button>
</form>
<p class="pwc-note" id="pwc-note" aria-live="polite">{{ $wait > 0 ? 'Available in a few seconds.' : '' }}</p>

<script>
(function () {
  var btn = document.getElementById('pwc-btn'), note = document.getElementById('pwc-note');
  var form = document.getElementById('pwc-form'), label = btn.dataset.label;
  var left = {{ (int) $wait }}, preview = @json($preview);

  function tick() {
    if (left <= 0) {
      btn.disabled = false;
      btn.textContent = label;
      note.textContent = '';
      return;
    }
    btn.textContent = label + ' in ' + left;
    left--;
    setTimeout(tick, 1000);
  }
  tick();

  form.addEventListener('submit', function (e) {
    if (preview) { e.preventDefault(); note.textContent = 'Preview: this would connect the user now.'; return; }
    btn.disabled = true;
    btn.textContent = 'Connecting...';
  });
})();
</script>
</div>
