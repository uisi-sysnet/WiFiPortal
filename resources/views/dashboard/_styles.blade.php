{{-- Dashboard look (colours, panels, map), shared by the dashboard and the full-screen map. --}}
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
.bar-title{flex:1;margin:0;font:600 1.2rem/1.1 var(--display);letter-spacing:.02em;color:var(--text)}
@media (max-width:900px){.bar{padding-left:68px}}
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
.kpi-row{display:contents}
/* Capacity alerts */
.alerts{grid-column:span 12}
.alert-list{list-style:none;margin:0;padding:0;display:grid;gap:8px}
.alert-list li{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:4px 12px;align-items:start;padding:9px 12px;border:1px solid rgba(255,176,32,.35);border-left:3px solid var(--warn);border-radius:4px;background:rgba(255,176,32,.06)}
.alert-list li.full{border-color:rgba(255,84,112,.45);border-left-color:var(--down);background:rgba(255,84,112,.08)}
.alert-list .lvl{font:600 .72rem var(--display);text-transform:uppercase;letter-spacing:.08em;color:var(--warn);padding-top:2px}
.alert-list li.full .lvl{color:var(--down)}
.alert-list a{color:var(--text);font-weight:600;text-decoration:none}
.alert-list a:hover{color:var(--neon)}
.alert-list p{margin:2px 0 0;font-size:.85rem;color:var(--muted)}
.alert-list time{font-size:.78rem;color:var(--muted);white-space:nowrap}
.alert-more{margin:8px 0 0;font-size:.82rem;color:var(--muted)}
.kpi .as-of{margin-top:-8px;font-size:.8rem}
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
.map-btn{display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border:1px solid var(--line);border-radius:4px;background:#0B1B2B;color:var(--text);font:500 .84rem var(--body);text-decoration:none;cursor:pointer}
.map-btn:hover{border-color:var(--neon);color:var(--neon);box-shadow:0 0 10px rgba(44,213,255,.35)}
.map-btn.icon{padding:5px 7px;order:99} /* last in the tools row */
.map-btn[aria-pressed="true"]{color:var(--neon);border-color:var(--neon)}
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
.pin.pin-router{border-radius:3px;transform:rotate(45deg)}
/* Link arcs: halo + line + light flowing from the uplink outwards */
.leaflet-overlay-pane path.link-halo{stroke:#2CD5FF;stroke-opacity:.12;fill:none}
.leaflet-overlay-pane path.link-halo.off{stroke:#FF5470;stroke-opacity:.14}
.leaflet-overlay-pane path.link-halo.unk{stroke:#6F8FA3;stroke-opacity:.08}
.leaflet-overlay-pane path.link{stroke:#2CD5FF;stroke-opacity:.85;fill:none;filter:drop-shadow(0 0 2px rgba(44,213,255,.9))}
.leaflet-overlay-pane path.link-hit{fill:none;stroke:#000}
.leaflet-overlay-pane path.link.off{stroke:#FF5470;stroke-opacity:.95;filter:drop-shadow(0 0 3px rgba(255,84,112,.9))}
.leaflet-overlay-pane path.link.unk{stroke:#6F8FA3;stroke-opacity:.6;filter:none}
.leaflet-overlay-pane path.link-flow{stroke:#E8FBFF;stroke-linecap:round;fill:none;filter:drop-shadow(0 0 3px #2CD5FF);animation:link-flow 1.6s linear infinite}
.leaflet-overlay-pane path.link-flow.trunk{animation-duration:1.1s}
@keyframes link-flow{to{stroke-dashoffset:-23.5}}
.legend-line{display:inline-block;width:18px;height:9px;border-top:1px solid var(--neon);border-radius:50% 50% 0 0;margin-right:2px;vertical-align:-2px;filter:drop-shadow(0 0 3px rgba(44,213,255,.9))}
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
.legend-heat{display:inline-block;width:18px;height:8px;border-radius:4px;margin:0 2px;background:linear-gradient(90deg,#33C6E8,#F8E33B,#D7191C);vertical-align:1px}
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
.chart .chart-meta{margin:-6px 0 8px;font-size:.85rem;color:var(--muted)}
.chart .chart-meta .sep{margin:0 6px;opacity:.6}
.chart rect.hit{fill:transparent}
.chart rect.hit:hover{fill:rgba(44,213,255,.06)}
.chart .axis.empty{font-size:13px}
.chart .seg{display:inline-flex;border:1px solid var(--line);border-radius:6px;overflow:hidden}
.chart .seg button{font:500 .82rem var(--body);padding:5px 12px;border:0;background:transparent;color:var(--muted);cursor:pointer}
.chart .seg button+button{border-left:1px solid var(--line)}
.chart .seg button:hover{color:var(--text)}
.chart .seg button[aria-pressed="true"]{background:rgba(44,213,255,.14);color:var(--neon);text-shadow:0 0 8px rgba(44,213,255,.6)}
#chart-body.loading{opacity:.55;transition:opacity .15s}
.peak{font:600 1.1rem var(--display);color:var(--neon)}

/* Busiest sites */
.top{grid-column:span 3;display:flex;flex-direction:column}  /* 25% */
.bars{margin:0;padding:0;list-style:none;display:grid;gap:12px}
.top .bars{flex:1;align-content:start;max-height:520px;overflow-y:auto;padding-right:4px}
#barangay-clients{flex:1;display:flex;flex-direction:column;min-height:0}
.bars .row b.na{font:500 .8rem var(--body);color:var(--muted)}
.bars-note{margin:12px 0 0;font-size:.8rem;color:var(--muted)}
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
@media (prefers-reduced-motion:reduce){.cell{transition:none}.grid::after{animation:none;display:none}.pin.offline::after{animation:none;display:none}.leaflet-overlay-pane path.link-flow{animation:none;display:none}}
</style>
