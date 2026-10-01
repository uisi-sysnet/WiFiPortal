{{--
  "Users online" chart body for one range (day, week, month, year).
  Rendered with the page and again by dashboard.users-chart when the filter changes.
  Needs: $s (UserHistory::series()). Each point is the peak users in that hour, day or month.
--}}
@php
  $W = 760; $H = 230; $L = 46; $R = 12; $T = 18; $B = 28;
  $pts = $s['points'];
  $n = count($pts);
  $values = array_filter(array_column($pts, 'value'), fn ($v) => $v !== null);
  $max = $values ? max($values) : 0;

  // Round axis: 4-5 gridlines at 1, 2, 2.5 or 5 x a power of ten
  $raw = max(1, $max) / 4;
  $mag = 10 ** floor(log10($raw));
  $stepY = collect([1, 2, 2.5, 5, 10])->map(fn ($m) => $m * $mag)->first(fn ($v) => $v >= $raw);
  $yMax = max($stepY, ceil(max(1, $max) / $stepY) * $stepY);
  $x = fn ($i) => round($L + ($n > 1 ? $i * ($W - $L - $R) / ($n - 1) : ($W - $L - $R) / 2), 1);
  $y = fn ($v) => round($T + ($H - $T - $B) * (1 - $v / $yMax), 1);
  $axis = fn ($v) => $v >= 1000 ? rtrim(rtrim(number_format($v / 1000, 1), '0'), '.').'k' : (string) $v;

  // Line and area per run of points that have data; gaps stay empty.
  $runs = [];
  $run = [];
  foreach ($pts as $i => $p) {
      if ($p['value'] === null) { if ($run) { $runs[] = $run; $run = []; } continue; }
      $run[] = $i;
  }
  if ($run) { $runs[] = $run; }

  // Axis labels and hover text per range
  $label = match ($s['range']) {
      'day' => fn ($at, $i) => $at->hour % 3 === 0 ? $at->format('H:00') : null,
      'week' => fn ($at, $i) => $at->hour === 0 ? $at->format('D j') : null,
      'month' => fn ($at, $i) => ($n - 1 - $i) % 5 === 0 ? $at->format('M j') : null,
      default => fn ($at, $i) => $at->format('M'),
  };
  $when = fn ($at) => match ($s['range']) {
      'day' => $at->format('H:00'),
      'week' => $at->format('D, M j, H:00'),
      'month' => $at->format('D, M j'),
      default => $at->format('F Y'),
  };
  $per = ['hour' => 'hour', 'day' => 'day', 'month' => 'month'][$s['step']];
  $last = $n - 1;
  // Day and week: the users online right now. Month and year: the last point is
  // today's or this month's peak so far, so it says that instead of "Now".
  [$nowLabel, $nowValue] = in_array($s['range'], ['day', 'week'], true)
      ? ['Now', $s['now']]
      : [$s['range'] === 'month' ? 'Today' : 'This month', $pts[$last]['value'] ?? null];
@endphp

<p class="chart-meta">
  @if ($s['peak'])
    Peak <span class="peak">{{ number_format($s['peak']['value']) }}</span>
    {{ $s['range'] === 'year' ? 'in' : ($s['range'] === 'day' ? 'at' : 'on') }} {{ $when($s['peak']['at']) }}
    <span class="sep">·</span>
  @endif
  {{ ucfirst($s['title']) }}, highest users online in each {{ $per }}
</p>

<svg viewBox="0 0 {{ $W }} {{ $H }}" role="img"
     aria-label="Users online, {{ $s['title'] }}{{ $s['peak'] ? ', peaking at '.number_format($s['peak']['value']) : ', no data yet' }}">
  <defs>
    <linearGradient id="fill" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#2CD5FF" stop-opacity=".35"/>
      <stop offset="1" stop-color="#2CD5FF" stop-opacity="0"/>
    </linearGradient>
    <filter id="glow" x="-10%" y="-30%" width="120%" height="160%">
      <feGaussianBlur stdDeviation="3" result="b"/>
      <feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge>
    </filter>
  </defs>

  @for ($v = 0; $v <= $yMax; $v += $stepY)
    <line class="gridline" x1="{{ $L }}" x2="{{ $W - $R }}" y1="{{ $y($v) }}" y2="{{ $y($v) }}"/>
    <text class="axis" x="{{ $L - 8 }}" y="{{ $y($v) + 4 }}" text-anchor="end">{{ $axis($v) }}</text>
  @endfor
  @foreach ($pts as $i => $p)
    @if ($text = $label($p['at'], $i))
      <text class="axis" x="{{ $x($i) }}" y="{{ $H - 8 }}" text-anchor="middle">{{ $text }}</text>
    @endif
  @endforeach

  @foreach ($runs as $r)
    @php
      $line = collect($r)->map(fn ($i, $k) => ($k ? 'L' : 'M').$x($i).','.$y($pts[$i]['value']))->implode(' ');
      $area = $line.' L'.$x(end($r)).','.($H - $B).' L'.$x($r[0]).','.($H - $B).' Z';
    @endphp
    <path d="{{ $area }}" fill="url(#fill)"/>
    <path d="{{ $line }}" fill="none" stroke="#2CD5FF" stroke-width="2.2" stroke-linejoin="round" filter="url(#glow)"/>
    @if (count($r) === 1)
      <circle cx="{{ $x($r[0]) }}" cy="{{ $y($pts[$r[0]]['value']) }}" r="3" fill="#2CD5FF"/>
    @endif
  @endforeach

  @if ($nowValue !== null && $pts[$last]['value'] !== null)
    @php $nx = $x($last); $ny = $y($pts[$last]['value']); @endphp
    <line x1="{{ $nx }}" x2="{{ $nx }}" y1="{{ $T }}" y2="{{ $H - $B }}" stroke="#2CD5FF" stroke-opacity=".5" stroke-dasharray="3 4"/>
    <circle cx="{{ $nx }}" cy="{{ $ny }}" r="5" fill="#030A12" stroke="#2CD5FF" stroke-width="2.5" filter="url(#glow)"/>
    <text class="axis now" x="{{ $nx - 9 }}" y="{{ max($T + 10, $ny - 10) }}" text-anchor="end" style="fill:#D6ECF5">{{ $nowLabel }} {{ number_format($nowValue) }}</text>
  @endif

  {{-- Hover: one invisible column per point, with its value --}}
  @foreach ($pts as $i => $p)
    @php $w = ($W - $L - $R) / max(1, $n - 1); @endphp
    <rect class="hit" x="{{ round($x($i) - $w / 2, 1) }}" y="{{ $T }}" width="{{ round($w, 1) }}" height="{{ $H - $T - $B }}">
      <title>{{ $when($p['at']) }}: {{ $p['value'] === null ? 'no data' : number_format($p['value']).' users' }}</title>
    </rect>
  @endforeach

  @unless ($values)
    <text class="axis empty" x="{{ ($W + $L) / 2 }}" y="{{ ($H - $B + $T) / 2 }}" text-anchor="middle">
      No history yet. Users online are saved every 5 minutes once routers are being checked.
    </text>
  @endunless
</svg>
