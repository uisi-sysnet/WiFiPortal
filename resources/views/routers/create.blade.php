@extends('layouts.app')

@section('title', 'Add router | Public WiFi Control')

@push('head')
<meta name="csrf-token" content="{{ csrf_token() }}">
<style>
.model-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:end}
select{font:inherit;width:100%;padding:9px 11px;border:1px solid #A7B4AD;border-radius:4px;background:#fff;color:var(--ink)}
#detect-msg{min-height:1.5em}
.test-row{display:flex;flex-wrap:wrap;align-items:center;gap:12px 16px;padding-top:4px;margin-bottom:18px}
#test-msg{margin:0;font-size:.92rem;color:var(--ink-2)}
#test-msg.ok{color:var(--signal);font-weight:600}
#test-msg.bad{color:var(--fail)}
#test-msg.ok::before{content:"";display:inline-block;width:9px;height:9px;border-radius:50%;background:currentColor;margin-right:8px;vertical-align:1px}
.btn:disabled{opacity:.45;cursor:not-allowed;filter:none}
#submit-hint{margin:0;font-size:.9rem;color:var(--ink-2)}
.login-modes{display:grid;gap:12px}
.login-modes .check{align-items:flex-start}
.login-modes .check input{margin-top:3px}

/* Front panel: one block per port, coloured by role */
.front{display:flex;flex-wrap:wrap;gap:4px;padding:12px;background:#1E2F3A;border-radius:4px;margin:4px 0 12px}
.front span{min-width:34px;height:30px;padding:0 6px;display:grid;place-items:center;border-radius:3px;font:500 .78rem "IBM Plex Mono",monospace;color:#fff;background:#3B4D58}
.front span.wan{background:#E0A43A;color:#1E2F3A}
.front span.lan{background:#3D7CC9}
.front span.trunk{background:repeating-linear-gradient(135deg,var(--signal) 0 6px,#7E5BB5 6px 12px)}
.front span.access{background:var(--signal)}
.front span.none{background:#3B4D58;color:#8FA3AB}
.key{display:flex;flex-wrap:wrap;gap:16px;font-size:.82rem;color:var(--ink-2);margin-bottom:14px}
.key i{display:inline-block;width:11px;height:11px;border-radius:2px;margin-right:6px;vertical-align:-1px}

table.ports th,table.ports td{padding:8px 12px;text-align:center;white-space:nowrap}
table.ports th[scope=row]{text-align:left;font-weight:400;background:none;color:var(--ink)}
table.ports th[scope=row] small{display:block;color:var(--ink-2);font-size:.8rem}
table.ports th[scope=row] small.api{color:var(--warn);font-weight:600}
table.ports input{width:18px;height:18px;accent-color:var(--signal);cursor:pointer}
table.vlans{width:100%}
table.vlans th,table.vlans td{padding:8px 10px;vertical-align:middle}
table.vlans input[type=number]{width:92px}
table.vlans td.mono{color:var(--ink-2)}
#port-summary{margin:12px 0 18px}

.choice{display:flex;flex-wrap:wrap;gap:18px;margin-bottom:16px}
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1>Add router</h1>
    <p class="lede">Pick the model, decide what each port does, and set the VLANs. Trunk ports carry the hotspot, management and test VLANs tagged to your APs and switches. We check everything on the router before changing it.</p>
  </div>
</div>

@if ($errors->any())
  <div class="alert" role="alert">
    Fix the highlighted fields and try again.
    @error('ports')<br><strong>{{ $message }}</strong>@enderror
    @error('vlans')<br><strong>{{ $message }}</strong>@enderror
  </div>
@endif

@php
  $err = fn ($f) => $errors->has($f) ? 'aria-invalid=true aria-describedby='.$f.'-error' : '';
  $groups = collect($models)->groupBy('group', true);
  $model = old('model', 'hex');
@endphp

<form id="router-form" method="POST" action="{{ route('routers.store') }}" class="form-layout" novalidate>
  @csrf
  <input type="hidden" id="detected" name="detected_ports" value="{{ old('detected_ports') }}">
  <div>
    <fieldset>
      <legend>Site</legend>
      <div class="field">
        <label for="name">Router name</label>
        <input id="name" name="name" type="text" value="{{ old('name') }}" maxlength="64" required placeholder="brgy-poblacion-hall" {!! $err('name') !!}>
        <p class="hint">Also set as the router identity, so it shows up in RADIUS reports.</p>
        @error('name')<p class="error" id="name-error">{{ $message }}</p>@enderror
      </div>
      <div class="field">
        <label for="location">Location <span class="hint">(optional)</span></label>
        <input id="location" name="location" type="text" value="{{ old('location') }}" maxlength="255" placeholder="Barangay hall, 2nd floor">
      </div>
    </fieldset>

    <fieldset>
      <legend>API connection</legend>
      <div class="row">
        <div class="field">
          <label for="host">IP address or hostname</label>
          <input id="host" name="host" type="text" value="{{ old('host') }}" required placeholder="172.16.10.1" class="mono" {!! $err('host') !!}>
          @error('host')<p class="error" id="host-error">{{ $message }}</p>@enderror
        </div>
        <div class="field">
          <label for="api_port">API port</label>
          <input id="api_port" name="api_port" type="number" min="1" max="65535" value="{{ old('api_port', config('hotspot.api.port')) }}" required {!! $err('api_port') !!}>
          @error('api_port')<p class="error" id="api_port-error">{{ $message }}</p>@enderror
        </div>
      </div>
      <div class="field">
        <label class="check"><input id="use_ssl" type="checkbox" name="use_ssl" value="1" @checked(old('use_ssl'))> Use API-SSL (port {{ config('hotspot.api.ssl_port') }})</label>
      </div>
      <div class="row">
        <div class="field">
          <label for="username">API username</label>
          <input id="username" name="username" type="text" value="{{ old('username') }}" autocomplete="off" required {!! $err('username') !!}>
          @error('username')<p class="error" id="username-error">{{ $message }}</p>@enderror
        </div>
        <div class="field">
          <label for="password">API password</label>
          <input id="password" name="password" type="password" autocomplete="new-password" required {!! $err('password') !!}>
          <p class="hint">Stored encrypted.</p>
          @error('password')<p class="error" id="password-error">{{ $message }}</p>@enderror
        </div>
      </div>
      <div class="test-row">
        <button type="button" id="test-conn" class="btn quiet">Test API connection</button>
        <p id="test-msg" aria-live="polite">Required before the router can be added.</p>
      </div>
    </fieldset>

    <fieldset>
      <legend>Ports</legend>
      <div class="model-row">
        <div class="field" style="margin-bottom:8px">
          <label for="model">Router model</label>
          <select id="model" name="model">
            @if ($model === 'detected' && old('detected_ports'))
              <option value="detected" selected>Read from router</option>
            @endif
            @foreach ($groups as $group => $items)
              <optgroup label="{{ $group }}">
                @foreach ($items as $key => $m)
                  <option value="{{ $key }}" @selected($model === $key)>{{ $m['label'] }} ({{ count($m['ports']) }} ports)</option>
                @endforeach
              </optgroup>
            @endforeach
          </select>
        </div>
        <button type="button" id="detect" class="btn quiet" style="margin-bottom:8px">Read ports from router</button>
      </div>
      <p class="hint" id="detect-msg" aria-live="polite">Not listed, or has wireless? Fill in the API connection above and read the ports from the router.</p>

      <div class="front" id="front" aria-hidden="true"></div>
      <div class="key">
        <span><i style="background:#E0A43A"></i>WAN, internet</span>
        <span><i style="background:repeating-linear-gradient(135deg,var(--signal) 0 3px,#7E5BB5 3px 6px)"></i>Trunk, all VLANs tagged</span>
        <span><i style="background:var(--signal)"></i>Hotspot, untagged</span>
        <span><i style="background:#3D7CC9"></i>LAN, untagged office network</span>
        <span><i style="background:#3B4D58"></i>Not used</span>
      </div>

      <div class="table-wrap">
        <table class="ports">
          <thead><tr><th scope="col" style="text-align:left">Port</th><th scope="col">WAN</th><th scope="col">Trunk</th><th scope="col">Hotspot</th><th scope="col">LAN</th><th scope="col">Not used</th></tr></thead>
          <tbody id="port-rows"></tbody>
        </table>
      </div>
      <p id="port-summary" class="hint" aria-live="polite"></p>
    </fieldset>

    <fieldset>
      <legend>VLANs</legend>
      <p class="hint" style="margin:0 0 12px">Use the same IDs your switches and APs are set to. The interface name is what you will see in Winbox.</p>
      <div class="table-wrap" style="margin-bottom:14px">
        <table class="vlans">
          <thead><tr><th scope="col">Network</th><th scope="col">VLAN ID</th><th scope="col">Interface name</th><th scope="col">Subnet</th></tr></thead>
          <tbody>
          @foreach (\App\Models\MikrotikRouter::NETWORKS as $net => $label)
            @php $d = $vlanDefaults[$net]; @endphp
            <tr>
              <th scope="row" style="background:none;color:var(--ink);font-weight:600">{{ $label }}</th>
              <td>
                <input type="number" min="2" max="4094" name="vlans[{{ $net }}][id]" id="vlan-{{ $net }}-id" data-net="{{ $net }}"
                       value="{{ old("vlans.$net.id", $d['id']) }}" aria-label="{{ $label }} VLAN ID" required
                       @error("vlans.$net.id") aria-invalid="true" @enderror>
              </td>
              <td>
                <input type="text" class="mono" name="vlans[{{ $net }}][name]" id="vlan-{{ $net }}-name" data-net="{{ $net }}"
                       value="{{ old("vlans.$net.name", $d['name']) }}" maxlength="32" aria-label="{{ $label }} interface name" required
                       @error("vlans.$net.name") aria-invalid="true" @enderror>
              </td>
              <td class="mono">{{ $next ? ($net === 'hotspot' ? $next['subnet'] : $next[$net.'_subnet']) : '' }}</td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
      @foreach (['vlans.hotspot.id','vlans.hotspot.name','vlans.mgmt.id','vlans.mgmt.name','vlans.test.id','vlans.test.name'] as $f)
        @error($f)<p class="error">{{ $message }}</p>@enderror
      @endforeach
      <div class="field">
        <label class="check"><input type="checkbox" name="mgmt_native" value="1" @checked(old('mgmt_native', config('hotspot.mgmt_native')))> Send management untagged on trunk ports</label>
        <p class="hint">Turn on if your APs or switches get their management IP without a VLAN tag (native VLAN).</p>
      </div>
    </fieldset>

    <fieldset>
      <legend>Hotspot login page</legend>
      @php $loginMode = old('login_mode', 'portal'); @endphp
      <div class="login-modes" role="radiogroup" aria-label="Login page">
        <label class="check"><input type="radio" name="login_mode" value="portal" @checked($loginMode === 'portal')>
          <span>Captive portal from this system<small class="hint" style="display:block;font-weight:400">Login page with resident ID and Terms, then the advertisement page with Connect. <a href="{{ route('splash.edit') }}" target="_blank">Edit captive portal</a></small></span></label>
        <label class="check"><input type="radio" name="login_mode" value="custom" @checked($loginMode === 'custom')>
          <span>Custom URL<small class="hint" style="display:block;font-weight:400">Your own external login or splash page</small></span></label>
        <label class="check"><input type="radio" name="login_mode" value="builtin" @checked($loginMode === 'builtin')>
          <span>Router's built-in page<small class="hint" style="display:block;font-weight:400">MikroTik's standard username and password page</small></span></label>
      </div>
      <div class="field" id="login-url-field" @if($loginMode !== 'custom') hidden @endif style="margin-top:14px">
        <label for="login_url">External login page URL</label>
        <input id="login_url" name="login_url" type="text" class="mono" value="{{ old('login_url') }}" placeholder="https://portal.example.com/login" {!! $err('login_url') !!}>
        <p class="hint">Receives <span class="mono">mac</span>, <span class="mono">ip</span>, <span class="mono">link-login-only</span>, <span class="mono">link-orig</span>, <span class="mono">chap-id</span>, <span class="mono">chap-challenge</span> and <span class="mono">error</span> as query parameters. Its host is allowed before login.</p>
        @error('login_url')<p class="error" id="login_url-error">{{ $message }}</p>@enderror
      </div>
      <p class="hint" style="margin:12px 0 18px">For the captive portal and custom URL, the router downloads its <span class="mono">login.html</span> from <span class="mono">{{ config('hotspot.portal_url') }}</span>, so the router must be able to reach that address.</p>
    </fieldset>

    <fieldset>
      <legend>Internet (WAN) settings</legend>
      @php $wanMode = old('wan_mode', 'keep'); @endphp
      <div class="choice" role="radiogroup" aria-label="WAN address">
        <label class="check"><input type="radio" name="wan_mode" value="keep" @checked($wanMode === 'keep')> Keep current settings</label>
        <label class="check"><input type="radio" name="wan_mode" value="dhcp" @checked($wanMode === 'dhcp')> Get IP from ISP (DHCP)</label>
        <label class="check"><input type="radio" name="wan_mode" value="static" @checked($wanMode === 'static')> Static IP</label>
      </div>
      <div class="row" id="wan-static" @if($wanMode !== 'static') hidden @endif>
        <div class="field">
          <label for="wan_address">IP address with prefix</label>
          <input id="wan_address" name="wan_address" type="text" value="{{ old('wan_address') }}" placeholder="203.0.113.10/29" class="mono" {!! $err('wan_address') !!}>
          @error('wan_address')<p class="error" id="wan_address-error">{{ $message }}</p>@enderror
        </div>
        <div class="field">
          <label for="wan_gateway">ISP gateway</label>
          <input id="wan_gateway" name="wan_gateway" type="text" value="{{ old('wan_gateway') }}" placeholder="203.0.113.9" class="mono" {!! $err('wan_gateway') !!}>
          @error('wan_gateway')<p class="error" id="wan_gateway-error">{{ $message }}</p>@enderror
        </div>
      </div>
      <p class="hint" style="margin-bottom:18px">Keep current settings if the WAN already works, for example PPPoE set up by hand.</p>
    </fieldset>

    <div class="actions">
      <button class="btn" type="submit" id="submit-btn" disabled aria-describedby="submit-hint">Add and configure router</button>
      <a class="btn quiet" href="{{ route('routers.index') }}">Cancel</a>
      <p id="submit-hint">Test the API connection first.</p>
    </div>
  </div>

  <aside class="aside" aria-labelledby="alloc-title">
    <h2 id="alloc-title">This router will get</h2>
    @if ($next)
      <dl>
        <dt>Hotspot</dt><dd class="mono">{{ $next['subnet'] }}</dd>
        <dt>Devices</dt><dd>up to {{ number_format($plan['hosts_per_block']) }}</dd>
        <dt>Management</dt><dd class="mono">{{ $next['mgmt_subnet'] }}</dd>
        <dt>Test</dt><dd class="mono">{{ $next['test_subnet'] }}</dd>
      </dl>
      <dl id="lan-aside">
        <dt>LAN</dt><dd class="mono">{{ $next['lan_subnet'] }}</dd>
      </dl>
      <dl>
        <dt>Per user</dt><dd class="mono">{{ config('hotspot.rate_limit') }}</dd>
        <dt>Login page</dt><dd class="mono">{{ config('hotspot.dns_name') }}</dd>
        <dt>RADIUS</dt><dd>{{ config('hotspot.radius.host') ?: 'not set' }}</dd>
      </dl>
      <p>Each network's gateway is <span class="mono">.1</span>. On management, test and LAN, addresses below <span class="mono">.64</span> are kept for fixed IPs.</p>
      <p>Hotspot and test users reach only the internet. Only management and LAN can open Winbox on the router.</p>
    @else
      <p>The address plan is full. Widen <span class="mono">HOTSPOT_SUPERNET</span> before adding more routers.</p>
    @endif
  </aside>
</form>

<script>
(function () {
  const models = @json(collect($models)->map(fn ($m) => $m['ports']));
  const oldRoles = @json((object) old('ports', []));
  const detectUrl = @json(route('routers.detect'));
  const token = document.querySelector('meta[name=csrf-token]').content;
  const ROLES = [['wan', 'WAN'], ['trunk', 'Trunk'], ['access', 'Hotspot untagged'], ['lan', 'LAN'], ['none', 'Not used']];

  const $ = (id) => document.getElementById(id);
  const select = $('model'), rows = $('port-rows'), front = $('front'), summary = $('port-summary');
  const detectedInput = $('detected'), detectBtn = $('detect'), detectMsg = $('detect-msg');

  const testBtn = $('test-conn'), testMsg = $('test-msg'), submitBtn = $('submit-btn'), submitHint = $('submit-hint');
  const CONN_FIELDS = ['host', 'api_port', 'use_ssl', 'username', 'password'];
  let tested = false;

  function connectionBody() {
    return {
      host: $('host').value.trim(), api_port: $('api_port').value.trim(), use_ssl: $('use_ssl').checked,
      username: $('username').value.trim(), password: $('password').value,
    };
  }

  function setTested(ok, message) {
    tested = ok;
    submitBtn.disabled = !ok;
    submitHint.hidden = ok;
    testMsg.className = ok ? 'ok' : (message ? 'bad' : '');
    testMsg.textContent = message || 'Required before the router can be added.';
  }

  async function postConnection(url) {
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
      body: JSON.stringify(connectionBody()),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      const firstError = data.errors ? Object.values(data.errors)[0][0] : null;
      throw new Error(firstError || data.message || 'The router did not answer.');
    }
    return data;
  }

  testBtn.addEventListener('click', async () => {
    const b = connectionBody();
    if (!b.host || !b.username || !b.password) {
      setTested(false, 'Fill in the IP address, API username and password first.');
      return;
    }
    testBtn.disabled = true;
    testMsg.className = '';
    testMsg.textContent = 'Connecting to ' + b.host + ':' + b.api_port + '...';
    try {
      const d = await postConnection(@json(route('routers.test-connection')));
      setTested(true, 'Connected to ' + (d.identity || 'router') + ', ' + (d.board_name || 'unknown model')
        + ', RouterOS ' + (d.ros_version || '?') + (d.uptime ? ', up ' + d.uptime : '') + '.');
    } catch (err) {
      setTested(false, err.message);
    } finally {
      testBtn.disabled = false;
    }
  });

  // Any change to the connection details means the earlier test no longer counts.
  CONN_FIELDS.forEach((id) => {
    const el = $(id);
    const evt = el.type === 'checkbox' ? 'change' : 'input';
    el.addEventListener(evt, () => {
      if (tested) setTested(false, 'Connection details changed. Test again.');
    });
  });

  // Enter key or a re-enabled button through dev tools: still blocked here, and by the server.
  $('router-form').addEventListener('submit', (e) => {
    if (!tested) {
      e.preventDefault();
      setTested(false, 'Test the API connection before adding the router.');
      testBtn.focus();
    }
  });

  let detected = null;
  try { detected = JSON.parse(detectedInput.value || 'null'); } catch (e) {}

  const typeOf = (n) => /^q?sfp/.test(n) ? 'SFP' : /^combo/.test(n) ? 'Combo' : 'Ethernet';
  const short = (n) => n.replace(/^ether/, '').replace(/^sfp-sfpplus/, 'S+').replace(/^sfp28-/, 'S28-')
                        .replace(/^q?sfp/, 'S').replace(/^combo/, 'C').replace(/^(wlan|wifi)/, 'W');

  function currentPorts() {
    if (select.value === 'detected' && detected) {
      return detected.ports.map((p) => ({ name: p.name, type: p.type === 'wireless' ? 'Wireless' : typeOf(p.name), bridge: p.bridge, api: p.api }));
    }
    return (models[select.value] || []).map((n) => ({ name: n, type: typeOf(n) }));
  }

  // Sensible start: the port the dashboard connects through (or the first) is WAN,
  // wired ports are trunks, wireless interfaces carry the hotspot untagged.
  function defaults(ports) {
    const roles = {};
    const direct = ports.find((p) => p.api && !p.bridge);
    const wan = direct ? direct.name : (ports[0] && ports[0].name);
    ports.forEach((p) => {
      roles[p.name] = p.name === wan ? 'wan' : (p.type === 'Wireless' ? 'access' : 'trunk');
    });
    return roles;
  }

  function render(roles) {
    const ports = currentPorts();
    rows.innerHTML = '';
    if (!ports.length) {
      rows.innerHTML = '<tr><td colspan="6" class="hint" style="text-align:left">Choose a model, or read the ports from the router.</td></tr>';
      return update();
    }
    roles = roles || defaults(ports);
    ports.forEach((p) => {
      const tr = document.createElement('tr');
      const th = document.createElement('th');
      th.scope = 'row';
      th.innerHTML = '<span class="mono"></span><small></small>';
      th.querySelector('.mono').textContent = p.name;
      const notes = [p.type];
      if (p.bridge) notes.push('now in ' + p.bridge);
      th.querySelector('small').textContent = notes.join(', ');
      if (p.api) {
        const s = document.createElement('small');
        s.className = 'api';
        s.textContent = 'Dashboard connects through here';
        th.appendChild(s);
      }
      tr.appendChild(th);
      ROLES.forEach(([value, label]) => {
        const td = document.createElement('td');
        const input = document.createElement('input');
        input.type = 'radio';
        input.name = 'ports[' + p.name + ']';
        input.value = value;
        input.checked = (roles[p.name] || 'none') === value;
        input.setAttribute('aria-label', p.name + ' as ' + label);
        td.appendChild(input);
        tr.appendChild(td);
      });
      rows.appendChild(tr);
    });
    update();
  }

  function currentRoles() {
    const r = {};
    rows.querySelectorAll('input[type=radio]:checked').forEach((i) => { r[i.name.slice(6, -1)] = i.value; });
    return r;
  }

  function update() {
    const r = currentRoles();
    front.innerHTML = '';
    const by = { wan: [], trunk: [], access: [], lan: [], none: [] };
    Object.entries(r).forEach(([name, role]) => {
      by[role].push(name);
      const s = document.createElement('span');
      s.className = role;
      s.textContent = short(name);
      front.appendChild(s);
    });
    const parts = [];
    const ok = by.wan.length === 1 && (by.trunk.length + by.access.length) > 0;
    parts.push(by.wan.length ? 'WAN: ' + by.wan.join(', ') + '.' : 'Choose a WAN port.');
    if (by.trunk.length) parts.push('Trunk: ' + by.trunk.join(', ') + '.');
    if (by.access.length) parts.push('Hotspot untagged: ' + by.access.join(', ') + '.');
    if (!by.trunk.length && !by.access.length) parts.push('Choose at least one trunk or hotspot port.');
    parts.push(by.lan.length ? 'LAN: ' + by.lan.join(', ') + '.' : 'No LAN.');
    summary.textContent = parts.join(' ');
    summary.style.color = ok ? '' : 'var(--fail)';
    if ($('lan-aside')) {
      $('lan-aside').hidden = !by.lan.length;
      $('lan-none').hidden = !!by.lan.length;
    }
  }

  // Only one WAN: picking WAN on a port frees the previous WAN port.
  rows.addEventListener('change', (e) => {
    if (e.target.value === 'wan') {
      rows.querySelectorAll('input[value=wan]:checked').forEach((i) => {
        if (i !== e.target) rows.querySelector('input[name="' + i.name + '"][value=none]').checked = true;
      });
    }
    update();
  });

  select.addEventListener('change', () => render(null));

  detectBtn.addEventListener('click', async () => {
    const b = connectionBody();
    if (!b.host || !b.username || !b.password) {
      detectMsg.textContent = 'Fill in the IP address, API username and password first.';
      return;
    }
    detectBtn.disabled = true;
    detectMsg.textContent = 'Reading ports from ' + b.host + '...';
    try {
      const data = await postConnection(detectUrl);
      setTested(true, 'Connected to ' + (data.identity || 'router') + ', ' + (data.board_name || 'unknown model')
        + ', RouterOS ' + (data.ros_version || '?') + '.');

      detected = data;
      detectedInput.value = JSON.stringify(data);
      let opt = select.querySelector('option[value=detected]');
      if (!opt) { opt = document.createElement('option'); opt.value = 'detected'; select.insertBefore(opt, select.firstChild); }
      opt.textContent = 'Read from router: ' + (data.board_name || 'unknown model') + ' (' + data.ports.length + ' ports)';
      select.value = 'detected';
      render(null);
      detectMsg.textContent = 'Found ' + data.ports.length + ' ports on ' + (data.board_name || 'the router') + ', RouterOS ' + (data.ros_version || '?') + '.';
    } catch (err) {
      detectMsg.textContent = err.message;
      setTested(false, err.message);
    } finally {
      detectBtn.disabled = false;
    }
  });

  document.querySelectorAll('input[id$="-id"][data-net]').forEach((idInput) => {
    idInput.addEventListener('input', () => {
      const nameInput = $('vlan-' + idInput.dataset.net + '-name');
      const m = nameInput.value.match(/^vlan\d*-(.+)$/);
      if (m && /^\d+$/.test(idInput.value)) nameInput.value = 'vlan' + idInput.value + '-' + m[1];
    });
  });

  document.querySelectorAll('input[name=login_mode]').forEach((r) => r.addEventListener('change', () => {
    $('login-url-field').hidden = document.querySelector('input[name=login_mode]:checked').value !== 'custom';
  }));

  document.querySelectorAll('input[name=wan_mode]').forEach((r) => r.addEventListener('change', () => {
    $('wan-static').hidden = document.querySelector('input[name=wan_mode]:checked').value !== 'static';
  }));

  const ssl = $('use_ssl'), port = $('api_port');
  const plain = '{{ config('hotspot.api.port') }}', secure = '{{ config('hotspot.api.ssl_port') }}';
  ssl.addEventListener('change', () => {
    if ([plain, secure, ''].includes(port.value)) port.value = ssl.checked ? secure : plain;
  });

  render(Object.keys(oldRoles).length ? oldRoles : null);
})();
</script>
@endsection
