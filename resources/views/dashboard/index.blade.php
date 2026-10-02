<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Network Operations | Public WiFi Control</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet.markercluster/1.5.3/MarkerCluster.min.css">
<link href="https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
@include('dashboard._styles')
</head>
<body>

@php
  $k = $kpis;

  // Access points by barangay (live)
  $allAps = collect($apGrid)->flatMap(fn ($g) => $g['aps']);
  $apStatus = $allAps->countBy('status');
  $maxClients = max(1, (int) $allAps->max('clients'));

@endphp

<header class="bar">
  <a class="brand" href="{{ route('dashboard') }}">
    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#2CD5FF" stroke-width="2" stroke-linecap="round" aria-hidden="true" style="filter:drop-shadow(0 0 6px rgba(44,213,255,.7))">
      <path d="M2 8.5a15 15 0 0 1 20 0"/><path d="M5.5 12.5a10 10 0 0 1 13 0"/><path d="M9 16.3a5 5 0 0 1 6 0"/><circle cx="12" cy="20" r="1.3" fill="#2CD5FF" stroke="none"/>
    </svg>
    <span><strong>Network Operations</strong><span>Public WiFi Control</span></span>
  </a>
  <nav aria-label="Main">
    <a href="{{ route('dashboard') }}" aria-current="page">Dashboard</a>
    @if (auth()->user()?->isAdmin())
      @include('partials.devices-menu')
    @endif
    {{-- Same items as the main menu in layouts/app --}}
    @if (auth()->user()?->hasRole('user'))
      <a href="{{ route('users.index') }}">Users</a>
    @endif
    @if (auth()->user()?->isAdmin())
      <a href="{{ route('radius') }}">RADIUS</a>
      <a href="{{ route('splash.edit') }}">Captive portal</a>
    @endif
  </nav>
  <p class="health" role="status" id="health"><b>{{ $k['routers']['online'] }} of {{ $k['routers']['total'] }}</b> routers online</p>
  <div class="clock"><time id="clock">--:--:--</time><span id="date">Philippine time</span></div>
  @include('partials.account-menu')
</header>

@if (session('denied'))
  <p class="sample" role="alert" style="color:#FFD7D2;border-color:#FF5470">{{ session('denied') }}</p>
@endif

<main class="deck">

  {{-- ---------- Readouts (live; refreshed by the script at the bottom) ---------- --}}
  <div id="kpi-row" class="kpi-row" aria-live="off">
    @include('dashboard._kpis', ['k' => $k])
  </div>

  {{-- Busy / full routers and address pools (live) --}}
  <div id="alerts-row" class="kpi-row">
    @include('dashboard._alerts', ['alerts' => $alerts])
  </div>

  {{-- ---------- Device map (live) ---------- --}}
  @include('dashboard._map')

  {{-- ---------- Clients by barangay (live; refreshed with the top row) ---------- --}}
  <section class="panel top" aria-labelledby="top-title">
    <div class="panel-head">
      <h2 id="top-title">Busiest Locations Now</h2>
      <p>Clients on access points</p>
    </div>
    <div id="barangay-clients">
      @include('dashboard._barangays', ['rows' => $barangayClients])
    </div>
  </section>

  {{-- ---------- Access points by barangay (live) ---------- --}}
  <section class="panel sites" aria-labelledby="sites-title">
    <div class="panel-head">
      <h2 id="sites-title">Access points by barangay</h2>
      <div class="legend">
        <span class="l-on"><b>{{ $apStatus['online'] ?? 0 }}</b> online</span>
        <span class="l-off"><b>{{ $apStatus['offline'] ?? 0 }}</b> offline</span>
        <span class="l-unk"><b>{{ $apStatus['unknown'] ?? 0 }}</b> not checked</span>
      </div>
    </div>
    @if ($allAps->isEmpty())
      <p class="grid-empty">No access points yet.<a href="{{ route('aps.index', ['add' => 1]) }}">Add access point</a></p>
    @else
      <div class="grid" id="ap-grid">
        @foreach ($apGrid as $g)
          <div class="grid-row">
            <h3 title="{{ $g['barangay'] }}">{{ $g['barangay'] }}<small>{{ count($g['aps']) }}</small></h3>
            <ul class="cells" aria-label="Access points in {{ $g['barangay'] }}">
              @foreach ($g['aps'] as $ap)
                <li>
                  <a class="cell {{ $ap['status'] }}" href="{{ $ap['edit'] }}" tabindex="-1"
                     data-ap="{{ json_encode([...$ap, 'barangay' => $g['barangay']]) }}"
                     style="--load:{{ $ap['clients'] === null ? 1 : round(0.35 + 0.65 * $ap['clients'] / $maxClients, 2) }}"
                     aria-label="{{ $ap['name'] }}, {{ $ap['status'] === 'unknown' ? 'not checked yet' : $ap['status'] }}, {{ $ap['clients'] === null ? 'clients not collected' : $ap['clients'].' clients' }}, {{ $ap['utilization'] === null ? 'utilization not collected' : $ap['utilization'].' percent utilization' }}"></a>
                </li>
              @endforeach
            </ul>
          </div>
        @endforeach
      </div>
      <p class="grid-note">Each light is one access point. Hover, or tab in and use the arrow keys, to see its details. Click to edit it.</p>
    @endif
    <div class="ap-tip" id="ap-tip" role="tooltip" hidden></div>
  </section>

  {{-- ---------- Users online over time (live) ---------- --}}
  <section class="panel chart" aria-labelledby="chart-title">
    <div class="panel-head">
      <h2 id="chart-title">Users online</h2>
      <div class="seg" role="group" aria-label="Chart period">
        @foreach (\App\Services\Dashboard\UserHistory::RANGES as $key => $r)
          <button type="button" data-range="{{ $key }}" aria-pressed="{{ $key === 'day' ? 'true' : 'false' }}">{{ $r['label'] }}</button>
        @endforeach
      </div>
    </div>
    <div id="chart-body" aria-live="polite">
      @include('dashboard._chart', ['s' => $chart])
    </div>
  </section>

  {{-- ---------- Event log ---------- --}}
  <section class="panel events" aria-labelledby="events-title">
    <div class="panel-head">
      <h2 id="events-title">Recent events</h2>
      <p><a href="{{ route('logs') }}" style="color:inherit">Last 24 hours &rsaquo; all</a></p>
    </div>
    <ul class="log">
      @forelse ($events as $e)
        <li class="{{ $e['level'] }}"><time>{{ $e['time'] }}</time><i aria-hidden="true"></i><span>{{ $e['text'] }}</span></li>
      @empty
        <li class="info"><time>&nbsp;</time><i aria-hidden="true"></i><span>Nothing in the last 24 hours. Routers, access points and switches going down or coming back appear here.</span></li>
      @endforelse
    </ul>
  </section>

</main>

<script>
(function () {
  const grid = document.getElementById('ap-grid'), tip = document.getElementById('ap-tip');
  if (!grid) return;
  const cells = Array.from(grid.querySelectorAll('.cell'));
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const statusText = { online: 'Online', offline: 'Offline', unknown: 'Not checked yet' };
  const na = '<span class="na">Not collected yet</span>';

  function show(cell) {
    const ap = JSON.parse(cell.dataset.ap);
    const u = ap.utilization;
    tip.innerHTML = '<h4>' + esc(ap.name) + '</h4>'
      + '<span class="st ' + esc(ap.status) + '">' + statusText[ap.status] + '</span>'
      + (ap.status === 'offline' && ap.seen ? ', last seen ' + esc(ap.seen) : '')
      + '<dl>'
      + '<dt>Clients</dt><dd>' + (ap.clients === null ? na : esc(ap.clients)) + '</dd>'
      + '<dt>Utilization</dt><dd>' + (u === null ? na : esc(u) + '%'
          + '<div class="meter"><span class="' + (u >= 80 ? 'high' : u >= 60 ? 'warn' : '') + '" style="width:' + Math.min(100, u) + '%"></span></div>') + '</dd>'
      + '</dl>'
      + '<p class="where">' + esc(ap.barangay) + (ap.landmark ? ', ' + esc(ap.landmark) : '') + '<br>' + esc(ap.ip)
      + (ap.model ? ', ' + esc(ap.model) : '') + '</p>';
    tip.hidden = false;

    // Above the light, or below if there's no room; kept inside the window.
    const r = cell.getBoundingClientRect(), t = tip.getBoundingClientRect();
    let top = r.top - t.height - 10;
    if (top < 70) top = r.bottom + 10;
    const left = Math.max(8, Math.min(window.innerWidth - t.width - 8, r.left + r.width / 2 - t.width / 2));
    tip.style.top = top + 'px';
    tip.style.left = left + 'px';
    cell.setAttribute('aria-describedby', 'ap-tip');
  }
  function hide(cell) {
    tip.hidden = true;
    if (cell) cell.removeAttribute('aria-describedby');
  }

  cells.forEach((cell) => {
    cell.addEventListener('mouseenter', () => show(cell));
    cell.addEventListener('mouseleave', () => { if (document.activeElement !== cell) hide(cell); });
    cell.addEventListener('focus', () => show(cell));
    cell.addEventListener('blur', () => hide(cell));
  });
  window.addEventListener('scroll', () => hide(), { passive: true });

  // One tab stop for the whole grid; arrow keys move between lights.
  cells[0].tabIndex = 0;
  const rows = Array.from(grid.querySelectorAll('.cells')).map((ul) => Array.from(ul.querySelectorAll('.cell')));
  function move(from, to) {
    if (!to) return;
    from.tabIndex = -1;
    to.tabIndex = 0;
    to.focus();
  }
  grid.addEventListener('keydown', (e) => {
    const cell = e.target.closest('.cell');
    if (!cell) return;
    const ri = rows.findIndex((r) => r.includes(cell)), ci = rows[ri].indexOf(cell);
    const at = (r, c) => rows[r] && rows[r][Math.min(c, rows[r].length - 1)];
    const next = {
      ArrowRight: rows[ri][ci + 1] || at(ri + 1, 0),
      ArrowLeft: rows[ri][ci - 1] || (rows[ri - 1] && rows[ri - 1][rows[ri - 1].length - 1]),
      ArrowDown: at(ri + 1, ci),
      ArrowUp: at(ri - 1, ci),
      Home: rows[ri][0],
      End: rows[ri][rows[ri].length - 1],
    }[e.key];
    if (e.key === 'Escape') { hide(cell); return; }
    if (next !== undefined) { e.preventDefault(); move(cell, next); }
  });
})();
</script>

<script>
(function () {
  var clock = document.getElementById('clock'), date = document.getElementById('date');
  var t = new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
  var d = new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila', weekday: 'short', month: 'short', day: 'numeric' });
  function tick() {
    var now = new Date();
    clock.textContent = t.format(now);
    date.textContent = d.format(now) + ', Philippine time';
  }
  tick();
  setInterval(tick, 1000);
})();
</script>

<script>
(function () {
  // Top row: users online and routers come from routers:poll (every 30 s by default),
  // switches and access points from devices:poll. Re-fetched every 10 seconds while the tab is visible.
  const row = document.getElementById('kpi-row'), health = document.getElementById('health');
  const url = @json(route('dashboard.live'));
  let busy = false;
  async function refresh() {
    if (busy || document.hidden) return;
    busy = true;
    try {
      const res = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
      if (!res.ok) return;
      const data = await res.json();
      row.innerHTML = data.html;
      document.getElementById('barangay-clients').innerHTML = data.barangays;
      document.getElementById('alerts-row').innerHTML = data.alerts;
      health.innerHTML = '<b>' + data.routers.online + ' of ' + data.routers.total + '</b> routers online';
    } catch (e) {
      // offline or server restarting: keep the last numbers
    } finally {
      busy = false;
    }
  }
  setInterval(refresh, 10000);
  document.addEventListener('visibilitychange', refresh);
})();
</script>

<script>
(function () {
  // Users online chart: Day / Week / Month / Year. The choice is remembered on this browser.
  const body = document.getElementById('chart-body');
  const buttons = document.querySelectorAll('[data-range]');
  const url = @json(route('dashboard.users-chart'));
  let range = 'day';
  try { range = localStorage.getItem('dashboard.chartRange') || 'day'; } catch (e) {}
  if (![...buttons].some((b) => b.dataset.range === range)) range = 'day';

  async function load(next) {
    range = next;
    buttons.forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.range === range)));
    try { localStorage.setItem('dashboard.chartRange', range); } catch (e) {}
    body.classList.add('loading');
    try {
      const res = await fetch(url + '?range=' + encodeURIComponent(range), { headers: { Accept: 'text/html' }, cache: 'no-store' });
      if (res.ok) body.innerHTML = await res.text();
    } catch (e) {
      // keep the last chart
    } finally {
      body.classList.remove('loading');
    }
  }

  buttons.forEach((b) => b.addEventListener('click', () => load(b.dataset.range)));
  if (range !== 'day') load(range);
  setInterval(() => { if (!document.hidden) load(range); }, 60000);
})();
</script>

@include('dashboard._map-script')
</body>
</html>
