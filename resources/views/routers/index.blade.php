@extends('layouts.app')

@section('title', 'Routers | Public WiFi Control')

@section('content')
<div class="page-head">
  <div>
    <h1>Routers</h1>
    <p class="lede">Each MikroTik runs one or more hotspot networks, each on its own subnet. Users sign in once through RADIUS and can roam to any site.</p>
  </div>
  <a class="btn" href="{{ route('routers.create') }}">Add router</a>
</div>

@if (session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif

@php
  $used = array_flip($plan['used_indexes']);
  $nextFree = null;
  for ($i = 0; $i < $plan['blocks']; $i++) { if (! isset($used[$i])) { $nextFree = $i; break; } }
  $nextSubnet = $nextFree === null ? null
    : long2ip(ip2long(strtok($plan['supernet'], '/')) + $nextFree * (1 << (32 - $plan['site_prefix']))).'/'.$plan['site_prefix'];
  $usedPct = $plan['blocks'] ? count($used) / $plan['blocks'] * 100 : 0;
@endphp
<section class="plan" aria-labelledby="plan-title">
  <h2 id="plan-title">Address plan</h2>
  <p>
    <span class="mono">{{ $plan['supernet'] }}</span> in /{{ $plan['site_prefix'] }} blocks:
    <strong>{{ number_format(count($used)) }} of {{ number_format($plan['blocks']) }}</strong> in use
    ({{ number_format($plan['hosts_per_block']) }} devices each).
    Next network: <span class="mono">{{ $nextSubnet ?? 'none free' }}</span>
  </p>
  <div role="img" aria-label="{{ count($used) }} of {{ $plan['blocks'] }} address blocks used"
       style="height:8px;margin-top:10px;background:#DCE2DF;border-radius:4px;overflow:hidden">
    <div style="height:100%;width:{{ count($used) ? max($usedPct, 1) : 0 }}%;background:var(--signal)"></div>
  </div>
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
        <tr><th>Router</th><th>Status</th><th>Hotspot networks</th><th>Load</th><th>API address</th><th>Model</th><th>Configured</th></tr>
      </thead>
      <tbody>
      @foreach ($routers as $router)
        <tr>
          <td><a href="{{ route('routers.show', $router) }}">{{ $router->name }}</a>@if($router->location)<small>{{ $router->location }}</small>@endif</td>
          <td><span class="status {{ $router->status }}">{{ $router->statusLabel() }}</span></td>
          <td>@foreach ($router->hotspotNetworks as $n)<span class="mono">{{ $n->subnet }}</span> <small style="display:inline">{{ $n->name }}</small>@if(! $loop->last)<br>@endif @endforeach</td>
          @php
            $pctUsers = $router->usersPercent();
            $tone = $pctUsers === null ? '#8a9a93' : ($pctUsers >= config('hotspot.capacity.full') ? '#B3372E' : ($pctUsers >= config('hotspot.capacity.busy') ? '#D97706' : '#0e670d'));
          @endphp
          <td>
            @if ($pctUsers === null)
              <span style="color:#8a9a93">Not known</span>
            @else
              <b style="color:{{ $tone }}">{{ rtrim(rtrim(number_format($pctUsers, 1), '0'), '.') }}%</b>
              <small>{{ number_format($router->active_users) }} of {{ number_format($router->ratedUsers()) }} users{{ $router->cpu_avg !== null ? ', CPU '.round($router->cpu_avg).'%' : '' }}</small>
            @endif
          </td>
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
