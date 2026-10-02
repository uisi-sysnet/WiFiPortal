{{--
  Capacity of one router: users vs its rating, CPU, memory, and each hotspot
  network's address pool, with open alerts. Readings come from routers:poll.
  Needs: $router (with hotspotNetworks), $alerts (open CapacityAlerts for it).
--}}
@php
  $cap = config('hotspot.capacity');
  $level = fn (?float $v, int $busy, int $full) => $v === null ? 'none' : ($v >= $full ? 'full' : ($v >= $busy ? 'busy' : 'ok'));
  $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.');
  $gb = fn ($bytes) => $bytes === null ? '?' : $num($bytes / 1073741824).' GB';
  $users = $router->usersPercent();
  $ratingSource = $router->rated_users ? 'set here' : ($router->modelRating() ? 'from the '.$router->modelLabel().' model' : 'default for unknown models');
@endphp

<section class="cap" id="capacity" aria-labelledby="cap-title">
  <h2 id="cap-title">Capacity</h2>

  @foreach ($alerts as $a)
    <div class="cap-alert {{ $a->level }}" role="alert">
      <b>{{ $a->level === 'full' ? 'Full' : 'Busy' }}:</b> {{ $a->message }}
      <small>Since {{ $a->opened_at->diffForHumans() }}</small>
    </div>
  @endforeach

  @if ($router->link_status !== 'online')
    <p class="hint">No live readings: the router {{ $router->link_status === 'offline' ? 'is not answering' : 'has not been checked yet' }}.</p>
  @endif

  <div class="cap-grid">
    <div class="cap-card {{ $level($users, $cap['busy'], $cap['full']) }}">
      <p class="cap-label">Users online</p>
      <p class="cap-num">{{ $router->active_users === null ? '–' : number_format($router->active_users) }} <small>of {{ number_format($router->ratedUsers()) }} rated</small></p>
      <div class="cap-bar"><span style="width:{{ min(100, $users ?? 0) }}%"></span></div>
      <p class="cap-sub">{{ $users === null ? 'Not known' : $num($users).'% of rated capacity' }}</p>
    </div>
    <div class="cap-card {{ $level($router->cpu_avg, $cap['cpu_busy'], $cap['cpu_full']) }}">
      <p class="cap-label">CPU</p>
      <p class="cap-num">{{ $router->cpu_avg === null ? '–' : $num($router->cpu_avg).'%' }} <small>average</small></p>
      <div class="cap-bar"><span style="width:{{ min(100, $router->cpu_avg ?? 0) }}%"></span></div>
      <p class="cap-sub">{{ $router->cpu_load === null ? 'Not known' : 'Now '.$router->cpu_load.'%. Busy from '.$cap['cpu_busy'].'%' }}</p>
    </div>
    <div class="cap-card {{ $level($router->memoryPercent(), 85, 95) }}">
      <p class="cap-label">Memory</p>
      <p class="cap-num">{{ $router->memoryPercent() === null ? '–' : $num($router->memoryPercent()).'%' }} <small>used</small></p>
      <div class="cap-bar"><span style="width:{{ min(100, $router->memoryPercent() ?? 0) }}%"></span></div>
      <p class="cap-sub">{{ $router->total_memory ? $gb($router->total_memory - $router->free_memory).' of '.$gb($router->total_memory) : 'Not known' }}</p>
    </div>
  </div>

  <div class="table-wrap cap-pools">
    <table>
      <thead><tr><th>Hotspot network</th><th>Users online</th><th>Addresses in use</th><th style="width:34%">Address pool</th></tr></thead>
      <tbody>
      @foreach ($router->hotspotNetworks as $n)
        @php $p = $n->poolPercent(); @endphp
        <tr>
          <td>{{ $n->name }} <small>VLAN {{ $n->vlan_id }}, <span class="mono">{{ $n->subnet }}</span></small></td>
          <td>{{ $n->active_users === null ? '–' : number_format($n->active_users) }}</td>
          <td>{{ $n->leases === null ? '–' : number_format($n->leases) }} <small>of {{ number_format($n->poolSize()) }}</small></td>
          <td>
            <div class="cap-bar {{ $level($p, $cap['busy'], $cap['full']) }}"><span style="width:{{ min(100, $p ?? 0) }}%"></span></div>
            <small>{{ $p === null ? 'Not known' : $num($p).'% used' }}</small>
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
  </div>

  <form class="cap-rating" method="POST" action="{{ route('routers.capacity', $router) }}">
    @csrf @method('PUT')
    <label for="rated_users">Rated users</label>
    <input id="rated_users" name="rated_users" type="number" min="1" max="100000"
           value="{{ old('rated_users', $router->rated_users) }}" placeholder="{{ $router->modelRating() ?? config('hotspot.capacity.default_users') }}">
    <button class="cap-btn" type="submit">Save</button>
    <span class="hint">
      Now {{ number_format($router->ratedUsers()) }} ({{ $ratingSource }}). How many hotspot users this router handles at once with CPU around 70%;
      load-test one unit of each model and set it here. Leave empty to use the model's figure. Busy from {{ $cap['busy'] }}%, full from {{ $cap['full'] }}%.
    </span>
    @error('rated_users')<p class="error">{{ $message }}</p>@enderror
  </form>
</section>
