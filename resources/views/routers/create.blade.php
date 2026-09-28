@extends('layouts.app')

@section('title', 'Add router | Public WiFi Control')

@section('content')
<div class="page-head">
  <div>
    <h1>Add router</h1>
    <p class="lede">We connect to the MikroTik, check the interfaces, then apply the hotspot setup: bridge, DHCP, DNS, NAT, RADIUS and the login page.</p>
  </div>
</div>

@if ($errors->any())
  <div class="alert" role="alert">Fix the highlighted fields and try again.</div>
@endif

@php
  $err = fn ($f) => $errors->has($f) ? 'aria-invalid=true aria-describedby='.$f.'-error' : '';
@endphp

<form method="POST" action="{{ route('routers.store') }}" class="form-layout" novalidate>
  @csrf
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
    </fieldset>

    <fieldset>
      <legend>Interfaces</legend>
      <div class="row">
        <div class="field">
          <label for="wan_interface">Internet (WAN)</label>
          <input id="wan_interface" name="wan_interface" type="text" value="{{ old('wan_interface', 'ether1') }}" required class="mono" {!! $err('wan_interface') !!}>
          @error('wan_interface')<p class="error" id="wan_interface-error">{{ $message }}</p>@enderror
        </div>
        <div class="field">
          <label for="hotspot_interface">Hotspot (users)</label>
          <input id="hotspot_interface" name="hotspot_interface" type="text" value="{{ old('hotspot_interface', 'ether2') }}" required class="mono" {!! $err('hotspot_interface') !!}>
          @error('hotspot_interface')<p class="error" id="hotspot_interface-error">{{ $message }}</p>@enderror
        </div>
      </div>
      <p class="hint" style="margin:-6px 0 18px">The hotspot port is moved into a new bridge. Don't pick the port you manage the router through.</p>
    </fieldset>

    <div class="actions">
      <button class="btn" type="submit">Add and configure router</button>
      <a class="btn quiet" href="{{ route('routers.index') }}">Cancel</a>
    </div>
  </div>

  <aside class="aside" aria-labelledby="alloc-title">
    <h2 id="alloc-title">This router will get</h2>
    @if ($next)
      <dl>
        <dt>Subnet</dt><dd class="mono">{{ $next['subnet'] }}</dd>
        <dt>Gateway</dt><dd class="mono">{{ $next['gateway'] }}</dd>
        <dt>DHCP pool</dt><dd class="mono">{{ $next['pool_start'] }}<br>{{ $next['pool_end'] }}</dd>
        <dt>Devices</dt><dd>up to {{ number_format($plan['hosts_per_block']) }}</dd>
        <dt>Per user</dt><dd class="mono">{{ config('hotspot.rate_limit') }}</dd>
        <dt>Login page</dt><dd class="mono">{{ config('hotspot.dns_name') }}</dd>
        <dt>RADIUS</dt><dd>{{ config('hotspot.radius.host') ? config('hotspot.radius.host') : 'Not set' }}</dd>
      </dl>
      <p>Allocated automatically from {{ $plan['supernet'] }}. The exact block is confirmed when you save.</p>
    @else
      <p>The address plan is full. Widen <span class="mono">HOTSPOT_SUPERNET</span> before adding more routers.</p>
    @endif
  </aside>
</form>

<script>
  // Switch the default port when API-SSL is toggled, unless the user typed their own.
  (function () {
    var ssl = document.getElementById('use_ssl'), port = document.getElementById('api_port');
    var plain = '{{ config('hotspot.api.port') }}', secure = '{{ config('hotspot.api.ssl_port') }}';
    ssl.addEventListener('change', function () {
      if (port.value === plain || port.value === secure || port.value === '') port.value = ssl.checked ? secure : plain;
    });
  })();
</script>
@endsection
