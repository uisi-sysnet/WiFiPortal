{{-- Injected into the admin's splash HTML at [[form]]. Theme it from the page with --accent and --ink. --}}
<div class="pw">
<style>
.pw{--pw-accent:var(--accent,#0E7C66);--pw-ink:var(--ink,#16242E);--pw-muted:#56666E;--pw-line:#C9D1CD;--pw-bad:#B3372E;color:var(--pw-ink)}
.pw *{box-sizing:border-box}
.pw form{display:grid;gap:16px;margin:0}
.pw-field{display:grid;gap:6px}
.pw-field[hidden],.pw-group[hidden]{display:none}
.pw-group{display:grid;gap:16px}
.pw label{font-weight:600;font-size:.95rem}
.pw-input{font:inherit;font-size:16px;width:100%;min-height:48px;padding:11px 14px;border:1px solid #A7B4AD;border-radius:10px;background:#fff;color:var(--pw-ink)}
.pw-input[aria-invalid="true"]{border-color:var(--pw-bad);background:#FDF6F5}
.pw-hint{margin:0;font-size:.85rem;color:var(--pw-muted)}
.pw-err{margin:0;font-size:.88rem;color:var(--pw-bad)}
.pw-alert{margin:0 0 4px;padding:10px 12px;border-left:4px solid var(--pw-bad);background:#F7E4E2;border-radius:6px;font-size:.92rem}
.pw-resident{display:flex;align-items:center;gap:12px;padding:12px 14px;border:1px solid var(--pw-line);border-radius:10px;cursor:pointer;font-weight:600}
.pw-resident input{width:22px;height:22px;margin:0;accent-color:var(--pw-accent);flex:none}
.pw-resident small{display:block;font-weight:400;color:var(--pw-muted);font-size:.84rem}
.pw-btn{font:inherit;font-weight:700;font-size:1rem;width:100%;min-height:50px;border:0;border-radius:10px;background:var(--pw-accent);color:#fff;cursor:pointer}
.pw-btn:disabled{opacity:.6;cursor:wait}
.pw-btn.pw-quiet{background:transparent;color:var(--pw-ink);border:1px solid var(--pw-line)}
.pw-modal{position:fixed;inset:0;z-index:1000;display:flex;align-items:flex-end;justify-content:center;background:rgba(10,20,26,.55)}
.pw-modal[hidden]{display:none}
.pw-sheet{display:flex;flex-direction:column;width:100%;max-width:560px;max-height:88vh;background:#fff;border-radius:16px 16px 0 0;color:var(--pw-ink)}
.pw-sheet h2{margin:0;padding:18px 20px 12px;font-size:1.2rem}
.pw-terms{overflow:auto;padding:4px 20px;font-size:.93rem;line-height:1.55;border-top:1px solid #E3E8E5;border-bottom:1px solid #E3E8E5}
.pw-terms h1,.pw-terms h2,.pw-terms h3{font-size:1rem;margin:16px 0 6px}
.pw-actions{display:grid;gap:10px;padding:14px 20px 20px}
.pw :focus-visible{outline:3px solid #F2B84B;outline-offset:2px}
@media (min-width:600px){.pw-modal{align-items:center;padding:24px}.pw-sheet{border-radius:16px}}
</style>

@if ($preview)
  <p class="pw-alert" style="border-color:#A8660F;background:#FBF1DF">Preview. Nothing is submitted.</p>
@endif
@if ($routerError)
  <p class="pw-alert" role="alert">{{ $routerError }}</p>
@endif
@error('form')<p class="pw-alert" role="alert">{{ $message }}</p>@enderror
@error('accept')<p class="pw-alert" role="alert">{{ $message }}</p>@enderror

@php
  $resident = (bool) old('resident');
  $inv = fn ($f) => $errors->has($f) ? 'aria-invalid=true aria-describedby=pw-'.$f.'-err' : '';
@endphp

<form id="pw-form" method="POST" action="{{ $action }}" novalidate>
  @csrf
  <input type="hidden" name="accept" id="pw-accept" value="">

  <label class="pw-resident" for="pw-resident">
    <input type="checkbox" id="pw-resident" name="resident" value="1" @checked($resident)>
    <span>I'm a resident<small>Log in with your {{ $page->citizen_label }}</small></span>
  </label>

  <div class="pw-group" id="pw-guest" @if($resident) hidden @endif>
    <div class="pw-field">
      <label for="pw-name">Full name</label>
      <input class="pw-input" id="pw-name" name="name" type="text" value="{{ old('name') }}" maxlength="80"
             autocomplete="name" autocapitalize="words" placeholder="Juan Santos" {!! $inv('name') !!}>
      @error('name')<p class="pw-err" id="pw-name-err">{{ $message }}</p>@enderror
    </div>
    <div class="pw-field">
      <label for="pw-contact">Mobile number or email</label>
      <input class="pw-input" id="pw-contact" name="contact" type="text" value="{{ old('contact') }}" maxlength="254"
             autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="0917 123 4567" {!! $inv('contact') !!}>
      @error('contact')<p class="pw-err" id="pw-contact-err">{{ $message }}</p>@enderror
    </div>
  </div>

  <div class="pw-group" id="pw-res" @if(! $resident) hidden @endif>
    <div class="pw-field">
      <label for="pw-citizen">{{ $page->citizen_label }}</label>
      <input class="pw-input" id="pw-citizen" name="citizen_number" type="text" value="{{ old('citizen_number') }}" maxlength="40"
             autocomplete="off" autocapitalize="characters" spellcheck="false" {!! $inv('citizen_number') !!}>
      @if ($page->citizen_hint)<p class="pw-hint">{{ $page->citizen_hint }}</p>@endif
      @error('citizen_number')<p class="pw-err" id="pw-citizen_number-err">{{ $message }}</p>@enderror
    </div>
  </div>

  <button type="submit" class="pw-btn" id="pw-login">Log in</button>

  <noscript>
    <label style="display:flex;gap:10px;font-weight:400">
      <input type="checkbox" name="accept" value="1">
      <span>I accept the <a href="{{ $preview ? '#' : route('portal.terms', ['network' => $network->portal_code]) }}">Terms and Conditions</a></span>
    </label>
  </noscript>
</form>

<div class="pw-modal" id="pw-modal" role="dialog" aria-modal="true" aria-labelledby="pw-terms-title" hidden>
  <div class="pw-sheet">
    <h2 id="pw-terms-title">Terms and Conditions</h2>
    <div class="pw-terms" tabindex="0">{!! $page->termsHtml() !!}</div>
    <div class="pw-actions">
      <button type="button" class="pw-btn" id="pw-agree">I agree, connect me</button>
      <button type="button" class="pw-btn pw-quiet" id="pw-cancel">Cancel</button>
    </div>
  </div>
</div>

<script>
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var form = $('pw-form'), resident = $('pw-resident'), guest = $('pw-guest'), res = $('pw-res');
  var nameIn = $('pw-name'), contactIn = $('pw-contact'), citizenIn = $('pw-citizen');
  var modal = $('pw-modal'), agree = $('pw-agree'), cancel = $('pw-cancel'), accept = $('pw-accept'), loginBtn = $('pw-login');
  var blocked = @json($page->blockedWords());
  var citizenPattern = @json($page->citizen_pattern);
  var citizenLabel = @json($page->citizen_label);
  var preview = @json($preview);

  function toggle() {
    guest.hidden = resident.checked;
    res.hidden = !resident.checked;
  }
  resident.addEventListener('change', function () {
    toggle();
    if (preview) parent.postMessage({ pw: 'resident', on: resident.checked }, '*');
    [nameIn, contactIn, citizenIn].forEach(clear);
    (resident.checked ? citizenIn : nameIn).focus();
  });

  /* ---- Same checks as the server (App\Rules\PersonName, MobileOrEmail) ---- */
  var SEQ = ['qwertyuiop', 'asdfghjkl', 'zxcvbnm', 'abcdefghijklmnopqrstuvwxyz'];

  function looksFake(name) {
    var plain = name.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    var words = plain.split(/[ \-]+/).map(function (w) { return w.replace(/[^a-z]/g, ''); }).filter(Boolean);
    var joined = words.join('');
    if (/([a-z])\1\1/.test(joined)) return true;
    if (/[bcdfghjklmnpqrstvwxz]{6,}/.test(joined)) return true;
    for (var i = 0; i < words.length; i++) {
      if (/([a-z]{2,3})\1\1/.test(words[i])) return true;
      if (words[i].length >= 4 && !/[aeiouy]/.test(words[i])) return true;
    }
    for (var r = 0; r < SEQ.length; r++) {
      var rows = [SEQ[r], SEQ[r].split('').reverse().join('')];
      for (var k = 0; k < 2; k++) {
        for (var j = 0; j + 5 <= rows[k].length; j++) {
          if (joined.indexOf(rows[k].substr(j, 5)) !== -1) return true;
        }
      }
    }
    var phrase = ' ' + words.join(' ') + ' ';
    return blocked.some(function (b) {
      b = b.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z ]/g, '');
      return b && phrase.indexOf(' ' + b + ' ') !== -1;
    });
  }

  function nameProblem(v) {
    v = v.replace(/[\u2019\u2018`\u00b4]/g, "'").replace(/\s+/g, ' ').trim();
    if (!v) return 'Enter your full name.';
    if (/\d/.test(v)) return 'Numbers are not allowed in a name.';
    var ok;
    try { ok = new RegExp("^\\p{L}[\\p{L}\\p{M}]*\\.?(?:[ '\\-]\\p{L}[\\p{L}\\p{M}]*\\.?)*$", 'u').test(v); }
    catch (e) { ok = /^[A-Za-zÀ-ÿ]+\.?(?:[ '\-][A-Za-zÀ-ÿ]+\.?)*$/.test(v); }
    if (!ok) return 'Use letters only. Symbols are not allowed.';
    var full = v.split(' ').filter(function (w) { return w.replace(/[.'\-]/g, '').length >= 2; });
    if (full.length < 2) return 'Enter your first and last name.';
    if (looksFake(v)) return 'Please enter your real name.';
    return '';
  }

  function contactProblem(v) {
    v = v.trim();
    if (!v) return 'Enter your mobile number or email.';
    if (v.indexOf('@') !== -1) {
      return /^[^\s@]+@[^\s@]+\.[A-Za-z]{2,}$/.test(v) ? '' : 'Enter a valid email address.';
    }
    var m = v.replace(/[\s\-().]/g, '').match(/^(?:\+?63|0)(9\d{9})$/);
    if (!m || /^9(\d)\1{8}$/.test(m[1])) return 'Enter a valid mobile number, like 0917 123 4567, or an email address.';
    return '';
  }

  function citizenProblem(v) {
    v = v.trim();
    if (!v) return 'Enter your ' + citizenLabel + '.';
    try { if (!new RegExp('^(?:' + citizenPattern + ')$', 'u').test(v)) return 'Enter a valid ' + citizenLabel + '.'; }
    catch (e) { /* pattern uses PHP-only syntax: the server checks it */ }
    return '';
  }

  function clear(input) {
    input.removeAttribute('aria-invalid');
    input.removeAttribute('aria-describedby');
    var old = $(input.id + '-live-err');
    if (old) old.remove();
  }

  function show(input, message) {
    clear(input);
    if (!message) return false;
    var p = document.createElement('p');
    p.className = 'pw-err';
    p.id = input.id + '-live-err';
    p.textContent = message;
    input.setAttribute('aria-invalid', 'true');
    input.setAttribute('aria-describedby', p.id);
    input.parentNode.appendChild(p);
    return true;
  }

  function validate() {
    var bad = [];
    if (resident.checked) {
      if (show(citizenIn, citizenProblem(citizenIn.value))) bad.push(citizenIn);
    } else {
      if (show(nameIn, nameProblem(nameIn.value))) bad.push(nameIn);
      if (show(contactIn, contactProblem(contactIn.value))) bad.push(contactIn);
    }
    if (bad.length) bad[0].focus();
    return !bad.length;
  }

  [nameIn, contactIn, citizenIn].forEach(function (input) {
    input.addEventListener('input', function () { if (input.getAttribute('aria-invalid')) clear(input); });
  });

  /* ---- Terms pop-up ---- */
  // quiet = opened by the editor's live preview: don't pull keyboard focus into the frame
  function openModal(quiet) {
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    if (!quiet) agree.focus();
    if (preview) parent.postMessage({ pw: 'terms', open: true }, '*');
  }
  function closeModal(quiet) {
    modal.hidden = true;
    document.body.style.overflow = '';
    if (!quiet) loginBtn.focus();
    if (preview) parent.postMessage({ pw: 'terms', open: false }, '*');
  }

  form.addEventListener('submit', function (e) {
    if (accept.value === '1') return;
    e.preventDefault();
    if (validate()) openModal();
  });

  agree.addEventListener('click', function () {
    if (preview) {
      agree.textContent = 'Preview: the form would be sent now';
      return;
    }
    accept.value = '1';
    agree.disabled = true;
    agree.textContent = 'Connecting...';
    form.submit();
  });
  cancel.addEventListener('click', function () { closeModal(); });
  modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
  document.addEventListener('keydown', function (e) {
    if (modal.hidden) return;
    if (e.key === 'Escape') closeModal();
    if (e.key === 'Tab') {
      var items = [modal.querySelector('.pw-terms'), agree, cancel];
      var i = items.indexOf(document.activeElement);
      if (e.shiftKey && i <= 0) { e.preventDefault(); cancel.focus(); }
      else if (!e.shiftKey && i === items.length - 1) { e.preventDefault(); items[0].focus(); }
    }
  });

  // Live preview in the splash editor: the editor sets resident view, Terms pop-up
  // and scroll position after every refresh; we report scrolling back.
  if (preview && window.parent !== window) {
    window.addEventListener('message', function (e) {
      var d = e.data;
      if (!d || d.pw !== 'state') return;
      if (resident.checked !== !!d.resident) { resident.checked = !!d.resident; toggle(); }
      if (d.terms && modal.hidden) openModal(true);
      if (!d.terms && !modal.hidden) closeModal(true);
      if (typeof d.scroll === 'number') window.scrollTo(0, d.scroll);
    });
    var scrollTimer;
    window.addEventListener('scroll', function () {
      clearTimeout(scrollTimer);
      scrollTimer = setTimeout(function () { parent.postMessage({ pw: 'scroll', y: window.scrollY }, '*'); }, 120);
    });
  }

  toggle();
})();
</script>
</div>
