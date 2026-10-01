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

/* Front panel: one block per port, coloured by role */
.front{display:flex;flex-wrap:wrap;gap:4px;padding:12px;background:#1E2F3A;border-radius:4px;margin:4px 0 12px}
.front span{min-width:34px;height:30px;padding:0 6px;display:grid;place-items:center;border-radius:3px;font:500 .78rem "IBM Plex Mono",monospace;color:#fff;background:#3B4D58}
.front span.wan{background:#E0A43A;color:#1E2F3A}
.front span.lan{background:#3D7CC9}
.front span.trunk{background:repeating-linear-gradient(135deg,var(--signal) 0 6px,#7E5BB5 6px 12px)}
.front span.none{background:#3B4D58;color:#8FA3AB}
.key{display:flex;flex-wrap:wrap;gap:16px;font-size:.82rem;color:var(--ink-2);margin-bottom:14px}
.key i{display:inline-block;width:11px;height:11px;border-radius:2px;margin-right:6px;vertical-align:-1px}

table.ports th,table.ports td{padding:8px 12px;text-align:center;white-space:nowrap}
table.ports thead th{font-size:.85rem}
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

/* Hotspot networks */
.modes{margin:0 0 16px;padding-left:18px;display:grid;gap:4px}
.net-card{border:1px solid var(--line);border-left:5px solid var(--net-color,var(--signal));border-radius:6px;padding:14px 16px 2px;margin-bottom:14px;background:#FBFCFB}
.net-head{display:flex;align-items:center;gap:10px;margin-bottom:12px}
.net-head strong{flex:1}
.net-head .net-subnet{font-size:.85rem;color:var(--ink-2)}
.link-btn{font:inherit;font-size:.85rem;background:none;border:0;color:var(--fail);cursor:pointer;padding:2px 4px;text-decoration:underline}
.row3{display:grid;grid-template-columns:minmax(0,2fr) 110px minmax(0,1.5fr);gap:12px}
@media (max-width:720px){.row3{grid-template-columns:1fr}}
.net-errors{margin:-6px 0 12px}
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1>Add router</h1>
    <p class="lede">Pick the model, set up one or more hotspot networks, decide what each port does, and set the VLANs. Trunk ports carry every hotspot VLAN plus management and test, tagged, to your APs and switches. We check everything on the router before changing it.</p>
  </div>
</div>

@if ($errors->any())
  <div class="alert" role="alert">
    Fix the highlighted fields and try again.
    @error('ports')<br><strong>{{ $message }}</strong>@enderror
    @error('vlans')<br><strong>{{ $message }}</strong>@enderror
    @error('networks')<br><strong>{{ $message }}</strong>@enderror
  </div>
@endif

@php
  $err = fn ($f) => $errors->has($f) ? 'aria-invalid=true aria-describedby='.$f.'-error' : '';
  $groups = collect($models)->groupBy('group', true);
  $model = old('model', 'hex');
  $networkErrors = collect($errors->getMessages())->filter(fn ($m, $k) => str_starts_with($k, 'networks.'));
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
      <div class="row">
        <div class="field">
          <label for="latitude">Latitude <span class="hint">(optional)</span></label>
          <input id="latitude" name="latitude" type="text" inputmode="decimal" class="mono" value="{{ old('latitude') }}" placeholder="14.4081" {!! $err('latitude') !!}>
          @error('latitude')<p class="error" id="latitude-error">{{ $message }}</p>@enderror
        </div>
        <div class="field">
          <label for="longitude">Longitude <span class="hint">(optional)</span></label>
          <input id="longitude" name="longitude" type="text" inputmode="decimal" class="mono" value="{{ old('longitude') }}" placeholder="121.0415" {!! $err('longitude') !!}>
          @error('longitude')<p class="error" id="longitude-error">{{ $message }}</p>@enderror
        </div>
      </div>
      <p class="hint" style="margin:-8px 0 18px">Puts the router on the dashboard map, with lines to the switches and access points connected to it.</p>
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
      <legend>Hotspot networks</legend>
      <p class="hint" style="margin:0 0 10px">Each network gets its own VLAN, subnet and hotspot, and they can't reach each other. For each one, choose which <a href="{{ route('splash.edit') }}" target="_blank">captive portal design</a> shows the login page and which shows the advertisement page:</p>
      <ul class="hint modes">
        <li><strong>One portal for all:</strong> pick the same two designs on every network.</li>
        <li><strong>Own portal each:</strong> pick a different design for each network.</li>
        <li><strong>Same login, different ads:</strong> same login design everywhere, a different advertisement design per network.</li>
      </ul>
      <div id="networks"></div>
      <p style="margin:0 0 14px"><button type="button" class="btn quiet" id="add-network">Add hotspot network</button></p>
      <p class="hint" style="margin:0 0 18px">For the captive portal and custom URL, the router downloads its <span class="mono">login.html</span> from <span class="mono">{{ config('hotspot.portal_url') }}</span>, so the router must be able to reach that address.</p>
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
      <div class="key" id="key"></div>

      <div class="table-wrap">
        <table class="ports">
          <thead><tr id="port-head"></tr></thead>
          <tbody id="port-rows"></tbody>
        </table>
      </div>
      <p id="port-summary" class="hint" aria-live="polite"></p>
    </fieldset>

    <fieldset>
      <legend>Management and test VLANs</legend>
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
              <td class="mono">{{ $next ? $next[$net.'_subnet'] : '' }}</td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
      @foreach (['vlans.mgmt.id','vlans.mgmt.name','vlans.test.id','vlans.test.name'] as $f)
        @error($f)<p class="error">{{ $message }}</p>@enderror
      @endforeach
      <div class="field">
        <label class="check"><input type="checkbox" name="mgmt_native" value="1" @checked(old('mgmt_native', config('hotspot.mgmt_native')))> Send management untagged on trunk ports</label>
        <p class="hint">Turn on if your APs or switches get their management IP without a VLAN tag (native VLAN).</p>
      </div>
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
      <p class="hint" style="margin-bottom:18px">Keep current settings if the WAN already works, for example PPPoE set up by hand (hotspot traffic then goes out through the PPPoE interface).</p>
    </fieldset>

    <div class="actions">
      <button class="btn" type="submit" id="submit-btn" disabled aria-describedby="submit-hint">Add and configure router</button>
      <a class="btn quiet" href="{{ route('routers.index') }}">Cancel</a>
      <p id="submit-hint">Test the API connection first.</p>
    </div>
  </div>

  <aside class="aside" aria-labelledby="alloc-title">
    <h2 id="alloc-title">This router will get</h2>
    @if ($next && $nextHotspot)
      <dl id="hs-aside"></dl>
      <dl>
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
      <p>Automatic addresses are the next free ones in <span class="mono">{{ $plan['supernet'] }}</span>. A network can be /16 to /24; a user limit shortens its DHCP range, so no more devices than that can join.</p>
      <p>Each network's gateway is <span class="mono">.1</span>. On management, test and LAN, addresses below <span class="mono">.64</span> are kept for fixed IPs.</p>
      <p>Hotspot and test users reach only the internet, never each other's networks. Only management and LAN can open Winbox on the router.</p>
    @else
      <p>The address plan is full. Widen <span class="mono">HOTSPOT_SUPERNET</span> (or the management, test and LAN plans) before adding more routers.</p>
    @endif
  </aside>
</form>

{{-- One hotspot network. __ID__ is replaced with a unique number; the server re-numbers them. --}}
<template id="network-template">
  <div class="net-card" data-uid="__ID__">
    <div class="net-head">
      <strong class="net-title">Hotspot network</strong>
      <span class="net-subnet mono"></span>
      <button type="button" class="link-btn net-remove">Remove</button>
    </div>
    <div class="row3">
      <div class="field">
        <label for="net-__ID__-name">Name</label>
        <input id="net-__ID__-name" type="text" class="net-name" name="networks[__ID__][name]" maxlength="60" required placeholder="Public WiFi">
      </div>
      <div class="field">
        <label for="net-__ID__-vlan">VLAN ID</label>
        <input id="net-__ID__-vlan" type="number" class="net-vlan" name="networks[__ID__][vlan_id]" min="2" max="4094" required>
      </div>
      <div class="field">
        <label for="net-__ID__-iface">Interface name</label>
        <input id="net-__ID__-iface" type="text" class="mono net-iface" name="networks[__ID__][interface]" maxlength="32" required>
      </div>
    </div>
    <div class="row3 net-address">
      <div class="field">
        <label for="net-__ID__-prefix">Size</label>
        <select id="net-__ID__-prefix" class="net-prefix" name="networks[__ID__][prefix]">
          @foreach ($sizes as $p => $label)<option value="{{ $p }}">{{ $label }}</option>@endforeach
        </select>
      </div>
      <div class="field">
        <label for="net-__ID__-limit">User limit</label>
        <input id="net-__ID__-limit" type="number" class="net-limit" name="networks[__ID__][max_users]" min="{{ \App\Services\Mikrotik\SubnetAllocator::MIN_USER_LIMIT }}" placeholder="No limit">
      </div>
      <div class="field">
        <label for="net-__ID__-subnet">Network address <span class="hint">(optional)</span></label>
        <input id="net-__ID__-subnet" type="text" class="mono net-subnet-input" name="networks[__ID__][subnet]" maxlength="18" placeholder="Automatic">
      </div>
    </div>
    <p class="hint net-capacity" style="margin:-8px 0 14px"></p>
    <div class="field">
      <label for="net-__ID__-mode">Login page</label>
      <select id="net-__ID__-mode" class="net-mode" name="networks[__ID__][login_mode]">
        @foreach (\App\Models\HotspotNetwork::LOGIN_MODES as $value => $label)
          <option value="{{ $value }}">{{ $label }}</option>
        @endforeach
      </select>
    </div>
    <div class="row net-designs">
      <div class="field">
        <label for="net-__ID__-login-page">Login page design</label>
        <select id="net-__ID__-login-page" class="net-login-page" name="networks[__ID__][login_page_id]">
          @foreach ($designs as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
        </select>
      </div>
      <div class="field">
        <label for="net-__ID__-ad-page">Advertisement design</label>
        <select id="net-__ID__-ad-page" class="net-ad-page" name="networks[__ID__][ad_page_id]">
          @foreach ($designs as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
        </select>
      </div>
    </div>
    <div class="field net-url" hidden>
      <label for="net-__ID__-url">External login page URL</label>
      <input id="net-__ID__-url" type="text" class="mono" name="networks[__ID__][login_url]" placeholder="https://portal.example.com/login">
      <p class="hint">Receives <span class="mono">mac</span>, <span class="mono">ip</span>, <span class="mono">link-login-only</span>, <span class="mono">link-orig</span>, <span class="mono">chap-id</span>, <span class="mono">chap-challenge</span>, <span class="mono">error</span> and <span class="mono">server-name</span> as query parameters. Its host is allowed before login.</p>
    </div>
    <div class="net-errors"></div>
  </div>
</template>

<script>
(function () {
  const models = @json(collect($models)->map(fn ($m) => $m['ports']));
  const oldRoles = @json((object) old('ports', []));
  const oldNetworks = @json((object) old('networks', []));
  const networkErrors = @json($networkErrors);
  const nextHotspot = @json(array_column($nextHotspot, 'subnet'));
  const defaultPrefix = @json($defaultPrefix);
  const minLimit = @json(\App\Services\Mikrotik\SubnetAllocator::MIN_USER_LIMIT);
  const capacity = (prefix) => Math.pow(2, 32 - prefix) - 3;
  const fmt = (n) => n.toLocaleString('en-US');
  const maxNetworks = @json($maxNetworks);
  const defaultDesign = @json(optional($designs->first())->id);
  const hotspotDefault = @json($vlanDefaults['hotspot']);
  const detectUrl = @json(route('routers.detect'));
  const token = document.querySelector('meta[name=csrf-token]').content;
  const COLORS = ['#0E7C66', '#C2410C', '#7E22CE', '#0369A1', '#B45309', '#BE185D', '#4D7C0F', '#475569'];

  const $ = (id) => document.getElementById(id);
  const select = $('model'), rows = $('port-rows'), head = $('port-head'), front = $('front'), summary = $('port-summary');
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

  /* ---------- Hotspot networks ---------- */

  const list = $('networks'), template = $('network-template').innerHTML;
  let nextUid = 0;

  // {uid, name, vlan, color} for every card, in order
  function networks() {
    return [...list.querySelectorAll('.net-card')].map((card, i) => ({
      uid: card.dataset.uid,
      name: card.querySelector('.net-name').value.trim() || 'Network ' + (i + 1),
      vlan: card.querySelector('.net-vlan').value.trim(),
      color: COLORS[i % COLORS.length],
    }));
  }

  function usedVlans() {
    return [...document.querySelectorAll('.net-vlan, input[id$="-id"][data-net]')].map((i) => +i.value).filter(Boolean);
  }

  function addNetwork(values) {
    const uid = String(values.uid ?? nextUid);
    nextUid = Math.max(nextUid, +uid + 1);
    const wrap = document.createElement('div');
    wrap.innerHTML = template.replaceAll('__ID__', uid).trim();
    const card = wrap.firstElementChild;
    list.appendChild(card);

    card.querySelector('.net-name').value = values.name ?? '';
    card.querySelector('.net-vlan').value = values.vlan_id ?? '';
    card.querySelector('.net-iface').value = values.interface ?? '';
    card.querySelector('.net-mode').value = values.login_mode ?? 'portal';
    card.querySelector('.net-login-page').value = values.login_page_id ?? defaultDesign;
    card.querySelector('.net-ad-page').value = values.ad_page_id ?? defaultDesign;
    card.querySelector('.net-url input').value = values.login_url ?? '';
    card.querySelector('.net-prefix').value = values.prefix ?? defaultPrefix;
    card.querySelector('.net-limit').value = values.max_users ?? '';
    card.querySelector('.net-subnet-input').value = values.subnet ?? '';

    // Server-side errors for this card
    const errors = Object.entries(networkErrors).filter(([key]) => key.startsWith('networks.' + uid + '.'));
    const errBox = card.querySelector('.net-errors');
    errors.forEach(([key, messages]) => {
      const p = document.createElement('p');
      p.className = 'error';
      p.textContent = messages[0];
      errBox.appendChild(p);
      const field = key.split('.').pop();
      const input = card.querySelector('[name$="[' + field + ']"]');
      if (input) input.setAttribute('aria-invalid', 'true');
    });

    card.querySelector('.net-mode').addEventListener('change', () => { syncCard(card); });
    card.querySelector('.net-vlan').addEventListener('input', (e) => {
      const iface = card.querySelector('.net-iface');
      const m = iface.value.match(/^vlan\d*-(.+)$/);
      if (m && /^\d+$/.test(e.target.value)) iface.value = 'vlan' + e.target.value + '-' + m[1];
      networksChanged();
    });
    card.querySelector('.net-name').addEventListener('input', networksChanged);
    // A typed address sets the size; the size and limit update the capacity line.
    card.querySelector('.net-subnet-input').addEventListener('input', (e) => {
      const m = /\/(\d{1,2})\s*$/.exec(e.target.value);
      const sel = card.querySelector('.net-prefix');
      if (m && sel.querySelector('option[value="' + m[1] + '"]')) sel.value = m[1];
      networksChanged();
    });
    card.querySelector('.net-prefix').addEventListener('change', networksChanged);
    card.querySelector('.net-limit').addEventListener('input', networksChanged);
    card.querySelector('.net-remove').addEventListener('click', () => {
      card.remove();
      networksChanged();
    });
    syncCard(card);
    return card;
  }

  function syncCard(card) {
    const mode = card.querySelector('.net-mode').value;
    card.querySelector('.net-designs').hidden = mode !== 'portal';
    card.querySelector('.net-url').hidden = mode !== 'custom';
  }

  // "Automatic /20" or the typed address, and how many users it will take.
  function addressOf(card) {
    const typed = card.querySelector('.net-subnet-input').value.trim();
    const prefix = +card.querySelector('.net-prefix').value;
    const limit = +card.querySelector('.net-limit').value || null;
    const cap = capacity(prefix);
    const users = limit ? fmt(limit) + ' users (limit)' : 'up to ' + fmt(cap) + ' users, no limit';
    let warn = '';
    if (limit && (limit < minLimit || limit > cap)) {
      warn = cap < minLimit
        ? ' A /' + prefix + ' is too small for a limit; leave it empty.'
        : ' The limit must be ' + fmt(minLimit) + ' to ' + fmt(cap) + '.';
    }
    return { label: typed || 'automatic /' + prefix, users, warn };
  }

  function newNetwork() {
    const taken = usedVlans();
    let vlan = hotspotDefault.id;
    while (taken.includes(vlan)) vlan++;
    const n = list.children.length + 1;
    const card = addNetwork({
      name: n === 1 ? 'Public WiFi' : 'Hotspot ' + n,
      vlan_id: vlan,
      interface: n === 1 ? hotspotDefault.name.replace(/^vlan\d*/, 'vlan' + vlan) : 'vlan' + vlan + '-hotspot' + n,
    });
    networksChanged();
    card.querySelector('.net-name').focus();
    card.querySelector('.net-name').select();
  }
  $('add-network').addEventListener('click', newNetwork);

  // Cards, port columns, front panel and aside all follow the network list.
  function networksChanged() {
    const nets = networks();
    const cards = [...list.querySelectorAll('.net-card')];
    cards.forEach((card, i) => {
      const a = addressOf(card);
      card.style.setProperty('--net-color', nets[i].color);
      card.querySelector('.net-title').textContent = nets[i].name;
      card.querySelector('.net-subnet').textContent = a.label;
      card.querySelector('.net-capacity').textContent = 'Gateway .1, DHCP for ' + a.users + '.' + a.warn;
      card.querySelector('.net-capacity').style.color = a.warn ? 'var(--fail)' : '';
      card.querySelector('.net-remove').hidden = nets.length === 1;
    });
    $('add-network').hidden = nets.length >= maxNetworks;

    const aside = $('hs-aside');
    if (aside) {
      aside.replaceChildren(...nets.flatMap((n, i) => {
        const a = addressOf(cards[i]);
        const dt = document.createElement('dt'), dd = document.createElement('dd');
        dt.textContent = n.name;
        dd.innerHTML = '<span class="mono"></span><br><small></small>';
        dd.querySelector('.mono').textContent = a.label + (n.vlan ? ', VLAN ' + n.vlan : '');
        dd.querySelector('small').textContent = a.users;
        return [dt, dd];
      }));
    }
    render(currentRoles());
  }

  /* ---------- Ports ---------- */

  let detected = null;
  try { detected = JSON.parse(detectedInput.value || 'null'); } catch (e) {}

  const typeOf = (n) => /^q?sfp/.test(n) ? 'SFP' : /^combo/.test(n) ? 'Combo' : 'Ethernet';
  const short = (n) => n.replace(/^ether/, '').replace(/^sfp-sfpplus/, 'S+').replace(/^sfp28-/, 'S28-')
                        .replace(/^q?sfp/, 'S').replace(/^combo/, 'C').replace(/^(wlan|wifi)/, 'W');

  // Role values in the form: wan, trunk, access:<vlan>, lan, none.
  // Internally a hotspot port is "net:<uid>", so it survives VLAN ID edits.
  function roleColumns() {
    return [
      ['wan', 'WAN'], ['trunk', 'Trunk'],
      ...networks().map((n) => ['net:' + n.uid, 'Hotspot: ' + n.name, n]),
      ['lan', 'LAN'], ['none', 'Not used'],
    ];
  }

  function currentPorts() {
    if (select.value === 'detected' && detected) {
      return detected.ports.map((p) => ({ name: p.name, type: p.type === 'wireless' ? 'Wireless' : typeOf(p.name), bridge: p.bridge, api: p.api }));
    }
    return (models[select.value] || []).map((n) => ({ name: n, type: typeOf(n) }));
  }

  // Sensible start: the port the dashboard connects through (or the first) is WAN,
  // wired ports are trunks, wireless interfaces carry the first hotspot network untagged.
  function defaults(ports) {
    const roles = {};
    const first = networks()[0];
    const direct = ports.find((p) => p.api && !p.bridge);
    const wan = direct ? direct.name : (ports[0] && ports[0].name);
    ports.forEach((p) => {
      roles[p.name] = p.name === wan ? 'wan' : (p.type === 'Wireless' && first ? 'net:' + first.uid : 'trunk');
    });
    return roles;
  }

  // Saved roles ("access:10", or plain "access" for the first network) to internal ones.
  function fromSaved(saved) {
    const nets = networks(), roles = {};
    Object.entries(saved).forEach(([port, role]) => {
      if (role === 'access') role = nets[0] ? 'net:' + nets[0].uid : 'none';
      const m = /^access:(\d+)$/.exec(role);
      if (m) {
        const n = nets.find((x) => x.vlan === m[1]);
        role = n ? 'net:' + n.uid : 'none';
      }
      roles[port] = role;
    });
    return roles;
  }

  function render(roles) {
    const ports = currentPorts();
    const columns = roleColumns();
    const vlanOf = Object.fromEntries(networks().map((n) => ['net:' + n.uid, n.vlan]));

    head.innerHTML = '<th scope="col" style="text-align:left">Port</th>';
    columns.forEach(([, label, net]) => {
      const th = document.createElement('th');
      th.scope = 'col';
      th.textContent = label;
      if (net) th.style.color = net.color;
      head.appendChild(th);
    });
    $('key').innerHTML = '';
    [['#E0A43A', 'WAN, internet'], ['repeating-linear-gradient(135deg,var(--signal) 0 3px,#7E5BB5 3px 6px)', 'Trunk, all VLANs tagged'],
      ...networks().map((n) => [n.color, n.name + ', untagged']), ['#3D7CC9', 'LAN, untagged office network'], ['#3B4D58', 'Not used']]
      .forEach(([bg, label]) => {
        const s = document.createElement('span');
        s.innerHTML = '<i></i>';
        s.firstChild.style.background = bg;
        s.append(label);
        $('key').appendChild(s);
      });

    rows.innerHTML = '';
    if (!ports.length) {
      rows.innerHTML = '<tr><td colspan="' + (columns.length + 1) + '" class="hint" style="text-align:left">Choose a model, or read the ports from the router.</td></tr>';
      return update();
    }
    roles = roles && Object.keys(roles).length ? roles : defaults(ports);
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
      const current = columns.some(([v]) => v === roles[p.name]) ? roles[p.name] : 'none';
      columns.forEach(([value, label]) => {
        const td = document.createElement('td');
        const input = document.createElement('input');
        input.type = 'radio';
        input.name = 'ports[' + p.name + ']';
        input.value = value.startsWith('net:') ? 'access:' + vlanOf[value] : value;
        input.dataset.role = value;
        input.checked = current === value;
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
    rows.querySelectorAll('input[type=radio]:checked').forEach((i) => { r[i.name.slice(6, -1)] = i.dataset.role; });
    return r;
  }

  function update() {
    const r = currentRoles();
    const nets = networks();
    const netOf = Object.fromEntries(nets.map((n) => ['net:' + n.uid, n]));
    front.innerHTML = '';
    const by = { wan: [], trunk: [], lan: [], none: [] };
    const access = {};
    Object.entries(r).forEach(([name, role]) => {
      const s = document.createElement('span');
      s.textContent = short(name);
      if (netOf[role]) {
        (access[role] = access[role] || []).push(name);
        s.style.background = netOf[role].color;
      } else {
        by[role].push(name);
        s.className = role;
      }
      front.appendChild(s);
    });
    const accessCount = Object.values(access).flat().length;
    const parts = [];
    const ok = by.wan.length === 1 && (by.trunk.length + accessCount) > 0;
    parts.push(by.wan.length ? 'WAN: ' + by.wan.join(', ') + '.' : 'Choose a WAN port.');
    if (by.trunk.length) parts.push('Trunk: ' + by.trunk.join(', ') + '.');
    Object.entries(access).forEach(([role, ports]) => parts.push(netOf[role].name + ' untagged: ' + ports.join(', ') + '.'));
    if (!by.trunk.length && !accessCount) parts.push('Choose at least one trunk or hotspot port.');
    parts.push(by.lan.length ? 'LAN: ' + by.lan.join(', ') + '.' : 'No LAN.');
    summary.textContent = parts.join(' ');
    summary.style.color = ok ? '' : 'var(--fail)';
    if ($('lan-aside')) $('lan-aside').hidden = !by.lan.length;
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

  document.querySelectorAll('input[name=wan_mode]').forEach((r) => r.addEventListener('change', () => {
    $('wan-static').hidden = document.querySelector('input[name=wan_mode]:checked').value !== 'static';
  }));

  const ssl = $('use_ssl'), port = $('api_port');
  const plain = '{{ config('hotspot.api.port') }}', secure = '{{ config('hotspot.api.ssl_port') }}';
  ssl.addEventListener('change', () => {
    if ([plain, secure, ''].includes(port.value)) port.value = ssl.checked ? secure : plain;
  });

  // Start: the networks from a failed submit, or one "Public WiFi" network.
  const saved = Object.entries(oldNetworks);
  if (saved.length) {
    saved.forEach(([uid, values]) => addNetwork({ ...values, uid }));
  } else {
    addNetwork({ name: 'Public WiFi', vlan_id: hotspotDefault.id, interface: hotspotDefault.name });
  }
  networksChanged();
  render(Object.keys(oldRoles).length ? fromSaved(oldRoles) : null);
})();
</script>
@endsection
