@extends('layouts.app')

@section('title', 'Settings | Public WiFi Control')

@push('head')
<style>
.settings-section{margin-bottom:36px;scroll-margin-top:20px}
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
.map-fields{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr) 120px;gap:14px;max-width:640px}
@media (max-width:640px){.map-fields{grid-template-columns:1fr}}
.map-form .actions{flex-wrap:wrap}
@media (max-width:640px){.brgy-list li{grid-template-columns:1fr}.brgy-meta{justify-content:space-between}}
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1>Settings</h1>
    <p class="lede">Lists the rest of the system uses, and where other settings live.</p>
  </div>
</div>

@if (session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
@if (session('error'))<div class="alert" role="alert">{{ session('error') }}</div>@endif

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

<section class="settings-section" aria-labelledby="other-title">
  <h2 id="other-title">Other settings</h2>
  <div class="plan">
    <p>Address plans, VLAN defaults, rate limits, RADIUS and SNMP timing: <span class="mono">.env</span>, then run <span class="mono">php artisan config:clear</span>.</p>
    <p style="margin-top:8px">Login page, advertisement page and Terms: <a href="{{ route('splash.edit') }}">Captive portal</a>.</p>
    <p style="margin-top:8px">New administrator: <span class="mono">php artisan wifi:make-admin email@example.com</span>.</p>
  </div>
</section>

<script>
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
