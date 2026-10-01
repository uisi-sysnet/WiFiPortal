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

<div class="device-fields">
    <fieldset>
      <legend>{{ $info['label'] }}</legend>
      <div class="row row-3">
        <div class="field">
          <label for="name">Device name</label>
          <input id="name" name="name" type="text" maxlength="64" value="{{ $val('name') }}" required
                 placeholder="{{ $type === 'ap' ? 'POB-AP-01' : 'POB-SW-01' }}" {!! $err('name') !!}>
          @error('name')<p class="error" id="name-error">{{ $message }}</p>@enderror
        </div>
        <div class="field">
          <label for="model">Model <span class="hint">(optional)</span></label>
          <input id="model" name="model" type="text" maxlength="64" value="{{ $val('model') }}"
                 placeholder="{{ $type === 'ap' ? 'U6-Pro' : 'CRS326-24G-2S+' }}" {!! $err('model') !!}>
          @error('model')<p class="error" id="model-error">{{ $message }}</p>@enderror
        </div>
        <div class="field">
          <label for="firmware_version">Firmware <span class="hint">(optional)</span></label>
          <input id="firmware_version" name="firmware_version" type="text" class="mono" maxlength="64" value="{{ $val('firmware_version') }}"
                 spellcheck="false" {!! $err('firmware_version') !!}>
          @error('firmware_version')<p class="error" id="firmware_version-error">{{ $message }}</p>@enderror
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
    </fieldset>

    <fieldset>
      <legend>Location</legend>
      <div class="row row-3">
        <div class="field">
          <label for="barangay_id">Barangay</label>
          @if ($barangays->isEmpty())
            <p class="hint">No barangays yet. <a href="{{ route('settings') }}#barangays">Add them in Settings</a>.</p>
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
        <div class="field">
          <label for="mikrotik_router_id">Site router <span class="hint">(optional)</span></label>
          <select id="mikrotik_router_id" name="mikrotik_router_id">
            <option value="">None</option>
            @foreach ($routers as $r)
              <option value="{{ $r->id }}" @selected((string) $val('mikrotik_router_id') === (string) $r->id)>{{ $r->name }}{{ $r->location ? ', '.$r->location : '' }}</option>
            @endforeach
          </select>
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
        <button type="button" id="use-location" class="btn quiet">Use my location</button>
        <a id="map-check" class="hint" href="#" target="_blank" rel="noopener" hidden>Check on Google Maps</a>
        <p id="geo-msg" class="hint" aria-live="polite" style="margin:0">Or paste "14.4081, 121.0415" from Google Maps.</p>
      </div>
    </fieldset>

    <fieldset>
      <legend>SNMP</legend>
      <div class="row row-3">
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
        <div class="field">
          <label>Version</label>
          <div class="choice" role="radiogroup" aria-label="SNMP version">
            <label class="check"><input type="radio" name="snmp_version" value="2c" @checked($ver === '2c')> v2c</label>
            <label class="check"><input type="radio" name="snmp_version" value="3" @checked($ver === '3')> v3</label>
            <label class="check"><input type="radio" name="snmp_version" value="1" @checked($ver === '1')> v1</label>
          </div>
        </div>
      </div>

      <div class="field" id="v12" @if($ver === '3') hidden @endif>
        <label for="community">Community</label>
        <input id="community" name="community" type="password" autocomplete="off" maxlength="128" {!! $err('community') !!}>
        <p class="hint">Read-only community set on the device. Stored encrypted. {{ $keep }}</p>
        @error('community')<p class="error" id="community-error">{{ $message }}</p>@enderror
      </div>

      <div id="v3" @if($ver !== '3') hidden @endif>
        <div class="row row-3">
          <div class="field">
            <label for="v3_username">Username</label>
            <input id="v3_username" name="v3_username" type="text" autocomplete="off" maxlength="64" value="{{ $val('v3_username') }}" {!! $err('v3_username') !!}>
            @error('v3_username')<p class="error" id="v3_username-error">{{ $message }}</p>@enderror
          </div>
          <div class="field">
            <label for="v3_security_level">Security</label>
            <select id="v3_security_level" name="v3_security_level">
              <option value="authPriv" @selected($level === 'authPriv')>Auth + encrypt</option>
              <option value="authNoPriv" @selected($level === 'authNoPriv')>Auth only</option>
              <option value="noAuthNoPriv" @selected($level === 'noAuthNoPriv')>None</option>
            </select>
          </div>
        </div>
        <div class="row row-3" id="v3-auth">
          <div class="field">
            <label for="v3_auth_protocol">Auth protocol</label>
            <select id="v3_auth_protocol" name="v3_auth_protocol">
              @foreach (['SHA', 'SHA256', 'SHA512', 'MD5'] as $p)<option @selected($val('v3_auth_protocol', 'SHA') === $p)>{{ $p }}</option>@endforeach
            </select>
          </div>
          <div class="field">
            <label for="v3_auth_password">Auth password</label>
            <input id="v3_auth_password" name="v3_auth_password" type="password" autocomplete="off" maxlength="128" {!! $err('v3_auth_password') !!}>
            @if ($keep)<p class="hint">{{ $keep }}</p>@endif
            @error('v3_auth_password')<p class="error" id="v3_auth_password-error">{{ $message }}</p>@enderror
          </div>
        </div>
        <div class="row row-3" id="v3-priv">
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

    <p class="hint" style="margin:0 0 4px">Checked over SNMP every minute; marked offline after {{ config('devices.offline_after') }} missed checks. On the device, enable SNMP (read-only is enough) and allow this server's IP.</p>
</div>

@once
<style>
/* --- Green Theme Device Fields (Compact, well-spaced) --- */
.device-fields {
  --ink: #0B1C14;
  --ink-2: #5c6b66;
  --signal: #0e670d;
  --signal-dark: #0a4f0a;
  --signal-soft: color-mix(in srgb, #0e670d 10%, #fff);
  --hover: #F0F3F1;
  --warn: #A8660F;
  --fail: #B3372E;
  --focus: #F2B84B;
  --line: #C8D9D0;
  --radius: 8px;
}

.device-fields fieldset {
  border: 1px solid var(--line);
  background: #fff;
  padding: 16px 18px 6px;
  margin: 0 0 14px;
  border-radius: var(--radius);
  min-width: 0;
}

.device-fields fieldset:last-of-type {
  margin-bottom: 10px;
}

.device-fields legend {
  font-weight: 700;
  padding: 0 8px;
  font-size: .72rem;
  color: var(--signal-dark);
  letter-spacing: .08em;
  text-transform: uppercase;
}

.device-fields .field {
  display: grid;
  gap: 5px;
  margin-bottom: 14px;
}

.device-fields .field label {
  font-weight: 600;
  font-size: .72rem;
  color: var(--ink-2);
  text-transform: uppercase;
  letter-spacing: .05em;
}

.device-fields .hint {
  font-size: .7rem;
  color: var(--ink-2);
  margin: 0;
  line-height: 1.4;
}

.device-fields .error {
  font-size: .72rem;
  color: var(--fail);
  margin: 0;
}

.device-fields input[type=text],
.device-fields input[type=email],
.device-fields input[type=password],
.device-fields input[type=number],
.device-fields select {
  font: inherit;
  font-size: .82rem;
  width: 100%;
  height: 34px;
  padding: 0 11px;
  border: 1px solid #A7B4AD;
  border-radius: 6px;
  background: #fff;
  color: var(--ink);
  box-sizing: border-box;
  transition: border-color .15s, box-shadow .15s;
}

.device-fields select {
  padding-right: 28px;
  appearance: none;
  background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%235c6b66' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><path d='M6 9l6 6 6-6'/></svg>");
  background-repeat: no-repeat;
  background-position: right 10px center;
  cursor: pointer;
}

.device-fields input[type=text]:focus,
.device-fields input[type=email]:focus,
.device-fields input[type=password]:focus,
.device-fields input[type=number]:focus,
.device-fields select:focus {
  outline: none;
  border-color: var(--signal);
  box-shadow: 0 0 0 3px var(--signal-soft);
}

.device-fields input[aria-invalid="true"] {
  border-color: var(--fail);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--fail) 10%, transparent);
}

.device-fields input.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: .78rem;
  letter-spacing: -.01em;
}

.device-fields .row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
}

.device-fields .row-3 {
  grid-template-columns: 1fr 1fr 1fr;
}

.device-fields .choice {
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
  align-items: center;
  height: 34px;
  padding: 0 12px;
  background: #F7FAF8;
  border: 1px solid var(--line);
  border-radius: 6px;
  box-sizing: border-box;
}

.device-fields .check {
  display: inline-flex;
  gap: 6px;
  align-items: center;
  font-weight: 500;
  font-size: .8rem;
  cursor: pointer;
  color: var(--ink);
}

.device-fields .check input {
  width: 14px;
  height: 14px;
  accent-color: var(--signal);
  cursor: pointer;
  margin: 0;
}

.device-fields .test-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px 14px;
  margin-bottom: 14px;
  padding: 8px 12px;
  background: #F7FAF8;
  border: 1px dashed var(--line);
  border-radius: 6px;
}

.device-fields #test-msg {
  margin: 0;
  font-size: .78rem;
  color: var(--ink-2);
}

.device-fields #test-msg.ok {
  color: var(--signal);
  font-weight: 600;
}

.device-fields #test-msg.bad {
  color: var(--fail);
}

.device-fields .btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  font: inherit;
  font-size: .78rem;
  font-weight: 600;
  height: 30px;
  padding: 0 14px;
  border-radius: 6px;
  border: 1px solid var(--signal);
  cursor: pointer;
  text-decoration: none;
  transition: background .15s, border-color .15s, color .15s;
  line-height: 1;
  background: var(--signal);
  color: #fff;
  box-sizing: border-box;
}

.device-fields .btn:hover {
  background: var(--signal-dark);
  border-color: var(--signal-dark);
  color: #fff;
}

.device-fields .btn:active {
  transform: translateY(1px);
}

.device-fields .btn.quiet {
  background: #fff;
  color: var(--signal);
  border-color: var(--line);
}

.device-fields .btn.quiet:hover {
  background: var(--signal-soft);
  border-color: var(--signal);
  color: var(--signal-dark);
}

.device-fields .btn:disabled {
  opacity: .45;
  cursor: not-allowed;
  transform: none;
}

.device-fields input.filled {
  background: #EAF6F1;
  transition: background 1.5s;
}

.device-fields #geo-msg {
  margin: 0;
  font-size: .72rem;
  color: var(--ink-2);
}

.device-fields #map-check {
  font-size: .72rem;
  color: var(--signal);
  text-decoration: underline;
  text-underline-offset: 2px;
}

.device-fields #map-check:hover {
  color: var(--signal-dark);
}

@media (max-width: 720px) {
  .device-fields .row,
  .device-fields .row-3 {
    grid-template-columns: 1fr 1fr;
    gap: 12px;
  }
  .device-fields fieldset {
    padding: 14px 14px 4px;
  }
}

@media (max-width: 480px) {
  .device-fields .row,
  .device-fields .row-3 {
    grid-template-columns: 1fr;
  }
}

.device-fields [hidden] {
  display: none !important;
}
</style>
@endonce

<script>
(function () {
  const $ = (id) => document.getElementById(id);
  const form = $(@json($formId)), msg = $('test-msg'), btn = $('test-snmp');
  if (!form || !btn) return;

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