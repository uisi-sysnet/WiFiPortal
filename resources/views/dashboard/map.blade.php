<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Map | Public WiFi Control</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet.markercluster/1.5.3/MarkerCluster.min.css">
<link href="https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
@include('dashboard._styles')
<style>
/* The map panel fills the whole window */
body.map-page{height:100vh;overflow:hidden}
.map-page .mapp{position:fixed;inset:0;display:flex;flex-direction:column;margin:0;border-radius:0;padding:12px 16px 10px}
.map-page .map-wrap{flex:1;min-height:0}
.map-page #map{height:100%}
.map-page .map-foot{flex:none}
</style>
</head>
<body class="map-page">

@include('dashboard._map', ['fullscreen' => true])
@include('dashboard._map-script')

<script>
(function () {
  // "Full screen" also hides the browser's own bars (Esc leaves).
  const btn = document.getElementById('map-fs');
  if (!btn || !document.documentElement.requestFullscreen) { if (btn) btn.hidden = true; return; }
  btn.addEventListener('click', () => {
    if (document.fullscreenElement) document.exitFullscreen();
    else document.documentElement.requestFullscreen();
  });
  document.addEventListener('fullscreenchange', () => {
    const on = !!document.fullscreenElement;
    btn.setAttribute('aria-pressed', String(on));
    btn.textContent = on ? 'Exit full screen' : 'Full screen';
  });
})();
</script>
</body>
</html>
