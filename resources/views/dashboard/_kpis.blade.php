{{--
  Top row of the dashboard: Users online, Routers, Switches, Access points.
  Rendered with the page and again by dashboard.live every few seconds.
  Needs: $k (DashboardController::kpis()).
--}}
@php
  // 40 lights: lit = online, dim = not checked yet, red = offline.
  // Any non-zero group gets at least one light so it never disappears.
  $leds = function (int $online, int $offline, int $total, int $count = 40) {
      if ($total === 0) {
          return [0, 0, $count]; // nothing added yet: all dim
      }
      $on = $online ? max(1, (int) round($online / $total * $count)) : 0;
      $off = $offline ? max(1, (int) round($offline / $total * $count)) : 0;
      $idle = $total - $online - $offline ? max(1, $count - $on - $off) : 0;
      $on = $count - $off - $idle; // absorb rounding so the bar is always full width
      return [max(0, $on), $off, $idle];
  };
  // Online share: "96.9%", "100%", or a dash when nothing is added yet
  $pct = fn (int $online, int $total) => $total ? rtrim(rtrim(number_format($online / $total * 100, 1), '0'), '.').'%' : '–';
  $pctClass = fn (int $online, int $total) => ! $total ? 'none' : ($online / $total >= .95 ? 'good' : ($online / $total >= .8 ? 'fair' : 'poor'));
  $u = $k['users'];
  $r = $k['routers'];
@endphp

<section class="panel kpi" aria-labelledby="kpi-users">
  <h2 id="kpi-users">Users online</h2>
  <p class="value">{{ $u['updated'] && $r['online'] ? number_format($u['online']) : '–' }}</p>
  <p class="sub">
    @if (! $r['total'])
      No routers added yet.
    @elseif (! $u['updated'])
      <span class="unk">Waiting for the first router check.</span>
    @else
      Across <b>{{ number_format($r['online']) }}</b> of {{ number_format($r['total']) }} routers{!! $r['offline'] ? '<span class="down">, '.number_format($r['offline']).' not answering</span>' : '' !!}.
      <b>{{ number_format($u['today']) }}</b> registered today.
    @endif
  </p>
  @if ($u['updated'])
    <p class="sub as-of">As of <time datetime="{{ $u['updated']->toIso8601String() }}">{{ $u['updated']->timezone('Asia/Manila')->format('H:i:s') }}</time></p>
  @endif
</section>

@foreach ([
    ['routers', 'Routers', $r, 'answering'],
    ['switches', 'Switches', $k['switches'], 'online'],
    ['aps', 'Access points', $k['aps'], 'online'],
] as [$key, $label, $d, $word])
  @php
    $down = $d['offline'];
    $unchecked = $d['total'] - $d['online'] - $down;
    [$on, $off, $idle] = $leds($d['online'], $down, $d['total']);
    // Dash until at least one has actually been checked
    $base = ($d['online'] + $down) > 0 ? $d['total'] : 0;
    // Online and offline are always shown (even when 0); "not checked" only when there are some.
    $parts = [
        '<b>'.number_format($d['online']).'</b> '.e($word),
        '<span class="'.($down ? 'down' : 'unk').'">'.number_format($down).' offline</span>',
    ];
    if ($unchecked) { $parts[] = '<span class="unk">'.number_format($unchecked).' not checked</span>'; }
  @endphp
  <section class="panel kpi" aria-labelledby="kpi-{{ $key }}">
    <h2 id="kpi-{{ $key }}">{{ $label }}</h2>
    <div class="value-row">
      <p class="value">{{ number_format($d['total']) }}</p>
      <p class="pct {{ $pctClass($d['online'], $base) }}">{{ $pct($d['online'], $base) }}<small>online</small></p>
    </div>
    <p class="sub">{!! implode(', ', $parts) !!}</p>
    <div class="leds" role="img" aria-label="{{ $d['total'] ? round($d['online'] / $d['total'] * 100, 1).' percent online' : 'none added yet' }}">
      @for ($s = 0; $s < $on; $s++)<i class="on"></i>@endfor
      @for ($s = 0; $s < $idle; $s++)<i></i>@endfor
      @for ($s = 0; $s < $off; $s++)<i class="off"></i>@endfor
    </div>
  </section>
@endforeach
