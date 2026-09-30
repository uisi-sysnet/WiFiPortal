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
<style>
:root{
  --void:#030A12;      /* page */
  --deck:#07131F;      /* panels */
  --deck-2:#0B1B2B;    /* raised rows */
  --neon:#2CD5FF;      /* primary signal */
  --neon-deep:#0A7EA8;
  --text:#D6ECF5;
  --muted:#7A97A8;
  --line:rgba(44,213,255,.16);
  --ok:#3CF0B0; --warn:#FFB547; --down:#FF5470;
  --glow:0 0 18px rgba(44,213,255,.45);
  --display:"Chakra Petch","Segoe UI",system-ui,sans-serif;
  --body:"IBM Plex Sans",system-ui,-apple-system,"Segoe UI",sans-serif;
}
*{box-sizing:border-box}
html,body{margin:0}
body{
  min-height:100vh;color:var(--text);font:15px/1.5 var(--body);
  background:
    radial-gradient(1200px 600px at 70% -10%,rgba(44,213,255,.10),transparent 60%),
    linear-gradient(rgba(44,213,255,.045) 1px,transparent 1px) 0 0/32px 32px,
    linear-gradient(90deg,rgba(44,213,255,.045) 1px,transparent 1px) 0 0/32px 32px,
    var(--void);
}
a{color:var(--neon)}
:focus-visible{outline:2px solid var(--neon);outline-offset:3px;box-shadow:var(--glow)}

/* ---------- Top bar ---------- */
.bar{display:flex;align-items:center;gap:28px;padding:14px 24px;border-bottom:1px solid var(--line);background:rgba(3,10,18,.85);backdrop-filter:blur(6px);position:sticky;top:0;z-index:1100}
.brand{display:flex;align-items:center;gap:12px;text-decoration:none;color:var(--text)}
.brand strong{display:block;font:600 1.2rem/1.1 var(--display);letter-spacing:.02em;color:var(--text)}
.brand span{display:block;font-size:.8rem;color:var(--muted)}
.bar nav{display:flex;gap:20px;flex:1}
.bar nav a{color:var(--muted);text-decoration:none;padding:6px 0;border-bottom:2px solid transparent;white-space:nowrap}
.bar nav a:hover{color:var(--text)}
.bar nav a[aria-current="page"]{color:var(--text);border-bottom-color:var(--neon);box-shadow:0 6px 12px -8px var(--neon)}
.health{display:flex;align-items:center;gap:8px;font-size:.9rem;color:var(--muted)}
.health b{color:var(--text);font-weight:600}
.health::before{content:"";width:9px;height:9px;border-radius:50%;background:var(--ok);box-shadow:0 0 10px var(--ok)}
.clock{text-align:right;line-height:1.1}
.clock time{display:block;font:600 1.45rem var(--display);color:var(--neon);text-shadow:var(--glow);font-variant-numeric:tabular-nums}
.clock span{font-size:.78rem;color:var(--muted)}
.bar .dm{
  --dm-link:var(--muted);--dm-link-active:var(--text);--dm-accent:var(--neon);
  --dm-bg:#0B1B2B;--dm-fg:var(--text);--dm-muted:var(--muted);--dm-line:var(--line);--dm-hover:rgba(44,213,255,.10);
  --dm-shadow:0 14px 40px rgba(0,0,0,.6),0 0 0 1px rgba(44,213,255,.08),0 0 24px rgba(44,213,255,.12);
}
.bar .dm-trigger{padding:6px 0}
.bar .acct{
  --acct-trigger:var(--neon);--acct-trigger-line:var(--line);--acct-avatar-bg:rgba(44,213,255,.12);
  --acct-bg:#0B1B2B;--acct-fg:var(--text);--acct-muted:var(--muted);--acct-line:var(--line);
  --acct-hover:rgba(44,213,255,.10);--acct-danger:var(--down);
  --acct-shadow:0 14px 40px rgba(0,0,0,.6),0 0 0 1px rgba(44,213,255,.08),0 0 24px rgba(44,213,255,.12);
}
.bar .acct-avatar{font-family:var(--display);text-shadow:var(--glow)}

.sample{margin:0;padding:8px 24px;font-size:.84rem;color:var(--warn);background:rgba(255,181,71,.06);border-bottom:1px solid rgba(255,181,71,.18)}

/* ---------- Deck ---------- */
.deck{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:16px;padding:20px 24px 40px;max-width:1800px;margin:0 auto}
.panel{grid-column:span 12;background:linear-gradient(180deg,rgba(11,27,43,.9),rgba(7,19,31,.92));border:1px solid var(--line);border-radius:6px;padding:18px 20px;min-width:0}
.panel h2{margin:0;font:600 1.05rem var(--display);letter-spacing:.02em}
.panel-head{display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:8px 16px;margin-bottom:14px}
.panel-head p{margin:0;font-size:.85rem;color:var(--muted)}

/* KPI readouts: notched like instrument plates */
.kpi{
  grid-column:span 3;position:relative;padding:18px 20px 20px;border:0;border-radius:0;
  background:linear-gradient(160deg,#0D2236,#07131F 60%);
  clip-path:polygon(16px 0,100% 0,100% calc(100% - 16px),calc(100% - 16px) 100%,0 100%,0 16px);
  box-shadow:inset 0 0 0 1px var(--line);
}
.kpi::before{content:"";position:absolute;top:0;left:16px;width:38%;height:2px;background:var(--neon);box-shadow:var(--glow)}
.kpi h2{font:500 .95rem var(--body);color:var(--muted);letter-spacing:0}
.kpi .value{margin:6px 0 2px;font:600 clamp(2.4rem,3.6vw,3.3rem)/1 var(--display);color:#EAF8FF;text-shadow:var(--glow);font-variant-numeric:tabular-nums}
.kpi .value-row{display:flex;align-items:baseline;justify-content:space-between;gap:12px}
.kpi .pct{margin:0;text-align:right;font:600 clamp(1.4rem,2vw,1.8rem)/1 var(--display);font-variant-numeric:tabular-nums}
.kpi .pct small{display:block;margin-top:4px;font:500 .78rem var(--body);color:var(--muted)}
.kpi .pct.good{color:var(--neon);text-shadow:var(--glow)}
.kpi .pct.fair{color:var(--warn)}
.kpi .pct.poor{color:var(--down);text-shadow:0 0 14px rgba(255,84,112,.45)}
.kpi .pct.none{color:var(--muted)}
.kpi .sub .unk{color:var(--muted)}
.kpi .sub{margin:0 0 14px;font-size:.9rem;color:var(--muted)}
.kpi .sub b{color:var(--text);font-weight:600}
.kpi .sub .down{color:var(--down);font-weight:600}
.leds{display:flex;gap:3px;height:12px}
.leds i{flex:1;border-radius:1px;background:rgba(44,213,255,.12)}
.leds i.on{background:var(--neon);box-shadow:0 0 6px rgba(44,213,255,.7)}
.leds i.off{background:var(--down);box-shadow:0 0 6px rgba(255,84,112,.6)}
.spark{display:block;width:100%;height:40px}

/* Site grid: every router is one light */
/* ---------- Device map ---------- */
.mapp{grid-column:span 9}  /* 75% */
.map-tools{display:flex;flex-wrap:wrap;align-items:center;gap:10px 18px;font-size:.88rem;color:var(--muted)}
.map-tools label{display:inline-flex;align-items:center;gap:7px;cursor:pointer}
.map-tools input[type=checkbox]{width:16px;height:16px;accent-color:var(--neon);cursor:pointer}
.map-tools select{font:inherit;font-size:.86rem;padding:5px 8px;border:1px solid var(--line);border-radius:4px;background:#0B1B2B;color:var(--text)}
.map-wrap{position:relative;isolation:isolate} /* keeps Leaflet's z-indexes (400-1000) inside the map */
#map{height:clamp(380px,58vh,640px);border:1px solid var(--line);border-radius:4px;background:#030A12}
.map-empty{position:absolute;inset:0;z-index:500;display:grid;place-items:center;text-align:center;padding:24px;background:rgba(3,10,18,.72);border-radius:4px}
.map-empty[hidden]{display:none}
.map-empty p{margin:0 0 12px;max-width:44ch;color:var(--text)}
.map-empty a{display:inline-block;margin:0 6px;padding:7px 14px;border:1px solid var(--neon);border-radius:4px;text-decoration:none}
.map-foot{display:flex;flex-wrap:wrap;justify-content:space-between;gap:6px 16px;margin:10px 0 0;font-size:.84rem;color:var(--muted)}
.map-foot p{margin:0}
.map-foot b{color:var(--text);font-weight:600}
.map-foot .off{color:var(--down)}

/* Pins: circle = access point, square = switch; colour = status */
.pin{border-radius:50%;background:var(--neon);box-shadow:0 0 0 2px #030A12,0 0 10px 2px rgba(44,213,255,.75)}
.pin.pin-switch{border-radius:2px}
.pin.unknown{background:#6F8FA3;box-shadow:0 0 0 2px #030A12}
.pin.offline{background:var(--down);box-shadow:0 0 0 2px #030A12,0 0 10px 2px rgba(255,84,112,.8)}
.pin.offline::after{content:"";position:absolute;inset:-6px;border-radius:inherit;border:2px solid var(--down);animation:ping 1.8s ease-out infinite}
@keyframes ping{from{transform:scale(.6);opacity:.9}to{transform:scale(1.6);opacity:0}}
.legend-pin{display:inline-block;width:10px;height:10px;margin-right:2px;vertical-align:-1px}

/* Clusters */
.cl{display:grid;place-items:center;border-radius:50%;background:rgba(44,213,255,.18);border:2px solid var(--neon);box-shadow:0 0 14px rgba(44,213,255,.5)}
.cl span{font:600 .9rem var(--display);color:#EAF8FF}
.cl.cl-off{border-color:var(--down);background:rgba(255,84,112,.18);box-shadow:0 0 14px rgba(255,84,112,.5)}

/* Leaflet in the dark theme */
.leaflet-container{font:inherit;background:#030A12}
.leaflet-container a{color:var(--neon)}
/* Turns OpenStreetMap's light map into a dark, blue-tinted one */
.tiles-dark{filter:invert(1) hue-rotate(185deg) brightness(.82) contrast(.92) saturate(.55)}
.leaflet-bar a,.leaflet-bar a:hover{background:#0B1B2B;color:var(--text);border-bottom-color:var(--line)}
.leaflet-bar{border:1px solid var(--line)!important;box-shadow:none!important}
.leaflet-control-attribution{background:rgba(3,10,18,.75)!important;color:var(--muted)}
.leaflet-control-attribution a{color:var(--neon)}
.leaflet-popup-content-wrapper,.leaflet-popup-tip{background:#0B1B2B;color:var(--text);border:1px solid var(--line);box-shadow:0 10px 30px rgba(0,0,0,.6)}
.leaflet-popup-content{margin:12px 14px;font-size:.88rem;line-height:1.45}
.leaflet-popup-content h3{margin:0 0 4px;font:600 1rem var(--display)}
.leaflet-popup-content dl{display:grid;grid-template-columns:auto 1fr;gap:2px 10px;margin:8px 0}
.leaflet-popup-content dt{color:var(--muted)}
.leaflet-popup-content dd{margin:0}
.leaflet-container a.leaflet-popup-close-button{color:var(--muted)}
.pop-status{font-weight:600}
.pop-status.online{color:var(--neon)}.pop-status.offline{color:var(--down)}.pop-status.unknown{color:var(--muted)}

.sites{grid-column:span 12}
.legend{display:flex;flex-wrap:wrap;gap:14px;font-size:.85rem;color:var(--muted)}
.legend span::before{content:"";display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:6px;vertical-align:-1px}
.legend .l-on::before{background:var(--neon);box-shadow:0 0 6px var(--neon)}
.legend .l-unk::before{background:#6F8FA3}
.legend .l-off::before{background:var(--down)}
.legend b{color:var(--text);font-weight:600}
.grid{position:relative;display:grid;gap:8px;overflow:hidden}
.grid-row{display:grid;grid-template-columns:120px 1fr;align-items:center;gap:12px}
.grid-row h3{margin:0;font:500 .88rem var(--body);color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cells{display:flex;flex-wrap:wrap;gap:5px;margin:0;padding:0;list-style:none}
.cells li{display:block}
.cell{display:block;width:26px;height:26px;border-radius:3px;background:var(--neon);opacity:var(--load,1);box-shadow:0 0 8px rgba(44,213,255,.55);transition:transform .12s}
.cell.offline{background:transparent;opacity:1;box-shadow:inset 0 0 0 2px var(--down),0 0 8px rgba(255,84,112,.45)}
.cell.unknown{background:#3E5563;opacity:1;box-shadow:inset 0 0 0 1px #6F8FA3}
.cell:hover,.cell:focus-visible{transform:scale(1.18);opacity:1;outline:2px solid #EAF8FF;outline-offset:1px;box-shadow:0 0 14px rgba(44,213,255,.9)}
.grid-row h3 small{margin-left:6px;font-size:.78rem;color:#5B7A8C}
.grid-empty{margin:0;padding:28px 0;color:var(--muted)}
.grid-empty a{margin-left:6px}

/* Hover card for one access point */
.ap-tip{position:fixed;z-index:1200;min-width:230px;max-width:280px;padding:12px 14px;border:1px solid var(--line);border-radius:6px;background:#0B1B2B;color:var(--text);font-size:.88rem;box-shadow:0 14px 40px rgba(0,0,0,.6),0 0 22px rgba(44,213,255,.14);pointer-events:none}
.ap-tip[hidden]{display:none}
.ap-tip h4{margin:0;font:600 1.02rem var(--display);color:#EAF8FF}
.ap-tip .st{font-weight:600}
.ap-tip .st.online{color:var(--neon)}.ap-tip .st.offline{color:var(--down)}.ap-tip .st.unknown{color:var(--muted)}
.ap-tip dl{display:grid;grid-template-columns:auto 1fr;gap:4px 12px;margin:10px 0 0}
.ap-tip dt{color:var(--muted)}
.ap-tip dd{margin:0;font-variant-numeric:tabular-nums}
.ap-tip .na{color:var(--muted);font-style:italic}
.ap-tip .meter{height:6px;margin-top:5px;border-radius:3px;background:rgba(44,213,255,.12);overflow:hidden}
.ap-tip .meter span{display:block;height:100%;background:var(--neon)}
.ap-tip .meter span.warn{background:var(--warn)}.ap-tip .meter span.high{background:var(--down)}
.ap-tip .where{margin:10px 0 0;padding-top:8px;border-top:1px solid var(--line);color:var(--muted);font-size:.82rem}
.grid::after{
  content:"";position:absolute;top:0;bottom:0;left:0;width:90px;pointer-events:none;
  background:linear-gradient(90deg,transparent,rgba(44,213,255,.16),transparent);
  animation:sweep 7s linear infinite;
}
@keyframes sweep{from{transform:translateX(-120px)}to{transform:translateX(1400px)}}
.grid-note{margin:14px 0 0;font-size:.82rem;color:var(--muted)}

/* Event log */
.events{grid-column:span 4;display:flex;flex-direction:column}
.log{margin:0;padding:0;list-style:none;overflow:auto;max-height:420px}
.log li{display:grid;grid-template-columns:52px 12px 1fr;gap:10px;align-items:baseline;padding:9px 2px;border-bottom:1px solid rgba(44,213,255,.08);font-size:.9rem}
.log li:last-child{border-bottom:0}
.log time{font:600 .9rem var(--display);color:var(--muted);font-variant-numeric:tabular-nums}
.log i{width:8px;height:8px;border-radius:50%;align-self:center}
.log .ok i{background:var(--ok);box-shadow:0 0 8px var(--ok)}
.log .warn i{background:var(--warn)}
.log .down i{background:var(--down);box-shadow:0 0 8px var(--down)}
.log .info i{background:var(--neon)}

/* Users chart */
.chart{grid-column:span 8}
.chart svg{display:block;width:100%;height:auto}
.chart .axis{fill:var(--muted);font:500 11px var(--body)}
.chart .gridline{stroke:rgba(44,213,255,.10);stroke-width:1}
.peak{font:600 1.1rem var(--display);color:var(--neon)}

/* Busiest sites */
.top{grid-column:span 3;display:flex;flex-direction:column}  /* 25% */
.bars{margin:0;padding:0;list-style:none;display:grid;gap:12px}
.top .bars{flex:1;align-content:space-between} /* spreads the list to the map's height */
.bars li{display:grid;gap:5px}
.bars .row{display:flex;justify-content:space-between;gap:8px;font-size:.9rem}
.bars .row b{font:600 .95rem var(--display);color:var(--text);font-variant-numeric:tabular-nums}
.bars .row small{color:var(--muted)}
.top .bars .row span{min-width:0}
.top .bars .row small{display:block}
.track{height:6px;border-radius:3px;background:rgba(44,213,255,.10);overflow:hidden}
.track span{display:block;height:100%;background:linear-gradient(90deg,var(--neon-deep),var(--neon));box-shadow:0 0 8px rgba(44,213,255,.6)}

@media (max-width:1280px){
  .kpi{grid-column:span 6}
  .mapp,.top,.sites,.chart,.events{grid-column:span 12}
}
@media (max-width:760px){
  .bar{flex-wrap:wrap;gap:10px 18px;padding:12px 16px;position:static}
  .bar nav{order:3;flex-basis:100%;overflow-x:auto}
  .health{display:none}
  .deck{padding:16px 12px 32px;gap:12px}
  .kpi,.events,.top{grid-column:span 12}
  .grid-row{grid-template-columns:1fr}
}
@media (prefers-reduced-motion:reduce){.cell{transition:none}.grid::after{animation:none;display:none}.pin.offline::after{animation:none;display:none}}
</style>
</head>
<body>

@php
  $k = $kpis;
  // 40-segment bar: lit = online share; at least one red segment whenever anything is down
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
  $maxSiteUsers = max(1, collect($sites)->max('users')); // busiest-sites panel (sample)

  // Access points by barangay (live)
  $allAps = collect($apGrid)->flatMap(fn ($g) => $g['aps']);
  $apStatus = $allAps->countBy('status');
  $maxClients = max(1, (int) $allAps->max('clients'));

  // Users-by-hour chart geometry
  $W = 760; $H = 230; $L = 46; $R = 10; $T = 14; $B = 28;
  $yMax = (int) (ceil(max($hourly) / 5000) * 5000);
  $x = fn ($i) => $L + $i * ($W - $L - $R) / 23;
  $y = fn ($v) => $T + ($H - $T - $B) * (1 - $v / $yMax);
  $line = collect($hourly)->map(fn ($v, $i) => ($i ? 'L' : 'M').round($x($i), 1).','.round($y($v), 1))->implode(' ');
  $area = $line.' L'.round($x(23), 1).','.($H - $B).' L'.$L.','.($H - $B).' Z';

  // Sparkline for the users readout
  $sx = fn ($i) => $i * 200 / 23;
  $sy = fn ($v) => 38 - 34 * $v / max($hourly);
  $spark = collect($hourly)->map(fn ($v, $i) => ($i ? 'L' : 'M').round($sx($i), 1).','.round($sy($v), 1))->implode(' ');
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
    @include('partials.devices-menu')
    <a href="{{ route('splash.edit') }}">Captive portal</a>
  </nav>
  <p class="health" role="status"><b>{{ $k['routers']['online'] }} of {{ $k['routers']['total'] }}</b> routers online</p>
  <div class="clock"><time id="clock">--:--:--</time><span id="date">Philippine time</span></div>
  @include('partials.account-menu')
</header>

<p class="sample">Access points, switches, the map and the access point grid are live. Routers, users, busiest sites, events and the chart are still sample data.</p>

<main class="deck">

  {{-- ---------- Readouts ---------- --}}
  @foreach ([
      ['Routers', $k['routers'], 'online'],
      ['Access points', $k['aps'], 'online'],
      ['Switches', $k['switches'], 'online'],
  ] as $i => [$label, $d, $word])
    @if ($i === 1)
      {{-- Users sits second: it is the number people look for first after routers --}}
      <section class="panel kpi" aria-labelledby="kpi-users">
        <h2 id="kpi-users">Users online</h2>
        <p class="value">{{ number_format($k['users']['online']) }}</p>
        <p class="sub"><b>{{ number_format($k['users']['today']) }}</b> new today, peak {{ number_format($k['users']['peak']) }}</p>
        <svg class="spark" viewBox="0 0 200 40" preserveAspectRatio="none" aria-hidden="true">
          <path d="{{ $spark }} L200,40 L0,40 Z" fill="rgba(44,213,255,.12)"/>
          <path d="{{ $spark }}" fill="none" stroke="#2CD5FF" stroke-width="1.6" vector-effect="non-scaling-stroke" style="filter:drop-shadow(0 0 4px rgba(44,213,255,.8))"/>
        </svg>
      </section>
    @endif
    @php
      $down = $d['offline'] ?? ($d['total'] - $d['online']);
      $unchecked = $d['total'] - $d['online'] - $down;
      [$on, $off, $idle] = $leds($d['online'], $down, $d['total']);
    @endphp
    <section class="panel kpi" aria-labelledby="kpi-{{ $i }}">
      <h2 id="kpi-{{ $i }}">{{ $label }}</h2>
      <div class="value-row">
        <p class="value">{{ number_format($d['total']) }}</p>
        {{-- Dash until at least one device has actually been checked --}}
        @php $base = ($d['online'] + $down) > 0 ? $d['total'] : 0; @endphp
        <p class="pct {{ $pctClass($d['online'], $base) }}">{{ $pct($d['online'], $base) }}<small>online</small></p>
      </div>
      @php
        // Online and offline are always shown (even when 0); "not checked" only when there are some.
        $parts = [
            '<b>'.number_format($d['online']).'</b> '.e($word),
            '<span class="'.($down ? 'down' : 'unk').'">'.number_format($down).' offline</span>',
        ];
        if ($unchecked) { $parts[] = '<span class="unk">'.number_format($unchecked).' not checked</span>'; }
      @endphp
      <p class="sub">{!! implode(', ', $parts) !!}</p>
      <div class="leds" role="img" aria-label="{{ $d['total'] ? round($d['online'] / $d['total'] * 100, 1).' percent online' : 'none added yet' }}">
        @for ($s = 0; $s < $on; $s++)<i class="on"></i>@endfor
        @for ($s = 0; $s < $idle; $s++)<i></i>@endfor
        @for ($s = 0; $s < $off; $s++)<i class="off"></i>@endfor
      </div>
    </section>
  @endforeach

  {{-- ---------- Device map (live) ---------- --}}
  @php
    $mapAps = collect($mapDevices)->where('type', 'ap')->count();
    $mapSw = collect($mapDevices)->where('type', 'switch')->count();
  @endphp
  <section class="panel mapp" aria-labelledby="map-title">
    <div class="panel-head">
      <h2 id="map-title">Access points and switches</h2>
      <div class="map-tools">
        <label><input type="checkbox" id="show-ap" checked><span class="legend-pin pin" aria-hidden="true"></span>Access points</label>
        <label><input type="checkbox" id="show-switch" checked><span class="legend-pin pin pin-switch" aria-hidden="true"></span>Switches</label>
        <label>Status
          <select id="map-status">
            <option value="all">All</option>
            <option value="offline">Offline only</option>
            <option value="online">Online only</option>
          </select>
        </label>
        <label>Zoom to
          <select id="map-barangay">
            <option value="">All barangays</option>
            @foreach ($mapBarangays as $b)<option>{{ $b }}</option>@endforeach
          </select>
        </label>
      </div>
    </div>
    <div class="map-wrap">
      <div id="map" role="region" aria-label="Map of access points and switches"></div>
      <div class="map-empty" id="map-empty" @if(count($mapDevices)) hidden @endif>
        <div>
          <p>No access point or switch has a map position yet. Add one with its latitude and longitude and it appears here.</p>
          <a href="{{ route('aps.index', ['add' => 1]) }}">Add access point</a>
          <a href="{{ route('switches.index', ['add' => 1]) }}">Add switch</a>
        </div>
      </div>
    </div>
    <div class="map-foot">
      <p id="map-summary" aria-live="polite"><b>{{ $mapAps }}</b> access points and <b>{{ $mapSw }}</b> switches on the map.</p>
      <p>Circle is an access point, square is a switch. Red and pulsing means offline. Refreshes every minute<span id="map-updated"></span>.</p>
    </div>
  </section>

  {{-- ---------- Busiest sites ---------- --}}
  <section class="panel top" aria-labelledby="top-title">
    <div class="panel-head">
      <h2 id="top-title">Busiest sites now</h2>
      <p>Users per router</p>
    </div>
    <ol class="bars">
      @foreach ($top as $site)
        <li>
          <div class="row"><span>{{ $site['name'] }} <small>{{ $site['barangay'] }}</small></span><b>{{ $site['users'] }}</b></div>
          <div class="track"><span style="width:{{ round($site['users'] / $maxSiteUsers * 100) }}%"></span></div>
        </li>
      @endforeach
    </ol>
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

  {{-- ---------- Users over 24 hours ---------- --}}
  <section class="panel chart" aria-labelledby="chart-title">
    <div class="panel-head">
      <h2 id="chart-title">Users online, last 24 hours</h2>
      <p>Peak <span class="peak">{{ number_format(max($hourly)) }}</span> at {{ sprintf('%02d:00', array_search(max($hourly), $hourly)) }}</p>
    </div>
    <svg viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-label="Users online by hour, peaking at {{ number_format(max($hourly)) }}">
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
      @for ($v = 0; $v <= $yMax; $v += 5000)
        <line class="gridline" x1="{{ $L }}" x2="{{ $W - $R }}" y1="{{ round($y($v), 1) }}" y2="{{ round($y($v), 1) }}"/>
        <text class="axis" x="{{ $L - 8 }}" y="{{ round($y($v), 1) + 4 }}" text-anchor="end">{{ $v ? ($v / 1000).'k' : '0' }}</text>
      @endfor
      @for ($h = 0; $h < 24; $h += 3)
        <text class="axis" x="{{ round($x($h), 1) }}" y="{{ $H - 8 }}" text-anchor="middle">{{ sprintf('%02d:00', $h) }}</text>
      @endfor
      <path d="{{ $area }}" fill="url(#fill)"/>
      <path d="{{ $line }}" fill="none" stroke="#2CD5FF" stroke-width="2.2" stroke-linejoin="round" filter="url(#glow)"/>
      <line x1="{{ round($x($currentHour), 1) }}" x2="{{ round($x($currentHour), 1) }}" y1="{{ $T }}" y2="{{ $H - $B }}" stroke="#2CD5FF" stroke-opacity=".5" stroke-dasharray="3 4"/>
      <circle cx="{{ round($x($currentHour), 1) }}" cy="{{ round($y($hourly[$currentHour]), 1) }}" r="5" fill="#030A12" stroke="#2CD5FF" stroke-width="2.5" filter="url(#glow)"/>
      <text class="axis" x="{{ round($x($currentHour), 1) + 9 }}" y="{{ round($y($hourly[$currentHour]), 1) - 9 }}" style="fill:#D6ECF5">Now {{ number_format($hourly[$currentHour]) }}</text>
    </svg>
  </section>

  {{-- ---------- Event log ---------- --}}
  <section class="panel events" aria-labelledby="events-title">
    <div class="panel-head">
      <h2 id="events-title">Recent events</h2>
      <p>Last 2 hours</p>
    </div>
    <ul class="log">
      @foreach ($events as $e)
        <li class="{{ $e['level'] }}"><time>{{ $e['time'] }}</time><i aria-hidden="true"></i><span>{{ $e['text'] }}</span></li>
      @endforeach
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

<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet.markercluster/1.5.3/leaflet.markercluster.min.js"></script>
<script>
(function () {
  if (!window.L) return; // map scripts blocked or offline
  const center = @json($mapCenter);
  const dataUrl = @json(route('dashboard.map-data'));
  let devices = @json($mapDevices);

  const map = L.map('map', { zoomControl: true, worldCopyJump: true }).setView([center.lat, center.lng], center.zoom);
  const osm = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';
  if (center.carto_key) {
    // CARTO dark map (free key, set CARTO_API_KEY in .env)
    L.tileLayer('https://basemaps.cartocdn.com/rastertiles/dark_all/{z}/{x}/{y}{r}.png?key=' + encodeURIComponent(center.carto_key), {
      maxZoom: 20,
      attribution: osm + ' &copy; <a href="https://carto.com/attributions">CARTO</a>',
    }).addTo(map);
  } else {
    // No key needed: OpenStreetMap's standard map, darkened with a CSS filter
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      className: 'tiles-dark',
      attribution: osm,
    }).addTo(map);
  }
  map.attributionControl.setPrefix('<a href="https://leafletjs.com">Leaflet</a>');

  // Nearby devices merge into one numbered circle; red if any of them is offline.
  const cluster = L.markerClusterGroup({
    showCoverageOnHover: false,
    maxClusterRadius: 45,
    spiderfyOnMaxZoom: true,
    iconCreateFunction(c) {
      const kids = c.getAllChildMarkers();
      const off = kids.filter((m) => m.options.dev.status === 'offline').length;
      const size = kids.length < 10 ? 34 : kids.length < 100 ? 40 : 48;
      return L.divIcon({
        html: '<span>' + kids.length + '</span>',
        className: 'cl' + (off ? ' cl-off' : ''),
        iconSize: [size, size],
      });
    },
  }).addTo(map);

  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
  const statusText = { online: 'Online', offline: 'Offline', unknown: 'Not checked yet' };

  function popup(d) {
    return '<h3>' + esc(d.name) + '</h3>'
      + '<span class="pop-status ' + esc(d.status) + '">' + statusText[d.status] + '</span>'
      + (d.status === 'offline' && d.seen ? ', last seen ' + esc(d.seen) : '')
      + '<dl>'
      + '<dt>Type</dt><dd>' + (d.type === 'ap' ? 'Access point' : 'Switch') + (d.model ? ', ' + esc(d.model) : '') + '</dd>'
      + '<dt>IP</dt><dd>' + esc(d.ip) + '</dd>'
      + '<dt>Location</dt><dd>' + esc(d.barangay || 'No barangay') + (d.landmark ? '<br>' + esc(d.landmark) : '') + '</dd>'
      + '</dl><a href="' + esc(d.edit) + '">Edit device</a>';
  }

  function visible() {
    const showAp = document.getElementById('show-ap').checked;
    const showSw = document.getElementById('show-switch').checked;
    const status = document.getElementById('map-status').value;
    return devices.filter((d) =>
      (d.type === 'ap' ? showAp : showSw) && (status === 'all' || d.status === status));
  }

  function render() {
    const list = visible();
    cluster.clearLayers();
    cluster.addLayers(list.map((d) => L.marker([d.lat, d.lng], {
      icon: L.divIcon({ className: 'pin pin-' + d.type + ' ' + d.status, iconSize: d.type === 'ap' ? [14, 14] : [13, 13] }),
      title: d.name + ', ' + statusText[d.status],
      dev: d,
    }).bindPopup(popup(d))));

    const aps = devices.filter((d) => d.type === 'ap').length;
    const sw = devices.length - aps;
    const off = devices.filter((d) => d.status === 'offline').length;
    document.getElementById('map-summary').innerHTML =
      '<b>' + aps + '</b> access points and <b>' + sw + '</b> switches on the map'
      + (off ? ', <b class="off">' + off + ' offline</b>' : '')
      + (list.length !== devices.length ? '. Showing ' + list.length + '.' : '.');
    document.getElementById('map-empty').hidden = devices.length > 0;
  }

  function fit(list) {
    if (!list.length) return;
    map.fitBounds(L.latLngBounds(list.map((d) => [d.lat, d.lng])), { padding: [40, 40], maxZoom: 17 });
  }

  ['show-ap', 'show-switch', 'map-status'].forEach((id) => document.getElementById(id).addEventListener('change', render));
  document.getElementById('map-barangay').addEventListener('change', (e) => {
    fit(e.target.value ? devices.filter((d) => d.barangay === e.target.value) : devices);
  });

  // Live: statuses change every minute when devices are polled.
  async function refresh() {
    try {
      const res = await fetch(dataUrl, { headers: { Accept: 'application/json' } });
      if (!res.ok) return;
      const data = await res.json();
      devices = data.devices;
      render();
      const t = new Date(data.updated).toLocaleTimeString('en-PH', { timeZone: 'Asia/Manila', hour: '2-digit', minute: '2-digit', hour12: false });
      document.getElementById('map-updated').textContent = ', last at ' + t;
    } catch (e) { /* keep showing the last data */ }
  }

  render();
  fit(devices);
  setInterval(refresh, 60000);
})();
</script>
</body>
</html>
