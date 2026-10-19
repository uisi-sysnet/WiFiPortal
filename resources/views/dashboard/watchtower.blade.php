<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Watchtower | Public WiFi Control</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<link href="https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#030A12;--glass:rgba(5,18,28,.74);--glass-2:rgba(8,28,42,.86);--edge:rgba(44,213,255,.32);--edge-2:rgba(44,213,255,.6);
  --text:#E6F6FF;--muted:#8FB2C4;--neon:#2CD5FF;--ok:#3DFFA8;--warn:#FFB547;--bad:#FF5470;--gold:#F2B84B;
  --display:"Chakra Petch",system-ui,sans-serif;--body:"IBM Plex Sans",system-ui,sans-serif;
}
*{box-sizing:border-box}
html,body{height:100%;margin:0}
body{background:var(--bg);color:var(--text);font:14px/1.45 var(--body);overflow:hidden}
a{color:var(--neon)}
.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
[hidden]{display:none!important}

/* The map fills everything right of the sidebar */
#wt-map{position:fixed;top:0;bottom:0;right:0;left:var(--sb-w,0px);z-index:0;background:#02070C;transition:left .18s ease}
.leaflet-container{font:inherit;background:#02070C}
.tiles-dim{filter:brightness(.62) saturate(.85) contrast(1.05)}
.tiles-dark{filter:invert(1) hue-rotate(195deg) brightness(.72) saturate(.55) contrast(.95)}
.leaflet-control-attribution{background:rgba(2,7,12,.6)!important;color:#6F8FA3!important;font-size:10px}
.leaflet-control-attribution a{color:#8FB2C4!important}
.leaflet-control-zoom a{background:var(--glass-2)!important;color:var(--neon)!important;border-color:var(--edge)!important}

/* Overlay layer: panels float over the map; the gaps let the map be dragged */
.wt{position:fixed;top:0;bottom:0;right:0;left:var(--sb-w,0px);z-index:500;pointer-events:none;transition:left .18s ease}
.wt > *{pointer-events:auto}
.glass{background:var(--glass);border:1px solid var(--edge);border-radius:10px;backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);
  box-shadow:0 10px 30px rgba(0,0,0,.45),inset 0 0 0 1px rgba(44,213,255,.05)}

/* Title bar */
.wt-top{position:absolute;top:12px;left:16px;right:16px;display:flex;align-items:center;gap:14px;pointer-events:none}
.wt-top > *{pointer-events:auto}
.wt-title{flex:1;display:flex;justify-content:center;pointer-events:none}
.wt-title h1{margin:0;padding:8px 46px 9px;font:700 1.45rem/1 var(--display);letter-spacing:.32em;text-transform:uppercase;color:var(--text);
  text-shadow:0 0 14px rgba(44,213,255,.65);background:linear-gradient(180deg,rgba(8,40,60,.92),rgba(4,18,30,.88));border:1px solid var(--edge-2);
  clip-path:polygon(0 0,100% 0,calc(100% - 22px) 100%,22px 100%);pointer-events:auto}
.wt-title h1 small{display:block;margin-top:5px;font:600 .62rem/1 var(--display);letter-spacing:.3em;color:var(--muted);text-shadow:none}
.wt-chip{display:inline-flex;align-items:center;gap:8px;padding:7px 12px;font:600 .8rem var(--display);letter-spacing:.08em;text-transform:uppercase;max-width:280px}
.wt-chip span:last-child{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.wt-dot{width:9px;height:9px;border-radius:50%;background:var(--ok);box-shadow:0 0 10px currentColor;color:var(--ok)}
.wt-status.normal{color:var(--ok)}.wt-status.warning{color:var(--warn)}.wt-status.critical{color:var(--bad)}
.wt-status .wt-dot{background:currentColor}
.wt-status.critical{animation:wt-blink 1.4s ease-in-out infinite}
@keyframes wt-blink{50%{box-shadow:0 0 0 1px var(--bad),0 0 22px rgba(255,84,112,.55)}}
.wt-clock{font:700 1.15rem var(--display);color:var(--neon);text-shadow:0 0 10px rgba(44,213,255,.6);padding:5px 12px;text-align:right;line-height:1.1}
.wt-clock small{display:block;font:500 .66rem var(--body);color:var(--muted);letter-spacing:.06em}
.wt-acct .acct-trigger{background:var(--glass-2);border:1px solid var(--edge);border-radius:999px}

/* Left: counts */
.wt-left{position:absolute;top:84px;left:16px;width:280px;display:grid;gap:12px;max-height:calc(100% - 84px - var(--bottom-h) - 24px);overflow:auto;scrollbar-width:none}
.wt-kpi{padding:12px 16px 13px;position:relative;overflow:hidden}
.wt-kpi::before{content:"";position:absolute;left:0;top:10px;bottom:10px;width:3px;border-radius:0 3px 3px 0;background:var(--neon);box-shadow:0 0 10px var(--neon)}
.wt-kpi.bad::before{background:var(--bad);box-shadow:0 0 10px var(--bad)}
.wt-kpi .lbl{margin:0;font:600 .72rem var(--display);letter-spacing:.12em;text-transform:uppercase;color:var(--muted)}
.wt-kpi .num{margin:4px 0 0;font:700 2rem/1 var(--display);color:var(--text)}
.wt-kpi .num small{font-size:.95rem;color:var(--muted);font-weight:600}
.wt-kpi .sub{margin:6px 0 0;font-size:.78rem;color:var(--muted)}
.wt-kpi .sub b.bad{color:var(--bad)}
.wt-kpi .bar{height:4px;margin-top:8px;border-radius:2px;background:rgba(143,178,196,.18);overflow:hidden;display:flex}
.wt-kpi .bar i{display:block;height:100%}

/* Right: alerts and what to check first */
.wt-right{position:absolute;top:84px;right:16px;width:340px;display:grid;gap:12px;max-height:calc(100% - 84px - var(--bottom-h) - 24px);overflow:auto;scrollbar-width:thin}
.wt-panel{padding:12px 14px}
.wt-panel h2{display:flex;justify-content:space-between;align-items:center;gap:8px;margin:0 0 8px;font:600 .78rem var(--display);letter-spacing:.14em;text-transform:uppercase;color:var(--neon)}
.wt-panel h2 span{font:500 .72rem var(--body);letter-spacing:0;text-transform:none;color:var(--muted)}
.wt-list{list-style:none;margin:0;padding:0;display:grid;gap:7px}
.wt-item{display:grid;grid-template-columns:auto minmax(0,1fr);gap:2px 10px;padding:8px 10px;border-radius:8px;background:rgba(255,84,112,.08);border:1px solid rgba(255,84,112,.32);cursor:pointer;text-align:left;color:inherit;font:inherit;width:100%}
.wt-item:hover{background:rgba(255,84,112,.16)}
.wt-item.ap{background:rgba(255,181,71,.07);border-color:rgba(255,181,71,.3)}
.wt-item.ap:hover{background:rgba(255,181,71,.14)}
.wt-item.cap{background:rgba(255,181,71,.07);border-color:rgba(255,181,71,.3);cursor:default}
.wt-item .n{grid-row:span 3;display:grid;place-items:center;width:24px;height:24px;border-radius:50%;background:var(--bad);color:#fff;font:700 .78rem var(--display)}
.wt-item.ap .n,.wt-item.cap .n{background:var(--warn);color:#1a1000}
.wt-item b{font:600 .9rem var(--display);letter-spacing:.02em}
.wt-item small{color:var(--muted);font-size:.74rem}
.wt-item p{margin:2px 0 0;font-size:.76rem;color:#C9DCE6}
.wt-ok{margin:0;padding:10px;border-radius:8px;background:rgba(61,255,168,.07);border:1px solid rgba(61,255,168,.3);color:var(--ok);font-size:.85rem}

/* Bottom strip */
:root{--bottom-h:224px}
html.wt-collapsed{--bottom-h:0px}
.wt-bottom{position:absolute;left:16px;right:16px;bottom:12px;height:var(--bottom-h);display:grid;grid-template-columns:minmax(0,.9fr) minmax(0,1.5fr) minmax(0,1.1fr) minmax(0,1.3fr);gap:12px;transition:height .2s ease}
html.wt-collapsed .wt-bottom{visibility:hidden}
.wt-bottom .wt-panel{min-width:0;overflow:hidden;display:flex;flex-direction:column}
.wt-toggle{position:absolute;left:50%;bottom:calc(var(--bottom-h) + 10px);transform:translateX(-50%);padding:4px 22px;font:600 .72rem var(--display);letter-spacing:.14em;text-transform:uppercase;color:var(--neon);cursor:pointer;
  background:var(--glass-2);border:1px solid var(--edge);border-radius:8px 8px 0 0;transition:bottom .2s ease}
.wt-donut{display:flex;align-items:center;gap:14px;flex:1}
.wt-donut svg{flex:none}
.wt-legend{list-style:none;margin:0;padding:0;display:grid;gap:6px;font-size:.8rem;min-width:0}
.wt-legend li{display:flex;align-items:center;gap:7px;white-space:nowrap}
.wt-legend i{width:9px;height:9px;border-radius:2px;flex:none}
.wt-legend b{margin-left:auto;padding-left:10px;font-family:var(--display)}
.wt-chart{flex:1;min-height:0}
.wt-chart svg{width:100%;height:100%;display:block}
.wt-bars{list-style:none;margin:0;padding:0;display:grid;gap:7px;overflow:auto;scrollbar-width:none}
.wt-bars li{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:3px 8px;font-size:.8rem}
.wt-bars .t{height:5px;grid-column:1/-1;border-radius:3px;background:rgba(143,178,196,.16);overflow:hidden}
.wt-bars .t i{display:block;height:100%;background:linear-gradient(90deg,var(--neon),var(--gold))}
.wt-bars b{font-family:var(--display)}
.wt-events{list-style:none;margin:0;padding:0;display:grid;gap:6px;overflow:auto;scrollbar-width:none;font-size:.8rem}
.wt-events li{display:grid;grid-template-columns:42px 10px minmax(0,1fr);gap:8px;align-items:baseline}
.wt-events time{color:var(--muted);font-family:var(--display)}
.wt-events i{width:8px;height:8px;border-radius:50%;background:var(--neon)}
.wt-events .down i{background:var(--bad)}.wt-events .warn i{background:var(--warn)}.wt-events .ok i{background:var(--ok)}
.wt-events span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.wt-empty{margin:auto;color:var(--muted);font-size:.82rem;text-align:center}

/* Map tools (layer switch) */
.wt-tools{position:absolute;right:372px;top:84px;display:flex;flex-direction:column;gap:6px;padding:6px}
.wt-tools label{display:flex;align-items:center;gap:6px;font-size:.76rem;color:var(--muted);cursor:pointer;white-space:nowrap}
.wt-tools input{accent-color:var(--neon)}

/* Pins */
.wt-pin{border-radius:50%;border:2px solid #E6F6FF;background:var(--ok);box-shadow:0 0 0 3px rgba(61,255,168,.28),0 0 12px var(--ok)}
.wt-pin.offline{background:var(--bad);box-shadow:0 0 0 3px rgba(255,84,112,.3),0 0 14px var(--bad);animation:wt-pulse 1.6s ease-in-out infinite}
.wt-pin.unknown{background:#6F8FA3;box-shadow:0 0 0 3px rgba(111,143,163,.25)}
.wt-pin.switch{border-radius:3px}
.wt-pin.router{border-radius:3px;transform:rotate(45deg);border-color:var(--gold)}
@keyframes wt-pulse{50%{box-shadow:0 0 0 8px rgba(255,84,112,0),0 0 20px var(--bad)}}
.leaflet-popup-content-wrapper,.leaflet-popup-tip{background:var(--glass-2);color:var(--text);border:1px solid var(--edge)}
.leaflet-popup-content{margin:10px 14px;font-size:.84rem}
.leaflet-popup-content h3{margin:0 0 4px;font:600 1rem var(--display)}
.leaflet-popup-content dl{display:grid;grid-template-columns:auto 1fr;gap:2px 10px;margin:6px 0 0}
.leaflet-popup-content dt{color:var(--muted)}
.leaflet-popup-content dd{margin:0}
.leaflet-container a.leaflet-popup-close-button{color:var(--muted)}
.wt-link{stroke:var(--neon);stroke-opacity:.55;fill:none}
.wt-link.off{stroke:var(--bad);stroke-opacity:.8;stroke-dasharray:4 4}

@media (max-width:1300px){.wt-tools{right:16px;top:auto;bottom:calc(var(--bottom-h) + 46px)}}
@media (max-width:1100px){
  :root{--bottom-h:0px}.wt-bottom,.wt-toggle{display:none}
  .wt-right{width:300px}.wt-left{width:240px}
}
@media (max-width:900px){
  .wt-top{left:64px}.wt-title h1{font-size:1rem;letter-spacing:.18em;padding:7px 26px}
  .wt-left,.wt-right{position:static;width:auto;max-height:none}
  .wt{overflow:auto;pointer-events:auto;padding:76px 12px 12px}
  .wt-left,.wt-right{margin-bottom:12px}
  .wt-tools{display:none}
}
@media (prefers-reduced-motion:reduce){.wt-pin.offline,.wt-status.critical{animation:none}}
</style>
</head>
<body>
@include('partials.sidebar', ['theme' => 'dark'])

<div id="wt-map" role="region" aria-label="Map of routers, switches and access points"></div>

<div class="wt" id="wt">
  {{-- Title bar --}}
  <div class="wt-top">
    <div class="wt-chip glass wt-status" id="wt-status" role="status"><span class="wt-dot" aria-hidden="true"></span><span id="wt-status-text">Checking</span></div>
    <div class="wt-title"><h1>Watchtower<small>Muntinlupa Public WiFi · live network</small></h1></div>
    <div class="wt-clock glass"><time id="wt-clock">--:--:--</time><small id="wt-date">Philippine time</small></div>
    <div class="wt-acct">@include('partials.account-menu')</div>
  </div>

  {{-- Left: counts --}}
  <div class="wt-left" id="wt-left" aria-live="off"></div>

  {{-- Right: alerts and what to check first --}}
  <div class="wt-right">
    <section class="glass wt-panel" aria-labelledby="wt-check-title">
      <h2 id="wt-check-title">Check these first <span id="wt-check-count"></span></h2>
      <div id="wt-check"></div>
    </section>
    <section class="glass wt-panel" id="wt-cap-panel" aria-labelledby="wt-cap-title" hidden>
      <h2 id="wt-cap-title">Capacity</h2>
      <ul class="wt-list" id="wt-cap"></ul>
    </section>
  </div>

  <div class="wt-tools glass" aria-label="Map layers">
    <label><input type="radio" name="wt-base" value="satellite" checked> Satellite</label>
    <label><input type="radio" name="wt-base" value="dark"> Dark map</label>
    <label><input type="checkbox" id="wt-links" checked> Links</label>
    <label><input type="checkbox" id="wt-heat"> Clients heat</label>
  </div>

  {{-- Bottom strip --}}
  <button type="button" class="wt-toggle" id="wt-toggle" aria-expanded="true" aria-controls="wt-bottom">Hide panels</button>
  <div class="wt-bottom" id="wt-bottom">
    <section class="glass wt-panel" aria-labelledby="wt-s-title">
      <h2 id="wt-s-title">Device status</h2>
      <div class="wt-donut" id="wt-donut"></div>
    </section>
    <section class="glass wt-panel" aria-labelledby="wt-u-title">
      <h2 id="wt-u-title">Users online <span id="wt-u-peak"></span></h2>
      <div class="wt-chart" id="wt-chart"></div>
    </section>
    <section class="glass wt-panel" aria-labelledby="wt-b-title">
      <h2 id="wt-b-title">Busiest locations <span>clients now</span></h2>
      <ul class="wt-bars" id="wt-bars"></ul>
    </section>
    <section class="glass wt-panel" aria-labelledby="wt-e-title">
      <h2 id="wt-e-title">Latest events <span>24 hours</span></h2>
      <ul class="wt-events" id="wt-events"></ul>
    </section>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet.heat/0.2.0/leaflet-heat.js"></script>
<script>
(function () {
  const dataUrl = @json(route('watchtower.data'));
  const center = @json($mapCenter);
  let data = @json($initial);
  const $ = (id) => document.getElementById(id);
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const num = (v) => (v === null || v === undefined) ? '–' : Number(v).toLocaleString();

  /* ---------- Map ---------- */
  if (!window.L) { $('wt-map').textContent = 'The map could not load (no internet to the map library).'; }
  const map = window.L ? L.map('wt-map', { zoomControl: false, preferCanvas: false, worldCopyJump: true }).setView([center.lat, center.lng], center.zoom) : null;
  let base = null;
  const bases = {
    satellite: () => L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
      maxZoom: 19, className: 'tiles-dim', attribution: 'Imagery &copy; Esri, Maxar, Earthstar Geographics',
    }),
    dark: () => center.carto_key
      ? L.tileLayer('https://basemaps.cartocdn.com/rastertiles/dark_all/{z}/{x}/{y}{r}.png?key=' + encodeURIComponent(center.carto_key), { maxZoom: 20, attribution: '&copy; OpenStreetMap &copy; CARTO' })
      : L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, className: 'tiles-dark', attribution: '&copy; OpenStreetMap contributors' }),
  };
  function setBase(name) {
    if (!map) return;
    if (base) map.removeLayer(base);
    base = bases[name]().addTo(map);
    try { localStorage.setItem('wt.base', name); } catch (e) {}
  }
  let savedBase = 'satellite';
  try { savedBase = localStorage.getItem('wt.base') || 'satellite'; } catch (e) {}
  document.querySelectorAll('input[name=wt-base]').forEach((r) => { r.checked = r.value === savedBase; r.addEventListener('change', () => setBase(r.value)); });
  if (map) {
    setBase(savedBase);
    L.control.zoom({ position: 'bottomright' }).addTo(map);
    map.attributionControl.setPrefix('<a href="https://leafletjs.com">Leaflet</a>');
  }

  const pins = map ? L.layerGroup().addTo(map) : null;
  const links = map ? L.layerGroup().addTo(map) : null;
  const heat = map && L.heatLayer ? L.heatLayer([], { radius: 30, blur: 16, minOpacity: .35, maxZoom: 11,
    gradient: { .2: '#3B4CC0', .4: '#33C6E8', .55: '#7CE85A', .7: '#F8E33B', .85: '#F2862E', 1: '#D7191C' } }) : null;
  const markers = {};
  const typeText = { ap: 'Access point', switch: 'Switch', router: 'Router' };
  const statusText = { online: 'Online', offline: 'Offline', unknown: 'Not checked yet' };

  function popup(d) {
    return '<h3>' + esc(d.name) + '</h3>' + '<span style="color:' + (d.status === 'online' ? 'var(--ok)' : d.status === 'offline' ? 'var(--bad)' : 'var(--muted)') + '">'
      + statusText[d.status] + '</span>' + (d.status === 'offline' && d.seen ? ', last seen ' + esc(d.seen) : '')
      + '<dl><dt>Type</dt><dd>' + typeText[d.type] + (d.model ? ', ' + esc(d.model) : '') + '</dd>'
      + '<dt>IP</dt><dd>' + esc(d.ip) + '</dd>'
      + (d.type === 'router' ? '<dt>Users</dt><dd>' + num(d.users) + '</dd>' : '')
      + (d.type === 'ap' ? '<dt>Clients</dt><dd>' + num(d.clients) + '</dd>' : '')
      + '<dt>Where</dt><dd>' + esc([d.barangay, d.landmark].filter(Boolean).join(', ') || '–') + '</dd></dl>'
      + (d.edit ? '<p style="margin:8px 0 0"><a href="' + esc(d.edit) + '">' + (d.type === 'router' ? 'Open router' : 'Edit device') + '</a></p>' : '');
  }

  function drawMap(first) {
    if (!map) return;
    pins.clearLayers(); links.clearLayers();
    const byKey = Object.fromEntries(data.devices.map((d) => [d.key, d]));
    if ($('wt-links').checked) {
      data.devices.forEach((d) => {
        const up = d.uplink && byKey[d.uplink];
        if (!up) return;
        const off = d.status === 'offline' || up.status === 'offline';
        L.polyline([[up.lat, up.lng], [d.lat, d.lng]], { className: 'wt-link' + (off ? ' off' : ''), weight: d.type === 'ap' ? 1 : 1.6, interactive: false }).addTo(links);
      });
    }
    data.devices.forEach((d) => {
      const size = d.type === 'router' ? 16 : d.type === 'switch' ? 13 : 12;
      markers[d.key] = L.marker([d.lat, d.lng], {
        icon: L.divIcon({ className: '', html: '<div class="wt-pin ' + d.type + ' ' + d.status + '" style="width:' + size + 'px;height:' + size + 'px"></div>', iconSize: [size, size] }),
        title: d.name + ', ' + statusText[d.status],
        zIndexOffset: d.status === 'offline' ? 1000 : 0,
      }).bindPopup(popup(d)).addTo(pins);
    });
    if (heat) {
      const pts = data.devices.filter((d) => d.type === 'ap' && d.clients > 0).map((d) => [d.lat, d.lng, d.clients]);
      const near = 0.004;
      heat.setOptions({ max: Math.max(1, ...pts.map((p) => pts.filter((q) => Math.abs(q[0] - p[0]) < near && Math.abs(q[1] - p[1]) < near).reduce((s, q) => s + q[2], 0))) });
      heat.setLatLngs(pts);
      if ($('wt-heat').checked) heat.addTo(map); else map.removeLayer(heat);
    }
    if (first && !center.fixed && data.devices.length) {
      // Frame the devices in the open middle: clear of the side panels and the bottom strip
      map.fitBounds(L.latLngBounds(data.devices.map((d) => [d.lat, d.lng])), { paddingTopLeft: [320, 100], paddingBottomRight: [390, 270], maxZoom: 16 });
    }
  }
  $('wt-links').addEventListener('change', () => drawMap(false));
  $('wt-heat').addEventListener('change', () => drawMap(false));

  function flyTo(key) {
    const d = data.devices.find((x) => x.key === key);
    if (!d || !map) return;
    map.flyTo([d.lat, d.lng], Math.max(map.getZoom(), 17), { duration: .8 });
    setTimeout(() => markers[key] && markers[key].openPopup(), 850);
  }

  /* ---------- Panels ---------- */
  function kpis() {
    const k = data.kpis, c = data.counts;
    const card = (label, value, of, sub, share, bad) => '<div class="glass wt-kpi' + (bad ? ' bad' : '') + '"><p class="lbl">' + label + '</p>'
      + '<p class="num">' + value + (of !== null ? ' <small>/ ' + num(of) + '</small>' : '') + '</p>'
      + (share !== null ? '<div class="bar"><i style="width:' + (share * 100) + '%;background:var(--ok)"></i><i style="width:' + ((1 - share) * 100) + '%;background:var(--bad)"></i></div>' : '')
      + '<p class="sub">' + sub + '</p></div>';
    const dev = (key, label) => {
      const x = c[key];
      return card(label + ' online', num(x.online), x.total, x.offline ? '<b class="bad">' + num(x.offline) + ' offline</b>' + (x.unknown ? ', ' + num(x.unknown) + ' not checked' : '')
        : (x.total ? 'All answering' : 'None added yet'), x.total ? x.online / x.total : null, x.offline > 0);
    };
    $('wt-left').innerHTML =
      card('Users online', num(k.users.online), null, (data.chart.peak ? 'Peak today ' + num(data.chart.peak.value) + ' at ' + esc(data.chart.peak.at) + ' · ' : '') + num(k.users.today) + ' registered today', null, false)
      + dev('router', 'Routers') + dev('switch', 'Switches') + dev('ap', 'Access points');
  }

  function status() {
    const s = data.status, el = $('wt-status');
    el.className = 'wt-chip glass wt-status ' + s.level;
    $('wt-status-text').textContent = s.level.toUpperCase() + (s.level === 'normal' ? '' : ': ' + (s.reasons[0] || ''));
    el.title = s.reasons.join('\n');
  }

  function checkFirst() {
    $('wt-check-count').textContent = data.roots.length ? data.roots.length + ' to check' : '';
    if (!data.roots.length) { $('wt-check').innerHTML = '<p class="wt-ok">Nothing is offline. All routers, switches and access points answer.</p>'; return; }
    $('wt-check').innerHTML = '<ul class="wt-list">' + data.roots.map((r, i) => '<li><button type="button" class="wt-item ' + (r.type === 'ap' ? 'ap' : '') + '" data-key="' + esc(r.key) + '">'
      + '<span class="n">' + (i + 1) + '</span><b>' + esc(r.label) + ' ' + esc(r.name) + '</b>'
      + '<small>' + esc(r.where || 'No location') + ' · down ' + esc(r.down_for) + (r.behind ? ' · ' + r.behind + ' behind it' : '') + '</small>'
      + '<p>' + esc(r.finding) + '</p></button></li>').join('') + '</ul>';
    $('wt-check').querySelectorAll('[data-key]').forEach((b) => b.addEventListener('click', () => flyTo(b.dataset.key)));
  }

  function capacity() {
    $('wt-cap-panel').hidden = !data.alerts.length;
    $('wt-cap').innerHTML = data.alerts.map((a) => '<li class="wt-item cap"><span class="n">!</span><b>' + (a.level === 'full' ? 'Full: ' : 'Busy: ') + esc(a.title) + '</b><p>' + esc(a.message) + '</p></li>').join('');
  }

  function donut() {
    const all = ['router', 'switch', 'ap'].reduce((t, k) => ({ online: t.online + data.counts[k].online, offline: t.offline + data.counts[k].offline, unknown: t.unknown + data.counts[k].unknown }), { online: 0, offline: 0, unknown: 0 });
    const total = all.online + all.offline + all.unknown;
    const parts = [['Online', all.online, '#3DFFA8'], ['Offline', all.offline, '#FF5470'], ['Not checked', all.unknown, '#6F8FA3']];
    let a = -Math.PI / 2, arcs = '';
    const R = 52, r = 36, cx = 60, cy = 60;
    parts.forEach(([, v, col]) => {
      if (!total || !v) return;
      const sweep = v / total * Math.PI * 2, b = a + sweep - (v === total ? 0.0001 : 0);
      const big = sweep > Math.PI ? 1 : 0, p = (ang, rad) => (cx + rad * Math.cos(ang)).toFixed(2) + ',' + (cy + rad * Math.sin(ang)).toFixed(2);
      arcs += '<path d="M' + p(a, R) + ' A' + R + ',' + R + ' 0 ' + big + ' 1 ' + p(b, R) + ' L' + p(b, r) + ' A' + r + ',' + r + ' 0 ' + big + ' 0 ' + p(a, r) + ' Z" fill="' + col + '" style="filter:drop-shadow(0 0 4px ' + col + ')"/>';
      a += sweep;
    });
    $('wt-donut').innerHTML = '<svg width="120" height="120" viewBox="0 0 120 120" role="img" aria-label="' + all.online + ' online, ' + all.offline + ' offline">'
      + (total ? arcs : '<circle cx="60" cy="60" r="44" fill="none" stroke="rgba(143,178,196,.2)" stroke-width="16"/>')
      + '<text x="60" y="58" text-anchor="middle" fill="#E6F6FF" font-family="Chakra Petch" font-weight="700" font-size="20">' + (total ? Math.round(all.online / total * 100) + '%' : '–') + '</text>'
      + '<text x="60" y="76" text-anchor="middle" fill="#8FB2C4" font-size="10">online</text></svg>'
      + '<ul class="wt-legend">' + parts.map(([l, v, col]) => '<li><i style="background:' + col + '"></i>' + l + '<b>' + num(v) + '</b></li>').join('')
      + '<li><i style="background:transparent"></i>Total<b>' + num(total) + '</b></li></ul>';
  }

  function chart() {
    const pts = data.chart.points, box = $('wt-chart');
    $('wt-u-peak').textContent = data.chart.peak ? 'peak ' + num(data.chart.peak.value) + ' at ' + data.chart.peak.at : 'last 24 hours';
    const vals = pts.map((p) => p.value).filter((v) => v !== null);
    if (!vals.length) { box.innerHTML = '<p class="wt-empty">No history yet: users online are saved every 5 minutes.</p>'; return; }
    const W = 520, H = 150, L = 36, B = 20, T = 8, max = Math.max(1, ...vals);
    const x = (i) => L + (W - L - 6) * (pts.length > 1 ? i / (pts.length - 1) : 0);
    const y = (v) => T + (H - T - B) * (1 - v / max);
    let line = '', area = '', started = false;
    pts.forEach((p, i) => {
      if (p.value === null) { started = false; return; }
      line += (started ? ' L' : ' M') + x(i).toFixed(1) + ',' + y(p.value).toFixed(1);
      started = true;
    });
    const firstI = pts.findIndex((p) => p.value !== null), lastI = pts.length - 1 - [...pts].reverse().findIndex((p) => p.value !== null);
    area = line + ' L' + x(lastI).toFixed(1) + ',' + (H - B) + ' L' + x(firstI).toFixed(1) + ',' + (H - B) + ' Z';
    const grid = [0, .5, 1].map((f) => '<line x1="' + L + '" x2="' + (W - 6) + '" y1="' + y(max * f) + '" y2="' + y(max * f) + '" stroke="rgba(143,178,196,.15)"/>'
      + '<text x="' + (L - 6) + '" y="' + (y(max * f) + 4) + '" text-anchor="end" fill="#8FB2C4" font-size="10">' + (max * f >= 1000 ? (max * f / 1000).toFixed(1) + 'k' : Math.round(max * f)) + '</text>').join('');
    const every = Math.max(1, Math.ceil(pts.length / 6));
    const labels = pts.map((p, i) => (pts.length - 1 - i) % every === 0 ? '<text x="' + x(i) + '" y="' + (H - 5) + '" text-anchor="middle" fill="#8FB2C4" font-size="10">' + p.at + '</text>' : '').join('');
    box.innerHTML = '<svg viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none" role="img" aria-label="Users online over the last 24 hours">'
      + '<defs><linearGradient id="wt-fill" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#2CD5FF" stop-opacity=".45"/><stop offset="1" stop-color="#2CD5FF" stop-opacity="0"/></linearGradient></defs>'
      + grid + '<path d="' + area + '" fill="url(#wt-fill)"/><path d="' + line + '" fill="none" stroke="#2CD5FF" stroke-width="2" style="filter:drop-shadow(0 0 4px #2CD5FF)"/>' + labels + '</svg>';
  }

  function bars() {
    const list = data.barangays;
    if (!list.length) { $('wt-bars').innerHTML = '<li class="wt-empty">No access points in barangays yet.</li>'; return; }
    const max = Math.max(1, ...list.map((b) => b.clients || 0));
    $('wt-bars').innerHTML = list.map((b) => '<li><span>' + esc(b.name) + ' <small style="color:var(--muted)">' + b.online + '/' + b.aps + ' APs</small></span><b>' + (b.clients === null ? '–' : num(b.clients)) + '</b>'
      + '<span class="t"><i style="width:' + ((b.clients || 0) / max * 100) + '%"></i></span></li>').join('');
  }

  function events() {
    $('wt-events').innerHTML = data.events.length
      ? data.events.map((e) => '<li class="' + esc(e.level) + '"><time>' + esc(e.time) + '</time><i aria-hidden="true"></i><span title="' + esc(e.text) + '">' + esc(e.text) + '</span></li>').join('')
      : '<li class="wt-empty" style="display:block">Nothing in the last 24 hours.</li>';
  }

  function render(first) {
    status(); kpis(); checkFirst(); capacity(); donut(); chart(); bars(); events(); drawMap(first);
  }

  /* ---------- Bottom panels: hide to see the whole map ---------- */
  function setCollapsed(on) {
    document.documentElement.classList.toggle('wt-collapsed', on);
    $('wt-toggle').textContent = on ? 'Show panels' : 'Hide panels';
    $('wt-toggle').setAttribute('aria-expanded', String(!on));
    try { localStorage.setItem('wt.collapsed', on ? '1' : '0'); } catch (e) {}
  }
  $('wt-toggle').addEventListener('click', () => setCollapsed(!document.documentElement.classList.contains('wt-collapsed')));
  try { if (localStorage.getItem('wt.collapsed') === '1') setCollapsed(true); } catch (e) {}

  /* ---------- Clock and refresh ---------- */
  function tick() {
    const now = new Date();
    $('wt-clock').textContent = now.toLocaleTimeString('en-PH', { timeZone: 'Asia/Manila', hour12: false });
    $('wt-date').textContent = now.toLocaleDateString('en-PH', { timeZone: 'Asia/Manila', weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }) + ' · PHT';
  }
  tick(); setInterval(tick, 1000);

  async function refresh() {
    try {
      const res = await fetch(dataUrl, { headers: { Accept: 'application/json' } });
      if (!res.ok) return;
      data = await res.json();
      render(false);
    } catch (e) { /* keep the last data */ }
  }
  render(true);
  setInterval(refresh, 30000);
  // The sidebar collapsing changes the map's size
  window.addEventListener('resize', () => map && setTimeout(() => map.invalidateSize(), 200));
})();
</script>
</body>
</html>
