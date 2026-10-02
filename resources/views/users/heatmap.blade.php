@extends('layouts.app')

@section('title', 'Heat map | Public WiFi Control')
@section('body-class', 'wide')

@push('head')
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<style>
:root{--line:#0e670d;--ink:#0F1A1F;--ink-2:#5c6b66;--hover:#F0F3F1;--fail:#B3372E}
body.wide main{max-width:1600px}
.hm{padding:0 4px}
.hm-head{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:10px 20px;margin-bottom:12px}
.hm-head h1{margin:0;font-size:1.35rem;font-weight:700;color:var(--ink)}
.hm-head p{margin:2px 0 0;color:var(--ink-2);font-size:.85rem}
.hm-back{font-size:.82rem;color:#0e670d;text-decoration:none;font-weight:600}
.hm-tools{display:flex;flex-wrap:wrap;align-items:center;gap:8px 10px;margin-bottom:12px}
.hm-seg{display:inline-flex;border:1px solid var(--line);border-radius:6px;overflow:hidden;background:#fff}
.hm-seg a{padding:6px 12px;font-size:.82rem;font-weight:600;color:var(--ink-2);text-decoration:none}
.hm-seg a+a{border-left:1px solid var(--line)}
.hm-seg a:hover{background:var(--hover);color:var(--ink)}
.hm-seg a[aria-current=page]{background:#0e670d;color:#fff}
.hm-form{display:flex;flex-wrap:wrap;align-items:center;gap:6px}
.hm-control{height:31px;padding:0 8px;font:inherit;font-size:.82rem;border:1px solid var(--line);border-radius:6px;background:#fff;color:var(--ink)}
.hm-btn{display:inline-flex;align-items:center;gap:6px;height:31px;padding:0 12px;font:inherit;font-size:.82rem;font-weight:600;border-radius:6px;border:1px solid #0e670d;background:#0e670d;color:#fff;cursor:pointer;text-decoration:none}
.hm-btn.quiet{background:#fff;color:#0e670d}
.hm-error{color:var(--fail);font-size:.8rem;margin:0 0 8px}
.hm-grid{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:14px}
.hm-card{background:#fff;border:2px solid var(--line);border-radius:8px}
.hm-map{position:relative;overflow:hidden}
#heatmap{height:640px;background:#EEF3EF}
.hm-legend{position:absolute;left:12px;bottom:12px;z-index:500;background:rgba(255,255,255,.94);border:1px solid var(--line);border-radius:6px;padding:7px 10px;font-size:.75rem;color:var(--ink)}
.hm-legend .ramp{width:180px;height:9px;border-radius:5px;margin:4px 0 2px;background:linear-gradient(90deg,#3B4CC0,#33C6E8,#7CE85A,#F8E33B,#F2862E,#D7191C)}
.hm-legend .ends{display:flex;justify-content:space-between;color:var(--ink-2)}
.hm-empty{position:absolute;inset:0;z-index:600;display:grid;place-items:center;background:rgba(255,255,255,.85);text-align:center;padding:24px;color:var(--ink-2)}
.hm-side{display:grid;gap:14px;align-content:start}
.hm-side .hm-card{padding:12px 14px}
.hm-side h2{margin:0 0 8px;font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-2)}
.hm-big{margin:0;font-size:1.7rem;font-weight:700;color:var(--ink);font-variant-numeric:tabular-nums}
.hm-big small{font-size:.8rem;font-weight:500;color:var(--ink-2)}
.hm-sub{margin:2px 0 0;font-size:.78rem;color:var(--ink-2)}
.hm-list{list-style:none;margin:0;padding:0;display:grid;gap:6px;font-size:.84rem}
.hm-list li{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:4px 10px;align-items:center}
.hm-list .bar{grid-column:1/-1;height:5px;border-radius:3px;background:#EEF3EF;overflow:hidden}
.hm-list .bar span{display:block;height:100%;background:linear-gradient(90deg,#F8E33B,#D7191C)}
.hm-list b{font-variant-numeric:tabular-nums}
.hm-list small{color:var(--ink-2)}
.hm-list a{color:inherit;text-decoration:none}
.hm-list a:hover{text-decoration:underline}
.hm-note{margin:10px 0 0;font-size:.75rem;color:var(--ink-2)}
.hm-print-head{display:none}
@media (max-width:1100px){.hm-grid{grid-template-columns:1fr}#heatmap{height:520px}}
@media print{
  header,.hm-tools,.hm-back,.leaflet-control-zoom{display:none!important}
  main{padding-top:0!important}
  .hm-print-head{display:block;font-size:.8rem;color:var(--ink-2);margin-bottom:6px}
  .hm-grid{grid-template-columns:minmax(0,1fr) 260px}
  #heatmap{height:560px}
  .hm-card{break-inside:avoid}
}
</style>
@endpush

@section('content')
@php
  $tz = config('hotspot.history.timezone');
  $metricLabels = ['average' => 'Average clients', 'peak' => 'Peak clients', 'now' => 'Clients now'];
  $q = fn (array $change) => route('users.heatmap', array_filter([...$filters, ...$change], fn ($v) => $v !== null && $v !== ''));
  $num = fn ($v) => $v >= 10 || floor($v) == $v ? number_format($v) : number_format($v, 1);
  $onMap = count(array_filter($points, fn ($p) => $p['value'] > 0));
  $missing = count($rows) - count($points);
  $maxBrgy = max(1, collect($barangays)->max('value') ?? 1);
  $topAps = collect($points)->sortByDesc('value')->filter(fn ($p) => $p['value'] > 0)->take(10)->values();
  $maxAp = max(1, $topAps->max('value') ?? 1);
  $live = $filters['metric'] === 'now';
@endphp

<div class="hm">
  <div class="hm-head">
    <div>
      <a class="hm-back" href="{{ route('users.index', ['range' => $filters['range'] === 'custom' ? null : $filters['range']]) }}#ap-clients">&larr; Users</a>
      <h1>Heat map: clients per access point</h1>
      <p>{{ $metricLabels[$filters['metric']] }}{{ $live ? ', live' : ', '.strtolower($window['title']) }}. Hotter means more people connected there.</p>
    </div>
    <div class="hm-print-head">Printed {{ now($tz)->format('F j, Y g:i A') }}. Public WiFi Control: system developed by Uplink Integrated Solutions Inc. &ndash; System &amp; Network Department</div>
  </div>

  <div class="hm-tools">
    <nav class="hm-seg" aria-label="Show">
      @foreach ($metricLabels as $key => $label)
        <a href="{{ $q(['metric' => $key]) }}" @if($filters['metric'] === $key) aria-current="page" @endif>{{ $label }}</a>
      @endforeach
    </nav>
    @unless ($live)
      <nav class="hm-seg" aria-label="Period">
        @foreach ($ranges as $key => $rg)
          <a href="{{ $q(['range' => $key, 'from' => null, 'to' => null]) }}" @if($filters['range'] === $key) aria-current="page" @endif>{{ $rg['label'] }}</a>
        @endforeach
      </nav>
    @endunless
    <form class="hm-form" method="GET" action="{{ route('users.heatmap') }}">
      <input type="hidden" name="metric" value="{{ $filters['metric'] }}">
      @unless ($live)
        <input type="hidden" name="range" value="custom">
        <label class="sr-only" for="hm-from">From</label>
        <input id="hm-from" class="hm-control" type="date" name="from" value="{{ $filters['from'] ?? now($tz)->subDays(6)->toDateString() }}" max="{{ now($tz)->toDateString() }}">
        <span style="font-size:.8rem;color:var(--ink-2)">to</span>
        <label class="sr-only" for="hm-to">To</label>
        <input id="hm-to" class="hm-control" type="date" name="to" value="{{ $filters['to'] ?? now($tz)->toDateString() }}" max="{{ now($tz)->toDateString() }}">
      @endunless
      <label class="sr-only" for="hm-brgy">Barangay</label>
      <select id="hm-brgy" class="hm-control" name="barangay">
        <option value="">All barangays</option>
        @foreach ($barangayList as $b)<option value="{{ $b->id }}" @selected((int) ($filters['barangay'] ?? 0) === $b->id)>{{ $b->name }}</option>@endforeach
      </select>
      @if ($live && $filters['range'] !== 'custom')<input type="hidden" name="range" value="{{ $filters['range'] }}">@endif
      <button class="hm-btn" type="submit">Show</button>
    </form>
    <button class="hm-btn quiet" type="button" onclick="window.print()">Print</button>
  </div>
  @foreach (['from', 'to'] as $field)
    @error($field)<p class="hm-error">{{ $message }}</p>@enderror
  @endforeach

  <div class="hm-grid">
    <div class="hm-card hm-map">
      <div id="heatmap" role="region" aria-label="Heat map of clients per access point"></div>
      <div class="hm-legend" aria-hidden="true">
        <b>{{ $metricLabels[$filters['metric']] }}</b>
        <div class="ramp"></div>
        <div class="ends"><span>Fewer</span><span>More ({{ $num($topAps->first()['value'] ?? 0) }} max at one AP)</span></div>
      </div>
      @unless ($onMap)
        <div class="hm-empty">
          <div>
            <p style="margin:0 0 6px;font-weight:600;color:var(--ink)">No clients to show {{ $live ? 'right now' : 'for this period' }}.</p>
            <p style="margin:0">Client counts are read from access points over SNMP every minute. Access points need a map position, and a brand we can read (or their own client count OID set under Edit &gt; SNMP).</p>
          </div>
        </div>
      @endunless
    </div>

    <div class="hm-side">
      <div class="hm-card">
        <h2>{{ $live ? 'Clients connected now' : 'Clients at a time, on average' }}</h2>
        @php $total = $live ? collect($rows)->sum('now') : $totals['average']; @endphp
        <p class="hm-big">{{ $num($total) }} <small>on {{ number_format($totals['reporting']) }} of {{ number_format($totals['aps']) }} access points</small></p>
        @if (! $live && $totals['busiest'])
          <p class="hm-sub">Busiest hour: <b>{{ number_format($totals['busiest']['clients']) }}</b> clients, {{ $totals['busiest']['at']->copy()->setTimezone($tz)->format('D, M j, H:00') }}</p>
        @endif
      </div>

      <div class="hm-card">
        <h2>Busiest barangays</h2>
        <ul class="hm-list">
          @forelse (array_slice(array_values(array_filter($barangays, fn ($b) => $b['value'] > 0)), 0, 8) as $b)
            <li>
              <span>{{ $b['name'] }} <small>&middot; {{ $b['aps'] }} {{ Str::plural('AP', $b['aps']) }}</small></span><b>{{ $num($b['value']) }}</b>
              <span class="bar"><span style="width:{{ round($b['value'] / $maxBrgy * 100, 1) }}%"></span></span>
            </li>
          @empty
            <li><small>No client counts yet.</small></li>
          @endforelse
        </ul>
      </div>

      <div class="hm-card">
        <h2>Busiest access points</h2>
        <ul class="hm-list" id="hm-top">
          @forelse ($topAps as $i => $p)
            <li>
              <a href="#" data-focus="{{ $i }}">{{ $p['name'] }} <small>&middot; {{ $p['barangay'] ?? 'No barangay' }}</small></a><b>{{ $num($p['value']) }}</b>
              <span class="bar"><span style="width:{{ round($p['value'] / $maxAp * 100, 1) }}%"></span></span>
            </li>
          @empty
            <li><small>No client counts yet.</small></li>
          @endforelse
        </ul>
        @if ($missing > 0)
          <p class="hm-note">{{ $missing }} {{ Str::plural('access point', $missing) }} without a map position {{ $missing === 1 ? 'is' : 'are' }} not on the map; {{ $missing === 1 ? 'it is' : 'they are' }} in the table on the Users page.</p>
        @endif
      </div>
    </div>
  </div>
  <p class="hm-note">Average: clients at a time while each access point answered, over the period. Peak: the most at one check. Times in {{ $tz }}.</p>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet.heat/0.2.0/leaflet-heat.js"></script>
<script>
(function () {
  if (!window.L) return;
  const points = @json($points);
  const center = @json($mapCenter);
  const top = @json($topAps);
  const metric = @json($metricLabels[$filters['metric']]);

  const map = L.map('heatmap', { zoomControl: true }).setView([center.lat, center.lng], center.zoom);
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
  }).addTo(map);

  // Scale: the busiest area (access points within about 400 m added up) is the hottest
  // colour; nearby access points add up, so their single highest value would turn all red.
  const near = 0.004;
  const max = Math.max(1, ...points.map((p) => points
    .filter((q) => Math.abs(q.lat - p.lat) < near && Math.abs(q.lng - p.lng) < near)
    .reduce((sum, q) => sum + q.value, 0)));
  if (L.heatLayer) {
    L.heatLayer(points.filter((p) => p.value > 0).map((p) => [p.lat, p.lng, p.value]), {
      radius: 30, blur: 16, minOpacity: 0.3, max: max, maxZoom: 11, // full strength from city zoom up
      gradient: { 0.15: '#3B4CC0', 0.35: '#33C6E8', 0.5: '#7CE85A', 0.65: '#F8E33B', 0.8: '#F2862E', 1: '#D7191C' },
    }).addTo(map);
  }

  // A small dot per access point, with its number on hover
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmt = (v) => v >= 10 || Number.isInteger(v) ? Math.round(v).toLocaleString() : v.toFixed(1);
  const dots = points.map((p) => L.circleMarker([p.lat, p.lng], {
    radius: 2.5, weight: 1, color: '#0F1A1F', fillColor: '#fff', fillOpacity: 0.85,
  }).bindTooltip('<b>' + esc(p.name) + '</b><br>' + esc(p.barangay || 'No barangay') + (p.landmark ? ', ' + esc(p.landmark) : '')
      + '<br>' + metric + ': <b>' + fmt(p.value) + '</b>').addTo(map));

  // A center set in Settings wins; otherwise frame the access points. Again once the
  // page has finished loading: fonts and styles can still change the map's size.
  function frame() {
    map.invalidateSize();
    if (!center.fixed && points.length) {
      map.fitBounds(L.latLngBounds(points.map((p) => [p.lat, p.lng])), { padding: [40, 40], maxZoom: 15 });
    }
  }
  frame();
  if (document.readyState !== 'complete') window.addEventListener('load', frame, { once: true });

  // Busiest list: click to fly there
  document.querySelectorAll('#hm-top [data-focus]').forEach((a) => a.addEventListener('click', (e) => {
    e.preventDefault();
    const p = top[+a.dataset.focus];
    map.flyTo([p.lat, p.lng], Math.max(map.getZoom(), 17));
    const dot = dots[points.findIndex((x) => x.lat === p.lat && x.lng === p.lng && x.name === p.name)];
    if (dot) setTimeout(() => dot.openTooltip(), 600);
  }));

  window.addEventListener('beforeprint', () => map.invalidateSize());
})();
</script>
@endsection
