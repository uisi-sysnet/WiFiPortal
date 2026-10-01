{{--
  Users report on one A4 landscape page: the numbers only, no list of people.
  Rendered to PDF by dompdf, or as HTML that the Users page captures as a PNG ($image).
  Plain tables and inline styles: dompdf has no flexbox/grid, so the bar chart is boxes
  and the pie is a PNG (App\Support\PieChart).
  Needs: $stats, $filters, $network, $pie, $generated, $by, $topNetworks, $otherNetworks, $reference, $image.
--}}
@php
  $image = $image ?? false;
  $s = $stats;
  $acc = $s['accumulated'];
  $pct = fn ($n) => \App\Services\Portal\RegistrationStats::percent($n, $acc);
  $bars = $s['bars'];
  $n = count($bars);
  $max = max(1, collect($bars)->max(fn ($b) => $b['unique'] + $b['repeated']));
  $chartH = 118; // px of the tallest bar
  $every = max(1, (int) ceil($n / 12)); // about 12 labels whatever the number of bars
  $label = fn ($at, $i) => match ($s['step']) {
      'hour' => $at->hour % 3 === 0 ? $at->format('H:00') : '',
      'year' => $at->format('Y'),
      'month' => ($n - 1 - $i) % $every === 0 ? $at->format($n > 12 ? "M 'y" : 'M') : '',
      default => $n <= 8 ? $at->format('D j') : (($n - 1 - $i) % $every === 0 ? $at->format('M j') : ''),
  };
  $when = fn ($at) => match ($s['step']) {
      'hour' => $at->format('M j, H:00'), 'month' => $at->format('F Y'), 'year' => $at->format('Y'), default => $at->format('D, M j, Y'),
  };
  $used = array_filter([
      'Hotspot network' => $network ? $network->router?->name.' / '.$network->name : null,
      'Users' => isset($filters['type']) ? ($filters['type'] === 'resident' ? 'Residents only' : 'Visitors only') : null,
  ]);
  $avg = $s['average'] >= 10 || $s['average'] == 0 ? number_format($s['average']) : number_format($s['average'], 1);
  $bd = $s['breakdown'];
  $stamp = $generated->format('F j, Y \a\t g:i A').' ('.$generated->timezone.')';
  $more = $otherNetworks + max(0, count($topNetworks) - 3);
@endphp
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Users report</title>
<style>
@page { margin: 22px 26px 52px; }
* { font-family: "DejaVu Sans", Arial, sans-serif; box-sizing: border-box; }
body { font-size: 9px; color: #0F1A1F; margin: 0; background: #fff; }
@if ($image)
body { width: 1123px; padding: 22px 26px 18px; }
@endif
h1 { font-size: 17px; margin: 0; color: #0e670d; }
h2 { font-size: 9.5px; margin: 9px 0 5px; text-transform: uppercase; letter-spacing: .5px; color: #0e670d; }
.muted { color: #5c6b66; }
table { border-collapse: collapse; width: 100%; }
.head td { vertical-align: bottom; }
.rule { border-bottom: 2px solid #0e670d; margin: 6px 0 6px; }
.summary { margin: 4px 0 0; padding: 5px 9px; background: #F3F8F4; border-left: 3px solid #0e670d; font-size: 8.5px; line-height: 1.45; }
.cards td { width: 25%; padding: 0 6px 0 0; vertical-align: top; }
.card { border: 1.5px solid #0e670d; border-radius: 5px; padding: 6px 9px; height: 60px; }
.card .lbl { font-size: 7.5px; font-weight: bold; text-transform: uppercase; color: #5c6b66; }
.card .num { font-size: 18px; font-weight: bold; margin: 2px 0 4px; }
.card .num small { font-size: 8px; font-weight: normal; color: #5c6b66; }
.track { height: 4px; background: #EEF3EF; }
.track div { height: 4px; background: #0e670d; }
.track .rep { background: #7FB77E; }
.card .sub { margin-top: 4px; color: #5c6b66; font-size: 7.5px; }
.card .sub b { color: #0e670d; }
.panel { border: 1.5px solid #0e670d; border-radius: 5px; padding: 7px 9px; }
.chart td.col { vertical-align: bottom; text-align: center; padding: 0 1px; }
.bar-u { background: #0e670d; margin: 0 auto; }
.bar-r { background: #7FB77E; margin: 0 auto; }
.chart .val { font-size: 6.5px; color: #0F1A1F; }
.chart .lab td { font-size: 6.5px; color: #5c6b66; text-align: center; padding-top: 2px; border-top: 1px solid #C9D6CE; }
.sw { display: inline-block; width: 8px; height: 8px; margin: 0 3px 0 10px; }
.legend td { padding: 3px 0; font-size: 8.5px; border-bottom: 0.5px solid #E1E9E4; }
.legend .v { text-align: right; font-weight: bold; }
.legend .p { text-align: right; color: #5c6b66; width: 46px; }
.bd td.box { width: 33.3%; vertical-align: top; padding-right: 6px; }
.bd td.box.last { padding-right: 0; }
.mini th { text-align: left; font-size: 7px; text-transform: uppercase; color: #5c6b66; padding: 2px 0; border-bottom: 1px solid #0e670d; }
.mini td { padding: 2px 0; border-bottom: 0.5px solid #E1E9E4; font-size: 8px; }
.mini .n { text-align: right; font-weight: bold; width: 48px; }
.mini .p { text-align: right; color: #5c6b66; width: 42px; }
.notes { margin: 6px 0 0; color: #5c6b66; font-size: 7px; line-height: 1.45; }
#footer { border-top: 1.5px solid #0e670d; padding-top: 4px; font-size: 7px; color: #5c6b66; line-height: 1.45; }
@if (! $image)
#footer { position: fixed; left: 0; right: 0; bottom: -40px; height: 34px; padding-right: 80px; } /* room for the page number */
@else
#footer { margin-top: 10px; }
@endif
#footer b { color: #0F1A1F; }
#footer .dev { color: #0e670d; font-weight: bold; }
</style>
</head>
<body>

<table class="head">
  <tr>
    <td>
      <h1>Users report</h1>
      <div class="muted">Hotspot registrations: Public WiFi Control</div>
    </td>
    <td style="text-align:right" class="muted">
      Period: <b style="color:#0F1A1F">{{ $s['title'] }}</b>
      @foreach ($used as $k => $v)<br>{{ $k }}: <b style="color:#0F1A1F">{{ $v }}</b>@endforeach
    </td>
  </tr>
</table>
<div class="rule"></div>

<div class="summary">
  @if ($acc)
    <b>{{ number_format($acc) }}</b> {{ $acc === 1 ? 'registration was' : 'registrations were' }} made on the hotspot captive portal{{ $s['range'] === 'all' ? ' so far' : ' in this period ('.$s['title'].')' }},
    by <b>{{ number_format($s['unique']) }}</b> different {{ $s['unique'] === 1 ? 'person' : 'people' }}.
    {{ $pct($s['repeated']) }} of registrations were return visits by {{ number_format($s['returning']) }} {{ $s['returning'] === 1 ? 'person' : 'people' }}.
    On average {{ $avg }} {{ $s['average'] == 1 ? 'user' : 'users' }} registered per {{ $s['per'] }}; the busiest {{ $s['per'] }} was {{ $when($s['peak']['at']) }} with {{ number_format($s['peak']['total']) }}.
    {{ $pct($bd['connected']) }} of registrations went on to connect to the internet.
  @else
    No one registered on the hotspot captive portal in this period{{ $used ? ' with these filters' : '' }}.
  @endif
</div>

{{-- Totals --}}
<h2>Totals</h2>
<table class="cards"><tr>
  <td><div class="card">
    <div class="lbl">Accumulated users</div>
    <div class="num">{{ number_format($acc) }}</div>
    <div class="sub">Every registration{{ $s['range'] === 'all' ? ' so far' : ' in this period' }}</div>
  </div></td>
  <td><div class="card">
    <div class="lbl">Unique users</div>
    <div class="num">{{ number_format($s['unique']) }}</div>
    <div class="track"><div style="width:{{ $acc ? round($s['unique'] / $acc * 100, 1) : 0 }}%"></div></div>
    <div class="sub"><b>{{ $pct($s['unique']) }}</b> of accumulated: different people</div>
  </div></td>
  <td><div class="card">
    <div class="lbl">Repeated users</div>
    <div class="num">{{ number_format($s['repeated']) }}</div>
    <div class="track"><div class="rep" style="width:{{ $acc ? round($s['repeated'] / $acc * 100, 1) : 0 }}%"></div></div>
    <div class="sub"><b>{{ $pct($s['repeated']) }}</b> of accumulated: return visits by {{ number_format($s['returning']) }} {{ $s['returning'] === 1 ? 'person' : 'people' }}</div>
  </div></td>
  <td style="padding-right:0"><div class="card">
    <div class="lbl">Average users</div>
    <div class="num">{{ $avg }} <small>per {{ $s['per'] }}</small></div>
    <div class="track"><div style="width:{{ $s['peak'] ? round($s['average'] / $s['peak']['total'] * 100, 1) : 0 }}%"></div></div>
    <div class="sub">
      @if ($s['peak'])
        <b>{{ \App\Services\Portal\RegistrationStats::percent($s['average'], $s['peak']['total']) }}</b> of the busiest {{ $s['per'] }}: {{ number_format($s['peak']['total']) }} on {{ $when($s['peak']['at']) }}
      @else
        No registrations in this period
      @endif
    </div>
  </div></td>
</tr></table>

{{-- Charts: users per period (left), unique and repeated pie (right) --}}
<table><tr>
  <td style="width:72%;vertical-align:top;padding-right:8px">
    <h2>Users per {{ $s['per'] }}
      <span style="font-size:7.5px;font-weight:normal;text-transform:none;color:#5c6b66">
        <span class="sw" style="background:#0e670d"></span>Unique<span class="sw" style="background:#7FB77E"></span>Repeated
      </span>
    </h2>
    <div class="panel">
      @if ($acc)
        @php $barW = max(3, min(24, (int) floor(540 / $n * 0.6))); @endphp
        <table class="chart">
          <tr style="height:{{ $chartH + 12 }}px">
            @foreach ($bars as $b)
              @php
                $barTotal = $b['unique'] + $b['repeated'];
                $hu = $b['unique'] ? max(1, round($b['unique'] / $max * $chartH)) : 0;
                $hr = $b['repeated'] ? max(1, round($b['repeated'] / $max * $chartH)) : 0;
              @endphp
              <td class="col">
                @if ($barTotal && $n <= 31)<div class="val">{{ number_format($barTotal) }}</div>@endif
                @if ($hr)<div class="bar-r" style="width:{{ $barW }}px;height:{{ $hr }}px"></div>@endif
                @if ($hu)<div class="bar-u" style="width:{{ $barW }}px;height:{{ $hu }}px"></div>@endif
              </td>
            @endforeach
          </tr>
          <tr class="lab">
            @foreach ($bars as $i => $b)<td>{{ $label($b['at'], $i) }}</td>@endforeach
          </tr>
        </table>
      @else
        <p class="muted" style="padding:50px 0;text-align:center;margin:0">No registrations in this period.</p>
      @endif
    </div>
  </td>
  <td style="width:28%;vertical-align:top">
    <h2>Unique and repeated</h2>
    <div class="panel">
      <table><tr>
        <td style="width:104px;vertical-align:middle"><img src="{{ $pie }}" width="100" height="100" alt=""></td>
        <td style="vertical-align:middle;padding-left:6px">
          <div style="font-size:15px;font-weight:bold">{{ number_format($acc) }} <span class="muted" style="font-size:8px;font-weight:normal">users</span></div>
          <table class="legend" style="margin-top:4px">
            <tr>
              <td><span class="sw" style="background:#0e670d;margin-left:0"></span>Unique</td>
              <td class="v">{{ number_format($s['unique']) }}</td>
              <td class="p">{{ $pct($s['unique']) }}</td>
            </tr>
            <tr>
              <td><span class="sw" style="background:#7FB77E;margin-left:0"></span>Repeated</td>
              <td class="v">{{ number_format($s['repeated']) }}</td>
              <td class="p">{{ $pct($s['repeated']) }}</td>
            </tr>
          </table>
        </td>
      </tr></table>
    </div>
  </td>
</tr></table>

{{-- Breakdown --}}
<h2>Breakdown</h2>
<table class="bd"><tr>
  <td class="box"><div class="panel">
    <table class="mini">
      <tr><th>Type of user</th><th class="n">Users</th><th class="p">Share</th></tr>
      <tr><td>Visitors (name and mobile or email)</td><td class="n">{{ number_format($bd['visitors']) }}</td><td class="p">{{ $pct($bd['visitors']) }}</td></tr>
      <tr><td>Residents (resident ID)</td><td class="n">{{ number_format($bd['residents']) }}</td><td class="p">{{ $pct($bd['residents']) }}</td></tr>
    </table>
  </div></td>
  <td class="box"><div class="panel">
    <table class="mini">
      <tr><th>After registering</th><th class="n">Users</th><th class="p">Share</th></tr>
      <tr><td>Connected to the internet</td><td class="n">{{ number_format($bd['connected']) }}</td><td class="p">{{ $pct($bd['connected']) }}</td></tr>
      <tr><td>Never tapped Connect</td><td class="n">{{ number_format($acc - $bd['connected']) }}</td><td class="p">{{ $pct($acc - $bd['connected']) }}</td></tr>
    </table>
  </div></td>
  <td class="box last"><div class="panel">
    <table class="mini">
      <tr><th>Busiest hotspot networks</th><th class="n">Users</th><th class="p">Share</th></tr>
      @forelse (array_slice($topNetworks, 0, 3) as $net)
        <tr><td>{{ $net['name'] }}</td><td class="n">{{ number_format($net['count']) }}</td><td class="p">{{ $pct($net['count']) }}</td></tr>
      @empty
        <tr><td colspan="3" class="muted">No registrations</td></tr>
      @endforelse
      @if ($more)
        <tr><td colspan="3" class="muted">and {{ $more }} more {{ $more === 1 ? 'network' : 'networks' }}</td></tr>
      @endif
    </table>
  </div></td>
</tr></table>

{{-- How the numbers are counted --}}
<p class="notes">
  <b>About these numbers.</b> Source: registrations on this system's hotspot captive portal (networks using a custom URL or the router's own login page are not included).
  One person is identified by resident ID, otherwise mobile or email, otherwise the phone's MAC. Their first registration in the period is unique; later ones are repeated, so unique + repeated = accumulated.
  A login lasts {{ config('hotspot.credential_hours') }} hours, so a repeat means registering again after it ended. Times in {{ $generated->timezone }}; bars per {{ $s['per'] }}. No personal details are included.
</p>

{{-- Footer: on every page in the PDF (page numbers are added by the controller), under the content in the image --}}
<div id="footer">
  This is a system-generated report from <b>Public WiFi Control</b>, produced on <b>{{ $stamp }}</b>{{ $by ? ' at the request of '.$by : '' }}.
  It reflects the data recorded at that moment and needs no signature. Reference no. <b>{{ $reference }}</b>.<br>
  <span class="dev">System developed by Uplink Integrated Solutions Inc. &ndash; System &amp; Network Department</span>
</div>

</body>
</html>
