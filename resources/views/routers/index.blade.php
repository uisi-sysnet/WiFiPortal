@extends('layouts.app')

@section('title', 'Routers | Public WiFi Control')

@section('content')
<div class="page-head">
  <div>
    <h1>Routers</h1>
    <p class="lede">Each MikroTik runs a hotspot on its own subnet. Users sign in once through RADIUS and can roam to any site.</p>
  </div>
  <a class="btn" href="{{ route('routers.create') }}">Add router</a>
</div>

@if (session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif

@php
  $used = array_flip($plan['used_indexes']);
  $nextFree = null;
  for ($i = 0; $i < $plan['blocks']; $i++) { if (! isset($used[$i])) { $nextFree = $i; break; } }
@endphp
<section class="plan" aria-labelledby="plan-title">
  <h2 id="plan-title">Address plan</h2>
  <p>
    <span class="mono">{{ $plan['supernet'] }}</span> split into /{{ $plan['site_prefix'] }} blocks.
    <strong>{{ number_format(count($used)) }} of {{ number_format($plan['blocks']) }}</strong> blocks in use.
    Each router serves up to {{ number_format($plan['hosts_per_block']) }} devices, so the full plan holds
    {{ number_format($plan['total_hosts']) }} addresses.
  </p>
  <div class="plan-grid" role="img" aria-label="{{ count($used) }} of {{ $plan['blocks'] }} address blocks used">
    @for ($i = 0; $i < $plan['blocks']; $i++)
      <span @class(['used' => isset($used[$i]), 'next' => $i === $nextFree])></span>
    @endfor
  </div>
  <div class="legend"><span><i class="used"></i>In use</span><span><i class="next"></i>Next router</span><span><i></i>Free</span></div>
</section>

@if ($routers->isEmpty())
  <div class="plan">
    <h2>No routers yet</h2>
    <p>Add your first MikroTik. You need its IP address, an API user, and which ports face the internet and the users.</p>
    <p style="margin-top:14px"><a class="btn" href="{{ route('routers.create') }}">Add router</a></p>
  </div>
@else
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Router</th><th>Status</th><th>Client subnet</th><th>API address</th><th>Model</th><th>Configured</th></tr>
      </thead>
      <tbody>
      @foreach ($routers as $router)
        <tr>
          <td><a href="{{ route('routers.show', $router) }}">{{ $router->name }}</a>@if($router->location)<small>{{ $router->location }}</small>@endif</td>
          <td><span class="status {{ $router->status }}">{{ $router->statusLabel() }}</span></td>
          <td class="mono">{{ $router->subnet }}</td>
          <td class="mono">{{ $router->host }}:{{ $router->api_port }}</td>
          <td>{{ $router->board_name ?? 'Unknown' }}<small>{{ $router->ros_version ? 'RouterOS '.$router->ros_version : '' }}</small></td>
          <td>{{ $router->provisioned_at?->diffForHumans() ?? 'Not yet' }}</td>
        </tr>
      @endforeach
      </tbody>
    </table>
  </div>
  <div style="margin-top:16px">{{ $routers->links() }}</div>
@endif
@endsection
