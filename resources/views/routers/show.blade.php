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
  <div><dt>Client subnet</dt><dd class="mono">{{ $router->subnet }}</dd></div>
  <div><dt>Gateway</dt><dd class="mono">{{ $router->gateway }}</dd></div>
  <div><dt>DHCP pool</dt><dd class="mono">{{ $router->pool_start }} to {{ $router->pool_end }}</dd></div>
  <div><dt>API</dt><dd class="mono">{{ $router->host }}:{{ $router->api_port }}{{ $router->use_ssl ? ' (SSL)' : '' }}</dd></div>
  <div><dt>Interfaces</dt><dd class="mono">WAN {{ $router->wan_interface }}, hotspot {{ $router->hotspot_interface }}</dd></div>
  <div><dt>Model</dt><dd>{{ $router->board_name ?? 'Unknown' }}</dd></div>
  <div><dt>RouterOS</dt><dd>{{ $router->ros_version ?? 'Unknown' }}</dd></div>
</dl>

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
