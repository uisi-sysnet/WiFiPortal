@extends('layouts.app')

@section('title', $router->name.' | Public WiFi Control')

@push('head')
  @if ($router->isBusy())<meta http-equiv="refresh" content="3">@endif
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
          onsubmit="return confirm('Remove {{ $router->name }} from the dashboard? Its subnet goes back to the address plan. The router keeps its current configuration.')">
      @csrf @method('DELETE')
      <button class="btn danger" type="submit">Remove</button>
    </form>
  </div>
</div>

@if (session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif

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
  <div><dt>Login page</dt><dd>{{ $router->loginModeLabel() }}
    @if ($router->login_mode === 'portal')<small style="display:block;font-weight:400"><a href="{{ route('splash.edit') }}">Edit splash page</a></small>
    @elseif ($router->login_mode === 'custom')<small class="mono" style="display:block;font-weight:400;word-break:break-all">{{ $router->login_url }}</small>@endif
  </dd></div>
  <div><dt>WAN</dt><dd><span class="mono">{{ $router->wan_interface }}</span>, {{ match ($router->wan_mode) { 'dhcp' => 'DHCP from ISP', 'static' => $router->wan_address, default => 'existing settings' } }}</dd></div>
</dl>

<h2>Networks</h2>
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
  $roleNames = ['wan' => 'WAN', 'trunk' => 'Trunk (tagged)', 'access' => 'Hotspot (untagged)', 'lan' => 'LAN', 'none' => 'Not used'];
  $roleColors = ['wan' => '#E0A43A', 'trunk' => 'repeating-linear-gradient(135deg,#0E7C66 0 3px,#7E5BB5 3px 6px)', 'access' => '#0E7C66', 'lan' => '#3D7CC9', 'none' => '#B8C2BD'];
@endphp
<h2>Ports</h2>
<div class="table-wrap" style="margin-bottom:28px">
  <table>
    <thead><tr><th>Port</th><th>Role</th><th>Bridge</th></tr></thead>
    <tbody>
    @foreach ($router->port_roles ?? [] as $port => $role)
      <tr>
        <td class="mono">{{ $port }}</td>
        <td><span style="display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:8px;background:{{ $roleColors[$role] ?? '#B8C2BD' }}"></span>{{ $roleNames[$role] ?? $role }}</td>
        <td class="mono">{{ match ($role) { 'lan' => 'bridge-lan', 'trunk', 'access' => 'bridge-trunk', default => '' } }}</td>
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
@endsection
