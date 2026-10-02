@extends('layouts.app')

@section('title', 'Settings | Public WiFi Control')
@section('body-class', 'settings-full')

@push('head')
<style>
/* Settings use the full width of the window */
body.settings-full main{max-width:none}
.settings-section{margin-bottom:36px;scroll-margin-top:90px}
/* Same buttons and fields as the device form (no shared stylesheet has them) */
main .btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;font:inherit;font-size:.82rem;font-weight:600;height:32px;padding:0 14px;border-radius:6px;border:1px solid #0e670d;background:#0e670d;color:#fff;cursor:pointer;text-decoration:none;line-height:1;box-sizing:border-box;transition:background .15s,border-color .15s}
main .btn:hover{background:#0a4d0a;border-color:#0a4d0a;color:#fff}
main .btn.quiet{background:#fff;color:#0e670d;border-color:#9FBFA3}
main .btn.quiet:hover{background:#EEF6EF;border-color:#0e670d;color:#0a4d0a}
main .btn.danger{background:#fff;color:#B3372E;border-color:#E2B6B1}
main .btn.danger:hover{background:#FBECEA}
main .btn.sm{height:28px;padding:0 10px;font-size:.78rem}
main .btn:disabled{opacity:.45;cursor:not-allowed}
.settings-section h2{margin:0 0 6px;font-size:1.1rem}
.settings-section .field{display:grid;gap:5px;margin:0 0 12px;align-content:start}
.settings-section label{font-weight:600;font-size:.88rem}
.settings-section input:not([type=checkbox]):not([type=radio]),.settings-section select,.settings-section textarea{font:inherit;font-size:.9rem;padding:7px 10px;border:1px solid #A7B4AD;border-radius:6px;background:#fff;color:#0F1A1F;box-sizing:border-box;width:100%}
.settings-section textarea{resize:vertical}
.settings-section [aria-invalid=true]{border-color:#B3372E;background:#FDF6F5}
.settings-section .error{margin:0;color:#B3372E;font-size:.82rem}
.settings-section .hint{color:#5c6b66;font-size:.82rem}
.settings-section .actions{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.add-row{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-start;margin-bottom:18px}
.add-row .field{flex:1;min-width:220px;margin:0}
.brgy-list{list-style:none;margin:0;padding:0;border:1px solid var(--line);background:var(--panel)}
.brgy-list li{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:center;padding:10px 14px;border-bottom:1px solid var(--line)}
.brgy-list li:last-child{border-bottom:0}
.brgy-edit{display:flex;gap:8px;align-items:center;min-width:0;margin:0}
.brgy-edit input{max-width:320px}
.brgy-meta{display:flex;gap:12px;align-items:center;white-space:nowrap}
.brgy-meta form{margin:0}
.brgy-meta .hint{min-width:80px;text-align:right}
.brgy-err{grid-column:1/-1;margin:0}
.brgy-list .btn[hidden]{display:none}
.brgy-list .btn:disabled{opacity:.4;cursor:not-allowed}
.map-fields{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr) 120px;gap:14px;max-width:900px}
@media (max-width:640px){.map-fields{grid-template-columns:1fr}}
.map-form .actions{flex-wrap:wrap}
.validity{width:100%;border-collapse:collapse;margin-bottom:14px;background:var(--panel);border:1px solid var(--line)}
.validity th,.validity td{padding:10px 14px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}
.validity th{font-weight:600}
.validity .pair{display:flex;gap:8px}
.validity input{width:110px}
.validity select{width:110px}
.settings-section .validity input:not([type=checkbox]),.settings-section .validity select{width:110px}
.validity .error{margin:6px 0 0}
@media (max-width:640px){.brgy-list li{grid-template-columns:1fr}.brgy-meta{justify-content:space-between}}
/* Left menu: one setting at a time */
.st-layout{display:grid;grid-template-columns:230px minmax(0,1fr);gap:28px;align-items:start}
.st-nav{position:sticky;top:90px;display:flex;flex-direction:column;gap:2px;padding:8px;background:#fff;border:2px solid #0e670d;border-radius:8px}
.st-nav a{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:6px;color:#0F1A1F;text-decoration:none;font-size:.9rem;font-weight:500}
.st-nav a svg{flex:none;color:#5c6b66}
.st-nav a:hover{background:#F0F3F1}
.st-nav a[aria-current=page]{background:#0e670d;color:#fff;font-weight:600}
.st-nav a[aria-current=page] svg{color:#fff}
.st-nav .st-state{margin-left:auto;font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;padding:1px 6px;border-radius:999px;background:#EEF3EF;color:#5c6b66}
.st-nav .st-state.on{background:#E2F1E3;color:#0e670d}
.st-nav a[aria-current=page] .st-state{background:rgba(255,255,255,.2);color:#fff}
.st-nav .st-group{margin:10px 10px 4px;font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#5c6b66}
.st-nav .st-group:first-child{margin-top:2px}
.st-panels .settings-section{margin-bottom:0}
.st-panels .settings-section h2{font-size:1.25rem;margin-bottom:8px}
@media (max-width:900px){
  .st-layout{grid-template-columns:1fr;gap:16px}
  .st-nav{position:static;flex-direction:row;overflow-x:auto;padding:6px}
  .st-nav a{white-space:nowrap}
  .st-nav .st-group{display:none}
}
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1>Settings</h1>
    <p class="lede">Access time, alerts and reports, lists the rest of the system uses, and where other settings live.</p>
  </div>
</div>

@if (session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
@if (session('error'))<div class="alert" role="alert">{{ session('error') }}</div>@endif

<div class="st-layout">
  <nav class="st-nav" aria-label="Settings">
    <span class="st-group">Hotspot</span>
    <a href="#validity" data-tab="validity"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>Access time</a>
    <span class="st-group">Alerts and reports</span>
    <a href="#telegram" data-tab="telegram"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 4 3 11l6 2 2 6 3-4 5 4 2-15z"/><path d="m9 13 8-6"/></svg>Telegram<span class="st-state {{ $telegram['enabled'] ? 'on' : '' }}">{{ $telegram['enabled'] ? 'On' : 'Off' }}</span></a>
    <a href="#mail" data-tab="mail"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>Email<span class="st-state {{ $mail['host'] ? 'on' : '' }}">{{ $mail['host'] ? 'Set' : '.env' }}</span></a>
    <a href="#report" data-tab="report"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5M10 13h6M10 17h6"/></svg>Automatic report<span class="st-state {{ $report['enabled'] ? 'on' : '' }}">{{ $report['enabled'] ? 'On' : 'Off' }}</span></a>
    <span class="st-group">Lists and map</span>
    <a href="#barangays" data-tab="barangays"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg>Barangays<span class="st-state">{{ $barangays->count() }}</span></a>
    <a href="#map" data-tab="map"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 4-6 2v14l6-2 6 2 6-2V4l-6 2z"/><path d="M9 4v14M15 6v14"/></svg>Dashboard map</a>
    <span class="st-group">System</span>
    <a href="#other" data-tab="other"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1 7 17M17 7l2.1-2.1"/></svg>Other settings</a>
  </nav>

  <div class="st-panels">
@php $vErr = $errors->getBag('validity'); @endphp
<section class="settings-section" id="validity" aria-labelledby="validity-title">
  <h2 id="validity-title">Internet access time</h2>
  <p class="hint" style="margin:0 0 14px">How long people stay online after registering on the captive portal. Until then they go online on every router and hotspot network without seeing the portal again; after that they register again.
    A change applies to new registrations. People already registered keep the end time they were given.</p>

  <form method="POST" action="{{ route('settings.validity') }}" novalidate>
    @csrf @method('PUT')
    <table class="validity">
      <thead><tr><th scope="col">Type of user</th><th scope="col">Online for</th></tr></thead>
      <tbody>
        @foreach ($validity as $key => $v)
          <tr>
            <th scope="row"><label for="v-{{ $key }}">{{ $v['label'] }}</label></th>
            <td>
              <div class="pair">
                <input id="v-{{ $key }}" name="{{ $key }}_amount" type="number" min="1" step="1" inputmode="numeric" class="mono"
                       value="{{ $vErr->any() ? old($key.'_amount') : $v['amount'] }}"
                       @if($vErr->has($key.'_amount')) aria-invalid="true" aria-describedby="v-{{ $key }}-error" @endif>
                <label for="v-{{ $key }}-unit" class="sr-only">Unit for {{ $v['label'] }}</label>
                <select id="v-{{ $key }}-unit" name="{{ $key }}_unit">
                  @foreach (['hours' => 'hours', 'days' => 'days'] as $u => $uLabel)
                    <option value="{{ $u }}" @selected(($vErr->any() ? old($key.'_unit') : $v['unit']) === $u)>{{ $uLabel }}</option>
                  @endforeach
                </select>
              </div>
              @if ($vErr->has($key.'_amount'))<p class="error" id="v-{{ $key }}-error">{{ $vErr->first($key.'_amount') }}</p>@endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    <div class="actions"><button class="btn" type="submit">Save access time</button></div>
  </form>
</section>

@include('settings._notifications')

<section class="settings-section" id="barangays" aria-labelledby="brgy-title">
  <h2 id="brgy-title">Barangays</h2>
  <p class="hint" style="margin:0 0 14px">Access points and switches are grouped by these. Renaming one moves its devices with it. A barangay can only be deleted once no device uses it.</p>

  <form method="POST" action="{{ route('barangays.store') }}" class="add-row" novalidate>
    @csrf
    <div class="field">
      <label for="new-brgy" class="sr-only">New barangay name</label>
      <input id="new-brgy" name="name" type="text" maxlength="80" placeholder="Barangay name"
             value="{{ $errors->addBarangay->any() ? old('name') : '' }}"
             @if($errors->addBarangay->has('name')) aria-invalid="true" aria-describedby="new-brgy-error" @endif>
      @if ($errors->addBarangay->has('name'))<p class="error" id="new-brgy-error">{{ $errors->addBarangay->first('name') }}</p>@endif
    </div>
    <button class="btn" type="submit">Add barangay</button>
  </form>

  @if ($barangays->isEmpty())
    <div class="plan"><p>No barangays yet. Add the ones where you install access points and switches.</p></div>
  @else
    <ul class="brgy-list">
      @foreach ($barangays as $b)
        @php $bag = $errors->getBag('barangay'.$b->id); @endphp
        <li>
          <form method="POST" action="{{ route('barangays.update', $b) }}" class="brgy-edit" novalidate>
            @csrf @method('PUT')
            <label for="brgy-{{ $b->id }}" class="sr-only">Name of {{ $b->name }}</label>
            <input id="brgy-{{ $b->id }}" name="name" type="text" maxlength="80"
                   value="{{ $bag->any() ? old('name') : $b->name }}" data-original="{{ $b->name }}"
                   @if($bag->has('name')) aria-invalid="true" aria-describedby="brgy-{{ $b->id }}-error" @endif>
            <button class="btn quiet sm" type="submit" hidden>Save</button>
          </form>
          <div class="brgy-meta">
            <span class="hint">{{ $b->devices_count }} {{ Str::plural('device', $b->devices_count) }}</span>
            <form method="POST" action="{{ route('barangays.destroy', $b) }}"
                  onsubmit="return confirm('Delete {{ $b->name }} from the list?')">
              @csrf @method('DELETE')
              <button class="btn danger sm" type="submit" @disabled($b->devices_count > 0)
                      @if($b->devices_count > 0) title="Move its devices to another barangay first" @endif>Delete</button>
            </form>
          </div>
          @if ($bag->has('name'))<p class="error brgy-err" id="brgy-{{ $b->id }}-error">{{ $bag->first('name') }}</p>@endif
        </li>
      @endforeach
    </ul>
  @endif
</section>

@php $mapErr = $errors->getBag('map'); @endphp
<section class="settings-section" id="map" aria-labelledby="map-title">
  <h2 id="map-title">Dashboard map</h2>
  <p class="hint" style="margin:0 0 14px">Where the map opens, e.g. the center of the city where you deploy. Leave all three empty and the map zooms to fit every access point, switch and router instead.</p>

  <form method="POST" action="{{ route('settings.map') }}" class="map-form" novalidate>
    @csrf @method('PUT')
    <div class="map-fields">
      @foreach ([['latitude', 'Latitude', '14.4081', 'decimal'], ['longitude', 'Longitude', '121.0415', 'decimal'], ['zoom', 'Zoom', (string) $defaultZoom, 'numeric']] as [$f, $label, $ph, $mode])
        <div class="field">
          <label for="map-{{ $f }}">{{ $label }}</label>
          <input id="map-{{ $f }}" name="{{ $f }}" type="text" inputmode="{{ $mode }}" class="mono"
                 value="{{ $mapErr->any() ? old($f) : ($map[$f] !== null ? ($f === 'zoom' ? (int) $map[$f] : (float) $map[$f]) : '') }}"
                 placeholder="{{ $ph }}"
                 @if($mapErr->has($f)) aria-invalid="true" aria-describedby="map-{{ $f }}-error" @endif>
          @if ($mapErr->has($f))<p class="error" id="map-{{ $f }}-error">{{ $mapErr->first($f) }}</p>@endif
        </div>
      @endforeach
    </div>
    <p class="hint" style="margin:-6px 0 14px">Zoom 3 shows a whole region, 13 a city, 15 a barangay (used when empty), 19 a single building.
      Tip: right-click a spot in Google Maps and click the coordinates to copy them, then paste into Latitude.</p>
    <div class="actions">
      <button class="btn" type="submit">Save map position</button>
      <button class="btn quiet" type="button" id="map-clear">Clear: fit to devices</button>
      <a class="btn quiet" href="{{ route('dashboard.map') }}" target="_blank" rel="noopener">Open map</a>
      <span class="hint" id="map-now">
        {{ $map['latitude'] !== null ? 'Now: opens on '.(float) $map['latitude'].', '.(float) $map['longitude'].' at zoom '.($map['zoom'] ?? $defaultZoom).'.' : 'Now: fits to devices automatically.' }}
      </span>
    </div>
  </form>
</section>

<section class="settings-section" id="other" aria-labelledby="other-title">
  <h2 id="other-title">Other settings</h2>
  <div class="plan">
    <p>Address plans, VLAN defaults, rate limits, RADIUS and SNMP timing: <span class="mono">.env</span>, then run <span class="mono">php artisan config:clear</span>.</p>
    <p style="margin-top:8px">Login page, advertisement page and Terms: <a href="{{ route('splash.edit') }}">Captive portal</a>.</p>
    <p style="margin-top:8px">New administrator: <span class="mono">php artisan wifi:make-admin email@example.com</span>.</p>
  </div>
</section>
  </div>
</div>

<script>
  // Left menu: show one setting at a time. The address (#telegram ...) picks it, so saving
  // comes back to the same one; a setting with an error opens by itself. Without
  // JavaScript every setting is shown, one under the other.
  (function () {
    var links = Array.prototype.slice.call(document.querySelectorAll('.st-nav a[data-tab]'));
    var panels = links.map(function (a) { return document.getElementById(a.dataset.tab); }).filter(Boolean);
    var ids = panels.map(function (p) { return p.id; });
    function show(id) {
      if (ids.indexOf(id) === -1) id = ids[0];
      panels.forEach(function (p) { p.hidden = p.id !== id; });
      links.forEach(function (a) { a.setAttribute('aria-current', a.dataset.tab === id ? 'page' : 'false'); });
      try { sessionStorage.setItem('settings.tab', id); } catch (e) {}
    }
    links.forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        history.replaceState(null, '', '#' + a.dataset.tab);
        show(a.dataset.tab);
        window.scrollTo(0, 0);
      });
    });
    window.addEventListener('hashchange', function () { show(location.hash.slice(1)); });
    var withError = panels.filter(function (p) { return p.querySelector('.error, [aria-invalid="true"]'); })[0];
    var saved = null;
    try { saved = sessionStorage.getItem('settings.tab'); } catch (e) {}
    show(withError ? withError.id : (location.hash.slice(1) || saved));
    if (location.hash) window.scrollTo(0, 0);
  })();

  // Map: clearing all three means "fit to devices"; pasting "14.4081, 121.0415" into either box fills both.
  (function () {
    var lat = document.getElementById('map-latitude'), lng = document.getElementById('map-longitude'), zoom = document.getElementById('map-zoom');
    document.getElementById('map-clear').addEventListener('click', function () {
      lat.value = lng.value = zoom.value = '';
      this.form.requestSubmit();
    });
    [lat, lng].forEach(function (input) {
      input.addEventListener('paste', function (e) {
        var m = (e.clipboardData || window.clipboardData).getData('text').match(/(-?\d{1,3}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)/);
        if (!m) return;
        e.preventDefault();
        lat.value = m[1];
        lng.value = m[2];
      });
    });
  })();

  // Show Save only once a name is actually changed.
  document.querySelectorAll('.brgy-edit').forEach(function (form) {
    var input = form.querySelector('input[name=name]'), save = form.querySelector('button');
    function sync() { save.hidden = input.value.trim() === input.dataset.original && input.getAttribute('aria-invalid') !== 'true'; }
    input.addEventListener('input', sync);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { input.value = input.dataset.original; sync(); }
    });
    sync();
  });
</script>
@endsection
