{{--
  Device fields shared by the Add pop-up (devices/index) and the Edit page (devices/form).
  Needs: $device, $type, $info, $editing, $routers, $barangays, $formId
--}}
@php
  $err = fn ($f) => $errors->has($f) ? 'aria-invalid=true aria-describedby='.$f.'-error' : '';
  $val = fn ($f, $default = null) => old($f, $device->{$f} ?? $default);
  $ver = (string) $val('snmp_version', '2c');
  $level = $val('v3_security_level', 'authPriv');
  $keep = $editing ? 'Leave blank to keep the current one.' : '';
@endphp

    <fieldset>
      <legend>{{ $info['label'] }}</legend>
      <div class="row">
        <div class="field">
          <label for="name">Device name</label>
          <input id="name" name="name" type="text" maxlength="64" value="{{ $val('name') }}" required
                 placeholder="{{ $type === 'ap' ? 'POB-AP-01' : 'POB-SW-01' }}" {!! $err('name') !!}>
          @error('name')<p class="error" id="name-error">{{ $message }}</p>@enderror
        </div>
        <div class="field">
          <label for="model">Device model <span class="hint">(optional)</span></label>
          <input id="model" name="model" type="text" maxlength="64" value="{{ $val('model') }}"
                 placeholder="{{ $type === 'ap' ? 'U6-Pro' : 'CRS326-24G-2S+' }}" {!! $err('model') !!}>
          @error('model')<p class="error" id="model-error">{{ $message }}</p>@enderror
        </div>
      </div>
      <div class="row">
        <div class="field">
          <label for="mac_address">MAC address <span class="hint">(optional)</span></label>
          <input id="mac_address" name="mac_address" type="text" class="mono" maxlength="20" value="{{ $val('mac_address') }}"
                 placeholder="AA:BB:CC:DD:EE:FF" spellcheck="false" autocapitalize="characters" {!! $err('mac_address') !!}>
          @error('mac_address')<p class="error" id="mac_address-error">{{ $message }}</p>@enderror
        </div>
        <div class="field">
          <label for="serial_number">Serial number <span class="hint">(optional)</span></label>
          <input id="serial_number" name="serial_number" type="text" class="mono" maxlength="64" value="{{ $val('serial_number') }}"
                 spellcheck="false" {!! $err('serial_number') !!}>
          @error('serial_number')<p class="error" id="serial_number-error">{{ $message }}</p>@enderror
        </div>
      </div>
      <div class="field">
        <label for="firmware_version">Firmware version <span class="hint">(optional)</span></label>
        <input id="firmware_version" name="firmware_version" type="text" class="mono" maxlength="64" value="{{ $val('firmware_version') }}"
               spellcheck="false" style="max-width:320px" {!! $err('firmware_version') !!}>
        <p class="hint">Test SNMP fills in the model, MAC, serial and firmware when the device reports them.</p>
        @error('firmware_version')<p class="error" id="firmware_version-error">{{ $message }}</p>@enderror
      </div>
    </fieldset>

    <fieldset>
      <legend>Location</legend>
      <div class="row">
        <div class="field">
          <label for="barangay_id">Barangay</label>
          @if ($barangays->isEmpty())
            <p class="hint">No barangays yet. <a href="{{ route('settings') }}#barangays">Add them in Settings</a>, then come back.</p>
          @else
            <select id="barangay_id" name="barangay_id" required {!! $err('barangay_id') !!}>
              <option value="">Choose barangay</option>
              @foreach ($barangays as $b)
                <option value="{{ $b->id }}" @selected((string) $val('barangay_id') === (string) $b->id)>{{ $b->name }}</option>
              @endforeach
            </select>
          @endif
          @error('barangay_id')<p class="error" id="barangay_id-error">{{ $message }}</p>@enderror
        </div>
        <div class="field">
          <label for="location">Landmark <span class="hint">(optional)</span></label>
          <input id="location" name="location" type="text" maxlength="255" value="{{ $val('location') }}" placeholder="Covered court, pole 3">
        </div>
      </div>
      <div class="row">
        <div class="field">
          <label for="latitude">Latitude</label>
          <input id="latitude" name="latitude" type="text" inputmode="decimal" class="mono" required placeholder="14.4081"
                 value="{{ $val('latitude') !== null ? (float) $val('latitude') : '' }}" {!! $err('latitude') !!}>
          @error('latitude')<p class="error" id="latitude-error">{{ $message }}</p>@enderror
        </div>
        <div class="field">
          <label for="longitude">Longitude</label>
          <input id="longitude" name="longitude" type="text" inputmode="decimal" class="mono" required placeholder="121.0415"
                 value="{{ $val('longitude') !== null ? (float) $val('longitude') : '' }}" {!! $err('longitude') !!}>
          @error('longitude')<p class="error" id="longitude-error">{{ $message }}</p>@enderror
        </div>
      </div>
      <div class="test-row">
        <button type="button" id="use-location" class="btn quiet">Use my current location</button>
        <a id="map-check" class="hint" href="#" target="_blank" rel="noopener" hidden>Check on Google Maps</a>
        <p id="geo-msg" class="hint" aria-live="polite" style="margin:0">Or paste "14.4081, 121.0415" from Google Maps into Latitude.</p>
      </div>
      <div class="field">
        <label for="mikrotik_router_id">Site router <span class="hint">(optional)</span></label>
        <select id="mikrotik_router_id" name="mikrotik_router_id">
          <option value="">None</option>
          @foreach ($routers as $r)
            <option value="{{ $r->id }}" @selected((string) $val('mikrotik_router_id') === (string) $r->id)>{{ $r->name }}{{ $r->location ? ', '.$r->location : '' }}</option>
          @endforeach
        </select>
      </div>
    </fieldset>

    <fieldset>
      <legend>SNMP</legend>
      <div class="row">
        <div class="field">
          <label for="host">IP address</label>
          <input id="host" name="host" type="text" class="mono" value="{{ $val('host') }}" required placeholder="172.20.0.11" {!! $err('host') !!}>
          @error('host')<p class="error" id="host-error">{{ $message }}</p>@enderror
        </div>
        <div class="field">
          <label for="snmp_port">Port</label>
          <input id="snmp_port" name="snmp_port" type="number" min="1" max="65535" value="{{ $val('snmp_port', 161) }}" required {!! $err('snmp_port') !!}>
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
        <p class="hint">Read-only community set on the device. Stored encrypted. {{ $keep }}</p>
        @error('community')<p class="error" id="community-error">{{ $message }}</p>@enderror
      </div>

      <div id="v3" @if($ver !== '3') hidden @endif>
        <div class="row">
          <div class="field">
            <label for="v3_username">Username</label>
            <input id="v3_username" name="v3_username" type="text" autocomplete="off" maxlength="64" value="{{ $val('v3_username') }}" {!! $err('v3_username') !!}>
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
              @foreach (['SHA', 'SHA256', 'SHA512', 'MD5'] as $p)<option @selected($val('v3_auth_protocol', 'SHA') === $p)>{{ $p }}</option>@endforeach
            </select>
          </div>
          <div class="field">
            <label for="v3_auth_password">Authentication password</label>
            <input id="v3_auth_password" name="v3_auth_password" type="password" autocomplete="off" maxlength="128" {!! $err('v3_auth_password') !!}>
            @if ($keep)<p class="hint">{{ $keep }}</p>@endif
            @error('v3_auth_password')<p class="error" id="v3_auth_password-error">{{ $message }}</p>@enderror
          </div>
        </div>
        <div class="row" id="v3-priv">
          <div class="field">
            <label for="v3_priv_protocol">Encryption</label>
            <select id="v3_priv_protocol" name="v3_priv_protocol">
              @foreach (['AES', 'DES'] as $p)<option @selected($val('v3_priv_protocol', 'AES') === $p)>{{ $p }}</option>@endforeach
            </select>
          </div>
          <div class="field">
            <label for="v3_priv_password">Encryption password</label>
            <input id="v3_priv_password" name="v3_priv_password" type="password" autocomplete="off" maxlength="128" {!! $err('v3_priv_password') !!}>
            @if ($keep)<p class="hint">{{ $keep }}</p>@endif
            @error('v3_priv_password')<p class="error" id="v3_priv_password-error">{{ $message }}</p>@enderror
          </div>
        </div>
      </div>

      <div class="test-row">
        <button type="button" id="test-snmp" class="btn quiet">Test SNMP</button>
        <p id="test-msg" aria-live="polite">Checks that the device answers, and reads its hardware details.</p>
      </div>
    </fieldset>

<p class="hint" style="margin:-6px 0 0">Checked over SNMP every minute; marked offline after {{ config('devices.offline_after') }} missed checks. On the device, enable SNMP (read-only is enough) and allow this server's IP.</p>

@once
<style>
.device-fields select{font:inherit;width:100%;padding:9px 11px;border:1px solid #A7B4AD;border-radius:4px;background:#fff;color:var(--ink)}
.device-fields .choice{display:flex;flex-wrap:wrap;gap:18px;margin-bottom:16px}
.device-fields .test-row{display:flex;flex-wrap:wrap;align-items:center;gap:12px 16px;margin-bottom:18px}
#test-msg{margin:0;font-size:.92rem;color:var(--ink-2)}
#test-msg.ok{color:var(--signal);font-weight:600}
#test-msg.bad{color:var(--fail)}
.device-fields .btn:disabled{opacity:.45;cursor:not-allowed}
.device-fields input.filled{background:#EAF6F1;transition:background 1.5s}
</style>
@endonce

<script>
(function () {
  const $ = (id) => document.getElementById(id);
  const form = $(@json($formId)), msg = $('test-msg'), btn = $('test-snmp');

  /* ---- SNMP version and security level ---- */
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

  /* ---- MAC: tidy to AA:BB:CC:DD:EE:FF when leaving the field ---- */
  $('mac_address').addEventListener('blur', (e) => {
    const hex = e.target.value.replace(/[^0-9a-f]/gi, '');
    if (hex.length === 12) e.target.value = hex.toUpperCase().match(/.{2}/g).join(':');
  });

  /* ---- Coordinates ---- */
  const lat = $('latitude'), lng = $('longitude'), mapLink = $('map-check'), geoMsg = $('geo-msg');
  function syncMap() {
    const a = parseFloat(lat.value), b = parseFloat(lng.value);
    const ok = !isNaN(a) && !isNaN(b) && Math.abs(a) <= 90 && Math.abs(b) <= 180;
    mapLink.hidden = !ok;
    if (ok) mapLink.href = 'https://www.google.com/maps?q=' + a + ',' + b;
  }
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

  /* ---- Test SNMP; fills empty hardware fields from what the device reports ---- */
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

      const filled = [];
      const names = { model: 'model', mac_address: 'MAC', serial_number: 'serial', firmware_version: 'firmware' };
      Object.entries(data.hardware || {}).forEach(([field, value]) => {
        const input = $(field);
        if (input && !input.value.trim() && value) {
          input.value = value;
          input.classList.add('filled');
          setTimeout(() => input.classList.remove('filled'), 1600);
          filled.push(names[field]);
        }
      });

      msg.className = 'ok';
      msg.textContent = 'Answered: ' + (data.sys_name || 'no name') + (data.uptime ? ', up ' + data.uptime : '') + '.'
        + (filled.length ? ' Filled in ' + filled.join(', ') + '.' : '');
    } catch (err) {
      msg.className = 'bad';
      msg.textContent = err.message;
    } finally {
      btn.disabled = false;
    }
  });
})();
</script>
