@extends('layouts.app')

@section('title', 'Add '.strtolower($info['label']).' | Public WiFi Control')

@push('head')
<meta name="csrf-token" content="{{ csrf_token() }}">
<style>
select{font:inherit;width:100%;padding:9px 11px;border:1px solid #A7B4AD;border-radius:4px;background:#fff;color:var(--ink)}
.choice{display:flex;flex-wrap:wrap;gap:18px;margin-bottom:16px}
.test-row{display:flex;flex-wrap:wrap;align-items:center;gap:12px 16px;margin-bottom:18px}
#test-msg{margin:0;font-size:.92rem;color:var(--ink-2)}
#test-msg.ok{color:var(--signal);font-weight:600}
#test-msg.bad{color:var(--fail)}
.btn:disabled{opacity:.45;cursor:not-allowed}
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1>Add {{ strtolower($info['label']) }}</h1>
    <p class="lede">The dashboard checks it over SNMP every minute to show whether it is online. Nothing on the device is changed.</p>
  </div>
</div>

@if ($errors->any())<div class="alert" role="alert">Fix the highlighted fields and try again.</div>@endif

@php
  $err = fn ($f) => $errors->has($f) ? 'aria-invalid=true aria-describedby='.$f.'-error' : '';
  $ver = old('snmp_version', '2c');
  $level = old('v3_security_level', 'authPriv');
@endphp

<form id="device-form" method="POST" action="{{ route($info['route'].'.store') }}" class="form-layout" novalidate>
  @csrf
  <div>
    <fieldset>
      <legend>{{ $info['label'] }}</legend>
      <div class="field">
        <label for="name">Name</label>
        <input id="name" name="name" type="text" maxlength="64" value="{{ old('name') }}" required
               placeholder="{{ $type === 'ap' ? 'POB-AP-01' : 'POB-SW-01' }}" {!! $err('name') !!}>
        @error('name')<p class="error" id="name-error">{{ $message }}</p>@enderror
      </div>
      <div class="row">
        <div class="field">
          <label for="barangay_id">Barangay</label>
          @if ($barangays->isEmpty())
            <p class="hint">No barangays yet. <a href="{{ route('settings') }}#barangays">Add them in Settings</a>, then come back.</p>
          @else
            <select id="barangay_id" name="barangay_id" required {!! $err('barangay_id') !!}>
              <option value="">Choose barangay</option>
              @foreach ($barangays as $b)
                <option value="{{ $b->id }}" @selected(old('barangay_id') == $b->id)>{{ $b->name }}</option>
              @endforeach
            </select>
          @endif
          @error('barangay_id')<p class="error" id="barangay_id-error">{{ $message }}</p>@enderror
        </div>
        <div class="field">
          <label for="location">Landmark <span class="hint">(optional)</span></label>
          <input id="location" name="location" type="text" maxlength="255" value="{{ old('location') }}" placeholder="Covered court, pole 3">
        </div>
      </div>
      <div class="field">
        <label for="mikrotik_router_id">Site router <span class="hint">(optional)</span></label>
        <select id="mikrotik_router_id" name="mikrotik_router_id">
          <option value="">None</option>
          @foreach ($routers as $r)
            <option value="{{ $r->id }}" @selected(old('mikrotik_router_id') == $r->id)>{{ $r->name }}{{ $r->location ? ', '.$r->location : '' }}</option>
          @endforeach
        </select>
        <p class="hint">The router this {{ strtolower($info['label']) }} sits behind.</p>
      </div>
    </fieldset>

    <fieldset>
      <legend>Map position</legend>
      <div class="row">
        <div class="field">
          <label for="latitude">Latitude</label>
          <input id="latitude" name="latitude" type="text" inputmode="decimal" class="mono" value="{{ old('latitude') }}" placeholder="14.4081" required {!! $err('latitude') !!}>
          @error('latitude')<p class="error" id="latitude-error">{{ $message }}</p>@enderror
        </div>
        <div class="field">
          <label for="longitude">Longitude</label>
          <input id="longitude" name="longitude" type="text" inputmode="decimal" class="mono" value="{{ old('longitude') }}" placeholder="121.0415" required {!! $err('longitude') !!}>
          @error('longitude')<p class="error" id="longitude-error">{{ $message }}</p>@enderror
        </div>
      </div>
      <div class="test-row">
        <button type="button" id="use-location" class="btn quiet">Use my current location</button>
        <a id="map-check" class="hint" href="#" target="_blank" rel="noopener" hidden>Check on Google Maps</a>
        <p id="geo-msg" class="hint" aria-live="polite" style="margin:0">Standing at the device? Use your phone's location. Or paste "14.4081, 121.0415" from Google Maps into Latitude.</p>
      </div>
    </fieldset>

    <fieldset>
      <legend>SNMP</legend>
      <div class="row">
        <div class="field">
          <label for="host">IP address</label>
          <input id="host" name="host" type="text" class="mono" value="{{ old('host') }}" required placeholder="172.20.0.11" {!! $err('host') !!}>
          @error('host')<p class="error" id="host-error">{{ $message }}</p>@enderror
        </div>
        <div class="field">
          <label for="snmp_port">Port</label>
          <input id="snmp_port" name="snmp_port" type="number" min="1" max="65535" value="{{ old('snmp_port', 161) }}" required {!! $err('snmp_port') !!}>
          @error('snmp_port')<p class="error" id="snmp_port-error">{{ $message }}</p>@enderror
        </div>
      </div>

      <div class="choice" role="radiogroup" aria-label="SNMP version">
        <label class="check"><input type="radio" name="snmp_version" value="2c" @checked($ver === '2c')> v2c</label>
        <label class="check"><input type="radio" name="snmp_version" value="3" @checked($ver === '3')> v3 (encrypted)</label>
        <label class="check"><input type="radio" name="snmp_version" value="1" @checked($ver === '1')> v1</label>
      </div>

      <div class="field" id="v12" @if($ver === '3') hidden @endif>
        <label for="community">Community</label>
        <input id="community" name="community" type="password" autocomplete="off" maxlength="128" {!! $err('community') !!}>
        <p class="hint">The read-only community set on the device. Stored encrypted.</p>
        @error('community')<p class="error" id="community-error">{{ $message }}</p>@enderror
      </div>

      <div id="v3" @if($ver !== '3') hidden @endif>
        <div class="row">
          <div class="field">
            <label for="v3_username">Username</label>
            <input id="v3_username" name="v3_username" type="text" autocomplete="off" maxlength="64" value="{{ old('v3_username') }}" {!! $err('v3_username') !!}>
            @error('v3_username')<p class="error" id="v3_username-error">{{ $message }}</p>@enderror
          </div>
          <div class="field">
            <label for="v3_security_level">Security</label>
            <select id="v3_security_level" name="v3_security_level">
              <option value="authPriv" @selected($level === 'authPriv')>Authentication and encryption</option>
              <option value="authNoPriv" @selected($level === 'authNoPriv')>Authentication only</option>
              <option value="noAuthNoPriv" @selected($level === 'noAuthNoPriv')>None</option>
            </select>
          </div>
        </div>
        <div class="row" id="v3-auth">
          <div class="field">
            <label for="v3_auth_protocol">Authentication</label>
            <select id="v3_auth_protocol" name="v3_auth_protocol">
              @foreach (['SHA', 'SHA256', 'SHA512', 'MD5'] as $p)<option @selected(old('v3_auth_protocol', 'SHA') === $p)>{{ $p }}</option>@endforeach
            </select>
          </div>
          <div class="field">
            <label for="v3_auth_password">Authentication password</label>
            <input id="v3_auth_password" name="v3_auth_password" type="password" autocomplete="off" maxlength="128" {!! $err('v3_auth_password') !!}>
            @error('v3_auth_password')<p class="error" id="v3_auth_password-error">{{ $message }}</p>@enderror
          </div>
        </div>
        <div class="row" id="v3-priv">
          <div class="field">
            <label for="v3_priv_protocol">Encryption</label>
            <select id="v3_priv_protocol" name="v3_priv_protocol">
              @foreach (['AES', 'DES'] as $p)<option @selected(old('v3_priv_protocol', 'AES') === $p)>{{ $p }}</option>@endforeach
            </select>
          </div>
          <div class="field">
            <label for="v3_priv_password">Encryption password</label>
            <input id="v3_priv_password" name="v3_priv_password" type="password" autocomplete="off" maxlength="128" {!! $err('v3_priv_password') !!}>
            @error('v3_priv_password')<p class="error" id="v3_priv_password-error">{{ $message }}</p>@enderror
          </div>
        </div>
      </div>

      <div class="test-row">
        <button type="button" id="test-snmp" class="btn quiet">Test SNMP</button>
        <p id="test-msg" aria-live="polite">Checks that the device answers before you add it.</p>
      </div>
    </fieldset>

    <div class="actions">
      <button class="btn" type="submit">Add {{ strtolower($info['label']) }}</button>
      <a class="btn quiet" href="{{ route($info['route'].'.index') }}">Cancel</a>
    </div>
  </div>

  <aside class="aside">
    <h2>What gets checked</h2>
    <dl>
      <dt>Every</dt><dd>1 minute</dd>
      <dt>Asks for</dt><dd>name, model, uptime</dd>
      <dt>Offline</dt><dd>after {{ config('devices.offline_after') }} missed checks</dd>
    </dl>
    <p>On the device, enable SNMP (read-only is enough) and allow this server's IP address. Use v3 where the device supports it: v1 and v2c send the community in plain text.</p>
    @if ($type === 'ap')
      <p>Connected-client counts per access point will use the same SNMP settings, so nothing needs to be re-entered later.</p>
    @endif
  </aside>
</form>

<script>
(function () {
  const $ = (id) => document.getElementById(id);
  const form = $('device-form'), msg = $('test-msg'), btn = $('test-snmp');

  function sync() {
    const v3 = form.querySelector('input[name=snmp_version]:checked').value === '3';
    const level = $('v3_security_level').value;
    $('v12').hidden = v3;
    $('v3').hidden = !v3;
    $('v3-auth').hidden = level === 'noAuthNoPriv';
    $('v3-priv').hidden = level !== 'authPriv';
  }
  form.querySelectorAll('input[name=snmp_version]').forEach((r) => r.addEventListener('change', sync));
  $('v3_security_level').addEventListener('change', sync);
  sync();

  /* ---- Coordinates ---- */
  const lat = $('latitude'), lng = $('longitude'), mapLink = $('map-check'), geoMsg = $('geo-msg');
  function syncMap() {
    const a = parseFloat(lat.value), b = parseFloat(lng.value);
    const ok = Math.abs(a) <= 90 && Math.abs(b) <= 180 && !isNaN(a) && !isNaN(b);
    mapLink.hidden = !ok;
    if (ok) mapLink.href = 'https://www.google.com/maps?q=' + a + ',' + b;
  }
  // Pasting "lat, lng" (as Google Maps copies it) into either box fills both.
  [lat, lng].forEach((input) => input.addEventListener('paste', (e) => {
    const text = (e.clipboardData || window.clipboardData).getData('text');
    const m = text.match(/(-?\d{1,3}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)/);
    if (!m) return;
    e.preventDefault();
    lat.value = m[1];
    lng.value = m[2];
    syncMap();
  }));
  [lat, lng].forEach((input) => input.addEventListener('input', syncMap));
  syncMap();

  $('use-location').addEventListener('click', () => {
    if (!navigator.geolocation) { geoMsg.textContent = 'This browser cannot share its location.'; return; }
    geoMsg.textContent = 'Getting your location...';
    navigator.geolocation.getCurrentPosition((pos) => {
      lat.value = pos.coords.latitude.toFixed(7);
      lng.value = pos.coords.longitude.toFixed(7);
      syncMap();
      geoMsg.textContent = 'Location filled in, accurate to about ' + Math.round(pos.coords.accuracy) + ' m.';
    }, (err) => {
      geoMsg.textContent = err.code === 1
        ? 'Location permission was denied. Allow it in the browser, or type the coordinates.'
        : 'Could not get a location. The page must be opened over https (or localhost) for this to work.';
    }, { enableHighAccuracy: true, timeout: 15000 });
  });

  btn.addEventListener('click', async () => {
    btn.disabled = true;
    msg.className = '';
    msg.textContent = 'Asking ' + ($('host').value.trim() || 'the device') + '...';
    try {
      const res = await fetch(@json(route('devices.test-snmp')), {
        method: 'POST',
        body: new FormData(form),
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.errors ? Object.values(data.errors)[0][0] : (data.message || 'The device did not answer.'));
      msg.className = 'ok';
      msg.textContent = 'Answered: ' + (data.sys_name || 'no name') + (data.uptime ? ', up ' + data.uptime : '')
        + (data.sys_descr ? '. ' + data.sys_descr.slice(0, 80) : '');
    } catch (err) {
      msg.className = 'bad';
      msg.textContent = err.message;
    } finally {
      btn.disabled = false;
    }
  });
})();
</script>
@endsection
