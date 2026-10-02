{{--
  Users page analytics: bar chart over time, unique/repeated donut, and totals.
  Needs: $stats (RegistrationStats::summary()), $ranges, $filters.
--}}
@php
  $s = $stats;
  $acc = $s['accumulated'];
  $pct = fn ($n) => \App\Services\Portal\RegistrationStats::percent($n, $acc);
  $rangeUrl = fn ($r) => route('users.index', array_filter([...$filters, 'range' => $r, 'page' => null],
      fn ($v, $k) => $v !== null && $v !== '' && ! ($k === 'period' && $v === 'all'), ARRAY_FILTER_USE_BOTH));

  // ---- Bar chart geometry ----
  $W = 760; $H = 250; $L = 44; $R = 10; $T = 14; $B = 30;
  $bars = $s['bars'];
  $n = count($bars);
  $max = max(1, collect($bars)->max(fn ($b) => $b['unique'] + $b['repeated']));
  $raw = $max / 4;
  $mag = 10 ** floor(log10(max(1, $raw)));
  // Whole-number steps only: users are counted in ones
  $stepY = max(1, collect([1, 2, 5, 10])->map(fn ($m) => (int) round($m * $mag))->first(fn ($v) => $v >= $raw));
  $yMax = ceil($max / $stepY) * $stepY;
  $slot = ($W - $L - $R) / $n;
  $bw = max(3, min(34, $slot * 0.62));
  $y = fn ($v) => round($T + ($H - $T - $B) * (1 - $v / $yMax), 1);
  $axis = fn ($v) => $v >= 1000 ? rtrim(rtrim(number_format($v / 1000, 1), '0'), '.').'k' : (string) (int) $v;
  // "All" can span many months: label about 12 of them, counting back from now
  $every = max(1, (int) ceil($n / 12));
  $label = match (true) {
      $s['range'] === 'day' => fn ($at, $i) => $at->hour % 3 === 0 ? $at->format('H:00') : null,
      $s['range'] === 'week' => fn ($at, $i) => $at->format('D j'),
      $s['range'] === 'month' => fn ($at, $i) => ($n - 1 - $i) % 5 === 0 ? $at->format('M j') : null,
      $s['step'] === 'year' => fn ($at, $i) => $at->format('Y'),
      $s['range'] === 'all' => fn ($at, $i) => ($n - 1 - $i) % $every === 0 ? $at->format($n > 12 ? "M 'y" : 'M') : null,
      default => fn ($at, $i) => $at->format('M'),
  };
  $when = fn ($at) => match ($s['step']) {
      'hour' => $at->format('M j, H:00'),
      'month' => $at->format('F Y'),
      'year' => $at->format('Y'),
      default => $at->format('D, M j'),
  };

  // ---- Pie: unique from 12 o'clock clockwise, repeated after it ----
  $pr = 70; $pc = 80;
  $uAngle = $acc ? $s['unique'] / $acc * 2 * M_PI : 0;
  $px = fn ($a) => round($pc + $pr * sin($a), 2);
  $py = fn ($a) => round($pc - $pr * cos($a), 2);
  $slice = fn ($a0, $a1) => 'M'.$pc.','.$pc.' L'.$px($a0).','.$py($a0)
      .' A'.$pr.','.$pr.' 0 '.($a1 - $a0 > M_PI ? 1 : 0).' 1 '.$px($a1).','.$py($a1).' Z';

  // Export: preselect the period on screen; reopen with errors after a bad date range
  $exportErrors = $errors->hasAny(['range', 'from', 'to']);
  $exportRange = old('range', $s['range']);
@endphp

<section class="u-stats" aria-labelledby="stats-title">
  <div class="u-stats-head">
    <h2 id="stats-title">Registered users <span>{{ $s['title'] }}</span></h2>
    <div class="u-stats-tools">
    <details class="u-export-wrap" @if($exportErrors) open @endif>
      <summary class="u-export">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></svg>
        Export
      </summary>
      <form class="u-export-form" method="GET" action="{{ route('users.report') }}">
        <fieldset>
          <legend>Report period</legend>
          @foreach ([...array_map(fn ($rg) => $rg['label'] === 'All' ? 'All (all time)' : $rg['label'].' ('.strtolower($rg['title']).')', $ranges), 'custom' => 'Date range'] as $key => $text)
            <label class="u-radio"><input type="radio" name="range" value="{{ $key }}" @checked($exportRange === $key)> {{ $text }}</label>
          @endforeach
        </fieldset>
        <fieldset class="u-format">
          <legend>File</legend>
          <label class="u-radio"><input type="radio" name="format" value="pdf" @checked(old('format', 'pdf') === 'pdf')> PDF document</label>
          <label class="u-radio"><input type="radio" name="format" value="png" @checked(old('format') === 'png')> Image (PNG)</label>
        </fieldset>
        <div class="u-dates" data-dates>
          <label>From <input class="u-control" type="date" name="from" value="{{ old('from', now()->timezone(config('hotspot.history.timezone'))->subDays(29)->toDateString()) }}" max="{{ now()->timezone(config('hotspot.history.timezone'))->toDateString() }}"></label>
          <label>To <input class="u-control" type="date" name="to" value="{{ old('to', now()->timezone(config('hotspot.history.timezone'))->toDateString()) }}" max="{{ now()->timezone(config('hotspot.history.timezone'))->toDateString() }}"></label>
        </div>
        @foreach (['range', 'from', 'to'] as $field)
          @error($field)<p class="u-error">{{ $message }}</p>@enderror
        @endforeach
        <label class="u-radio" style="margin:0 0 8px"><input type="checkbox" name="aps" value="1" @checked(old('aps'))> Add clients per access point (second page)</label>
        @if (! empty($filters['network']))<input type="hidden" name="network" value="{{ $filters['network'] }}">@endif
        @if (! empty($filters['type']))<input type="hidden" name="type" value="{{ $filters['type'] }}">@endif
        <p class="u-export-note">One page: totals, users per period, the unique/repeated pie and a breakdown{{ ! empty($filters['network']) || ! empty($filters['type']) ? ', for the network and user type selected below' : '' }}. No personal details.</p>
        <p class="u-export-msg" data-export-msg role="status" hidden></p>
        <button class="u-btn" type="submit" data-export-btn>Download PDF</button>
      </form>
    </details>
    <nav class="u-seg" aria-label="Period">
      @foreach ($ranges as $key => $rg)
        <a href="{{ $rangeUrl($key) }}" @if($s['range'] === $key) aria-current="page" @endif>{{ $rg['label'] }}</a>
      @endforeach
    </nav>
    </div>
  </div>

  <div class="u-charts">
    {{-- Users over time --}}
    <figure class="u-card u-bars">
      <figcaption>
        Users per {{ $s['per'] }}
        <span class="u-key"><i class="k-unique"></i>Unique <i class="k-repeat"></i>Repeated</span>
      </figcaption>
      <svg viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-label="Users per {{ $s['per'] }}, {{ strtolower($s['title']) }}: {{ number_format($acc) }} in total">
        @for ($v = 0; $v <= $yMax; $v += $stepY)
          <line class="grid" x1="{{ $L }}" x2="{{ $W - $R }}" y1="{{ $y($v) }}" y2="{{ $y($v) }}"/>
          <text class="ax" x="{{ $L - 8 }}" y="{{ $y($v) + 4 }}" text-anchor="end">{{ $axis($v) }}</text>
        @endfor
        @foreach ($bars as $i => $b)
          @php
            $cx = $L + $slot * ($i + 0.5);
            $total = $b['unique'] + $b['repeated'];
          @endphp
          @if ($b['unique'])
            <rect class="b-unique" x="{{ round($cx - $bw / 2, 1) }}" y="{{ $y($b['unique']) }}" width="{{ round($bw, 1) }}" height="{{ round($y(0) - $y($b['unique']), 1) }}" rx="2"/>
          @endif
          @if ($b['repeated'])
            <rect class="b-repeat" x="{{ round($cx - $bw / 2, 1) }}" y="{{ $y($total) }}" width="{{ round($bw, 1) }}" height="{{ round($y($b['unique']) - $y($total), 1) }}" rx="2"/>
          @endif
          @if ($total && $n <= 12)
            <text class="val" x="{{ round($cx, 1) }}" y="{{ $y($total) - 5 }}" text-anchor="middle">{{ number_format($total) }}</text>
          @endif
          @if ($text = $label($b['at'], $i))
            <text class="ax" x="{{ round($cx, 1) }}" y="{{ $H - 9 }}" text-anchor="middle">{{ $text }}</text>
          @endif
          <rect class="hit" x="{{ round($cx - $slot / 2, 1) }}" y="{{ $T }}" width="{{ round($slot, 1) }}" height="{{ $H - $T - $B }}">
            <title>{{ $when($b['at']) }}: {{ number_format($total) }} users ({{ number_format($b['unique']) }} unique, {{ number_format($b['repeated']) }} repeated)</title>
          </rect>
        @endforeach
        @unless ($acc)
          <text class="ax empty" x="{{ ($W + $L) / 2 }}" y="{{ ($H - $B + $T) / 2 }}" text-anchor="middle">No registrations in this period yet.</text>
        @endunless
      </svg>
    </figure>

    {{-- Unique vs repeated, with numbers --}}
    <figure class="u-card u-donut">
      <figcaption>Unique and repeated <span class="u-total-users">{{ number_format($acc) }} users</span></figcaption>
      <svg viewBox="0 0 160 160" role="img" aria-label="{{ number_format($s['unique']) }} unique ({{ $pct($s['unique']) }}), {{ number_format($s['repeated']) }} repeated ({{ $pct($s['repeated']) }})">
        @if (! $acc)
          <circle cx="{{ $pc }}" cy="{{ $pc }}" r="{{ $pr }}" class="p-empty"/>
        @elseif (! $s['repeated'] || ! $s['unique'])
          <circle cx="{{ $pc }}" cy="{{ $pc }}" r="{{ $pr }}" class="{{ $s['unique'] ? 'p-unique' : 'p-repeat' }}"/>
        @else
          <path d="{{ $slice(0, $uAngle) }}" class="p-unique"><title>Unique {{ number_format($s['unique']) }} ({{ $pct($s['unique']) }})</title></path>
          <path d="{{ $slice($uAngle, 2 * M_PI) }}" class="p-repeat"><title>Repeated {{ number_format($s['repeated']) }} ({{ $pct($s['repeated']) }})</title></path>
        @endif
      </svg>
      <ul class="u-legend">
        <li><i class="k-unique"></i>Unique <b>{{ number_format($s['unique']) }}</b><span>{{ $pct($s['unique']) }}</span></li>
        <li><i class="k-repeat"></i>Repeated <b>{{ number_format($s['repeated']) }}</b><span>{{ $pct($s['repeated']) }}</span></li>
      </ul>
    </figure>
  </div>

  {{-- Totals --}}
  <div class="u-totals">
    <div class="u-card u-total">
      <p class="t-label">Accumulated users</p>
      <p class="t-num">{{ number_format($acc) }}</p>
      <p class="t-sub">Every registration{{ $s['range'] === 'all' ? ' so far' : ', '.strtolower($s['title']) }}</p>
    </div>
    <div class="u-card u-total">
      <p class="t-label">Unique users</p>
      <p class="t-num">{{ number_format($s['unique']) }}</p>
      <div class="t-bar"><span style="width:{{ $acc ? round($s['unique'] / $acc * 100, 1) : 0 }}%"></span></div>
      <p class="t-sub"><b>{{ $pct($s['unique']) }}</b> of accumulated: different people</p>
    </div>
    <div class="u-card u-total">
      <p class="t-label">Repeated users</p>
      <p class="t-num">{{ number_format($s['repeated']) }}</p>
      <div class="t-bar repeat"><span style="width:{{ $acc ? round($s['repeated'] / $acc * 100, 1) : 0 }}%"></span></div>
      <p class="t-sub"><b>{{ $pct($s['repeated']) }}</b> of accumulated: return visits by {{ number_format($s['returning']) }} {{ $s['returning'] === 1 ? 'person' : 'people' }}</p>
    </div>
    <div class="u-card u-total">
      <p class="t-label">Average users</p>
      <p class="t-num">{{ $s['average'] >= 10 || $s['average'] == 0 ? number_format($s['average']) : number_format($s['average'], 1) }} <small>per {{ $s['per'] }}</small></p>
      <div class="t-bar"><span style="width:{{ $s['peak'] ? round($s['average'] / $s['peak']['total'] * 100, 1) : 0 }}%"></span></div>
      <p class="t-sub">
        @if ($s['peak'])
          <b>{{ \App\Services\Portal\RegistrationStats::percent($s['average'], $s['peak']['total']) }}</b> of the busiest {{ $s['per'] }}: {{ number_format($s['peak']['total']) }} on {{ $when($s['peak']['at']) }}
        @else
          No registrations yet
        @endif
      </p>
    </div>
  </div>
</section>
