@extends('layouts.app')

@section('title', $router->name.' | Public WiFi Control')

@push('head')
  @if ($router->isBusy())<meta http-equiv="refresh" content="3">@endif
<style>
select{font:inherit;width:100%;padding:8px 10px;border:1px solid #A7B4AD;border-radius:4px;background:#fff;color:var(--ink)}
.nets{display:grid;gap:14px;margin-bottom:28px}
.net{border:1px solid var(--line);border-left:5px solid var(--net-color);background:var(--panel);border-radius:var(--radius);padding:16px 18px 4px}
.net-top{display:flex;flex-wrap:wrap;gap:6px 18px;align-items:baseline;margin-bottom:12px}
.net-top h3{margin:0;font-size:1.05rem}
.net-top span{font-size:.88rem;color:var(--ink-2)}
.net-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
@media (max-width:820px){.net-grid{grid-template-columns:1fr}}
.net .field{margin-bottom:12px}
.net-foot{display:flex;flex-wrap:wrap;gap:10px 16px;align-items:center;margin-bottom:12px}
.net-foot a{font-size:.88rem;word-break:break-all}
details.add{border:1px dashed var(--line);border-radius:var(--radius);padding:12px 18px;margin-bottom:28px;background:var(--panel)}
details.add summary{cursor:pointer;font-weight:600}
details.add[open] summary{margin-bottom:14px}
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1>{{ $router->name }}</h1>
    <p class="lede">{{ $router->location ?: 'No location set' }}</p>
  </div>
  <div class="actions">
    <form method="POST" action="{{ route('routers.provision', $router) }}">
      @csrf
      <button class="btn quiet" type="submit" @disabled($router->isBusy())>Re-apply configuration</button>
    </form>
    <form method="POST" action="{{ route('routers.destroy', $router) }}"
          onsubmit="return confirm('Remove {{ $router->name }} from the dashboard? Its subnets go back to the address plan. The router keeps its current configuration.')">
      @csrf @method('DELETE')
      <button class="btn danger" type="submit">Remove</button>
    </form>
  </div>
</div>

@if (session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
@if ($errors->any())
  <div class="alert" role="alert">
    @foreach ($errors->all() as $message){{ $message }}@if(! $loop->last)<br>@endif @endforeach
  </div>
@endif

@if ($router->status === 'failed')
  <div class="alert" role="alert">
    <strong>Configuration failed.</strong> {{ $router->last_error }}
    <br>Fix the cause on the router or its API user, then use Re-apply configuration. Steps that already ran are safe to repeat.
  </div>
@endif

<dl class="facts">
  <div><dt>Status</dt><dd><span class="status {{ $router->status }}">{{ $router->statusLabel() }}</span></dd></div>
  <div><dt>Model</dt><dd>{{ $router->modelLabel() }}</dd></div>
  <div><dt>RouterOS</dt><dd>{{ $router->ros_version ?? 'Unknown' }}</dd></div>
  <div><dt>API</dt><dd class="mono">{{ $router->host }}:{{ $router->api_port }}{{ $router->use_ssl ? ' (SSL)' : '' }}</dd></div>
  <div><dt>WAN</dt><dd><span class="mono">{{ $router->wan_interface }}</span>, {{ match ($router->wan_mode) { 'dhcp' => 'DHCP from ISP', 'static' => $router->wan_address, default => 'existing settings' } }}</dd></div>
</dl>

@php
  $colors = ['#0E7C66', '#C2410C', '#7E22CE', '#0369A1', '#B45309', '#BE185D', '#4D7C0F', '#475569'];
  $networks = $router->hotspotNetworks;
@endphp

<h2>Hotspot networks</h2>
<p class="hint" style="margin:-4px 0 14px">Each network has its own login and advertisement page. Changing a design takes effect on the next page load. Changing the login page type updates the router.
  <a href="{{ route('splash.edit') }}">Edit captive portal designs</a></p>
<div class="nets">
@foreach ($networks as $i => $n)
  <form class="net" method="POST" action="{{ route('networks.update', $n) }}" style="--net-color:{{ $colors[$i % count($colors)] }}">
    @csrf @method('PUT')
    <div class="net-top">
      <h3>{{ $n->name }}</h3>
      <span>VLAN <span class="mono">{{ $n->vlan_id }}</span>, <span class="mono">{{ $n->interface }}</span></span>
      <span>Gateway <span class="mono">{{ $n->gateway }}</span></span>
      <span>DHCP <span class="mono">{{ $n->pool_start }} to {{ $n->pool_end }}</span></span>
      <span>Users: {{ $n->usersLabel() }}</span>
      <span>Hotspot <span class="mono">{{ $n->serverName() }}</span></span>
    </div>
    <div class="net-grid">
      <div class="field">
        <label for="n{{ $n->id }}-name">Name</label>
        <input id="n{{ $n->id }}-name" name="name" type="text" maxlength="60" value="{{ $n->name }}" required>
      </div>
      <div class="field">
        <label for="n{{ $n->id }}-subnet">Network address</label>
        <input id="n{{ $n->id }}-subnet" name="subnet" type="text" class="mono" maxlength="18" value="{{ $n->subnet }}" required>
        <p class="hint">/16 (65,533 users) to /24 (253 users). Changing it re-applies the router; connected phones get a new address.</p>
      </div>
      <div class="field">
        <label for="n{{ $n->id }}-limit">User limit</label>
        <input id="n{{ $n->id }}-limit" name="max_users" type="number" min="{{ \App\Services\Mikrotik\SubnetAllocator::MIN_USER_LIMIT }}" max="{{ $n->capacity() }}" value="{{ $n->max_users }}" placeholder="No limit">
        <p class="hint">Empty for no limit (up to {{ number_format($n->capacity()) }}), or {{ number_format(\App\Services\Mikrotik\SubnetAllocator::MIN_USER_LIMIT) }} or more.</p>
      </div>
      <div class="field">
        <label for="n{{ $n->id }}-mode">Login page</label>
        <select id="n{{ $n->id }}-mode" name="login_mode" data-mode>
          @foreach (\App\Models\HotspotNetwork::LOGIN_MODES as $value => $label)
            <option value="{{ $value }}" @selected($n->login_mode === $value)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
      <div class="field" data-when="custom" @if($n->login_mode !== 'custom') hidden @endif>
        <label for="n{{ $n->id }}-url">External login page URL</label>
        <input id="n{{ $n->id }}-url" name="login_url" type="text" class="mono" value="{{ $n->login_url }}" placeholder="https://portal.example.com/login">
      </div>
      <div class="field" data-when="portal" @if($n->login_mode !== 'portal') hidden @endif>
        <label for="n{{ $n->id }}-login">Login page design</label>
        <select id="n{{ $n->id }}-login" name="login_page_id">
          @foreach ($designs as $d)<option value="{{ $d->id }}" @selected(($n->login_page_id ?? $designs->first()->id) === $d->id)>{{ $d->name }}</option>@endforeach
        </select>
      </div>
      <div class="field" data-when="portal" @if($n->login_mode !== 'portal') hidden @endif>
        <label for="n{{ $n->id }}-ad">Advertisement design</label>
        <select id="n{{ $n->id }}-ad" name="ad_page_id">
          @foreach ($designs as $d)<option value="{{ $d->id }}" @selected(($n->ad_page_id ?? $designs->first()->id) === $d->id)>{{ $d->name }}</option>@endforeach
        </select>
      </div>
    </div>
    <div class="net-foot">
      <button class="btn quiet sm" type="submit">Save {{ $n->name }}</button>
      @if ($n->login_mode === 'portal')
        <a href="{{ $n->portalUrl() }}" target="_blank" rel="noopener">{{ $n->portalUrl() }}</a>
      @endif
    </div>
  </form>
@endforeach
</div>

@if ($networks->count() < $maxNetworks)
  @php
    $taken = [...$networks->pluck('vlan_id')->all(), ...array_map(fn ($net) => (int) $router->vlan($net)['id'], array_keys(\App\Models\MikrotikRouter::NETWORKS))];
    $vlanId = (int) old('vlan_id', $vlanDefaults['hotspot']['id']);
    while (in_array($vlanId, $taken, true)) { $vlanId++; }
  @endphp
  <details class="add" @if($errors->hasAny(['network', 'vlan_id', 'interface', 'prefix']) || (old('vlan_id') && $errors->hasAny(['subnet', 'max_users', 'name']))) open @endif>
    <summary>Add hotspot network</summary>
    <form method="POST" action="{{ route('routers.networks.store', $router) }}">
      @csrf
      <p class="hint" style="margin:0 0 14px">Gets the next free address of the chosen size @if ($nextHotspot)(next /{{ $defaultPrefix }}: <span class="mono">{{ $nextHotspot['subnet'] }}</span>)@endif unless you type one, and is carried tagged on the trunk ports
        ({{ implode(', ', $router->portsWith('trunk')) ?: 'none yet' }}). Set the same VLAN on your APs or switches. The router is updated right away.</p>
      <div class="net-grid">
        <div class="field">
          <label for="add-name">Name</label>
          <input id="add-name" name="name" type="text" maxlength="60" value="{{ old('name', 'Hotspot '.($networks->count() + 1)) }}" required>
        </div>
        <div class="field">
          <label for="add-vlan">VLAN ID</label>
          <input id="add-vlan" name="vlan_id" type="number" min="2" max="4094" value="{{ $vlanId }}" required>
        </div>
        <div class="field">
          <label for="add-iface">Interface name</label>
          <input id="add-iface" name="interface" type="text" class="mono" maxlength="32" value="{{ old('interface', 'vlan'.$vlanId.'-hotspot'.($networks->count() + 1)) }}" required>
        </div>
        <div class="field">
          <label for="add-prefix">Size</label>
          <select id="add-prefix" name="prefix">
            @foreach ($sizes as $p => $label)<option value="{{ $p }}" @selected((int) old('prefix', $defaultPrefix) === $p)>{{ $label }}</option>@endforeach
          </select>
        </div>
        <div class="field">
          <label for="add-limit">User limit</label>
          <input id="add-limit" name="max_users" type="number" min="{{ \App\Services\Mikrotik\SubnetAllocator::MIN_USER_LIMIT }}" value="{{ old('max_users') }}" placeholder="No limit">
        </div>
        <div class="field">
          <label for="add-subnet">Network address <span class="hint">(optional)</span></label>
          <input id="add-subnet" name="subnet" type="text" class="mono" maxlength="18" value="{{ old('subnet') }}" placeholder="Automatic">
        </div>
        <div class="field">
          <label for="add-mode">Login page</label>
          <select id="add-mode" name="login_mode" data-mode>
            @foreach (\App\Models\HotspotNetwork::LOGIN_MODES as $value => $label)
              <option value="{{ $value }}" @selected(old('login_mode', 'portal') === $value)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
        <div class="field" data-when="custom" @if(old('login_mode', 'portal') !== 'custom') hidden @endif>
          <label for="add-url">External login page URL</label>
          <input id="add-url" name="login_url" type="text" class="mono" value="{{ old('login_url') }}" placeholder="https://portal.example.com/login">
        </div>
        <div class="field" data-when="portal" @if(old('login_mode', 'portal') !== 'portal') hidden @endif>
          <label for="add-login">Login page design</label>
          <select id="add-login" name="login_page_id">
            @foreach ($designs as $d)<option value="{{ $d->id }}" @selected((int) old('login_page_id', $designs->first()->id) === $d->id)>{{ $d->name }}</option>@endforeach
          </select>
        </div>
        <div class="field" data-when="portal" @if(old('login_mode', 'portal') !== 'portal') hidden @endif>
          <label for="add-ad">Advertisement design</label>
          <select id="add-ad" name="ad_page_id">
            @foreach ($designs as $d)<option value="{{ $d->id }}" @selected((int) old('ad_page_id', $designs->first()->id) === $d->id)>{{ $d->name }}</option>@endforeach
          </select>
        </div>
      </div>
      <p style="margin:0 0 12px"><button class="btn" type="submit" @disabled($router->isBusy())>Add network and update router</button></p>
    </form>
  </details>
@endif

<h2>Other networks</h2>
<div class="table-wrap" style="margin-bottom:28px">
  <table>
    <thead><tr><th>Network</th><th>VLAN</th><th>Interface</th><th>Subnet</th><th>Gateway</th><th>DHCP</th></tr></thead>
    <tbody>
    @foreach (\App\Models\MikrotikRouter::NETWORKS as $net => $label)
      @php $v = $router->vlan($net); $n = $router->network($net); @endphp
      <tr>
        <td>{{ $label }}@if($net === 'mgmt' && $router->mgmtNative())<small>untagged on trunks</small>@endif</td>
        <td class="mono">{{ $v['id'] }}</td>
        <td class="mono">{{ $v['name'] }}</td>
        <td class="mono">{{ $n['subnet'] }}</td>
        <td class="mono">{{ $n['gateway'] }}</td>
        <td class="mono">{{ $n['pool_start'] }} to {{ $n['pool_end'] }}</td>
      </tr>
    @endforeach
    @if ($router->hasLan())
      @php $n = $router->network('lan'); @endphp
      <tr>
        <td>LAN</td><td>untagged</td><td class="mono">bridge-lan</td>
        <td class="mono">{{ $n['subnet'] }}</td><td class="mono">{{ $n['gateway'] }}</td>
        <td class="mono">{{ $n['pool_start'] }} to {{ $n['pool_end'] }}</td>
      </tr>
    @endif
    </tbody>
  </table>
</div>

@php
  $roleNames = ['wan' => 'WAN', 'trunk' => 'Trunk (tagged)', 'lan' => 'LAN', 'none' => 'Not used'];
  $roleColors = ['wan' => '#E0A43A', 'trunk' => 'repeating-linear-gradient(135deg,#0E7C66 0 3px,#7E5BB5 3px 6px)', 'lan' => '#3D7CC9', 'none' => '#B8C2BD'];
  $accessOf = [];
  foreach ($networks as $i => $n) {
      foreach ($router->accessPortsFor($n) as $port) {
          $accessOf[$port] = [$n->name.' (untagged)', $colors[$i % count($colors)]];
      }
  }
@endphp
<h2>Ports</h2>
<div class="table-wrap" style="margin-bottom:28px">
  <table>
    <thead><tr><th>Port</th><th>Role</th><th>Bridge</th></tr></thead>
    <tbody>
    @foreach ($router->port_roles ?? [] as $port => $role)
      @php [$label, $color] = $accessOf[$port] ?? [$roleNames[$role] ?? $role, $roleColors[$role] ?? '#B8C2BD']; @endphp
      <tr>
        <td class="mono">{{ $port }}</td>
        <td><span style="display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:8px;background:{{ $color }}"></span>{{ $label }}</td>
        <td class="mono">{{ $role === 'lan' ? 'bridge-lan' : ($role === 'trunk' || isset($accessOf[$port]) ? 'bridge-trunk' : '') }}</td>
      </tr>
    @endforeach
    </tbody>
  </table>
</div>

<h2>Configuration log</h2>
@if ($router->provision_log)
  <ol class="log">
    @foreach ($router->provision_log as $entry)
      <li>{{ $entry['message'] }}<time datetime="{{ $entry['at'] }}">{{ \Illuminate\Support\Carbon::parse($entry['at'])->format('M j, H:i:s') }}</time></li>
    @endforeach
  </ol>
@elseif ($router->isBusy())
  <p class="hint">Waiting for the queue worker. This page refreshes on its own. If it stays here, check that <span class="mono">php artisan queue:work</span> is running.</p>
@else
  <p class="hint">Nothing has been applied yet.</p>
@endif

<script>
// Show the URL field or the design pickers depending on the login page type.
document.querySelectorAll('select[data-mode]').forEach((sel) => {
  sel.addEventListener('change', () => {
    sel.closest('form').querySelectorAll('[data-when]').forEach((el) => { el.hidden = el.dataset.when !== sel.value; });
  });
});
</script>
@endsection
