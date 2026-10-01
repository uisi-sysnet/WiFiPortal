{{-- Map behaviour (pins, links, filters, refresh). Needs: $mapCenter, $mapDevices. --}}
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

  const typeText = { ap: 'Access point', switch: 'Switch', router: 'Router' };
  const byKey = () => Object.fromEntries(devices.map((d) => [d.key, d]));

  // What a device is plugged into; for one that isn't on the map, says so.
  function uplinkText(d, index) {
    if (!d.uplink) return null;
    const up = index[d.uplink];
    return up ? esc(up.name) + ' (' + typeText[up.type].toLowerCase() + ')' : 'a ' + d.uplink.split(':')[0] + ' without a map position';
  }

  function popup(d, index) {
    const up = uplinkText(d, index);
    const down = devices.filter((x) => x.uplink === d.key).length;
    return '<h3>' + esc(d.name) + '</h3>'
      + '<span class="pop-status ' + esc(d.status) + '">' + statusText[d.status] + '</span>'
      + (d.status === 'offline' && d.seen ? ', last seen ' + esc(d.seen) : '')
      + '<dl>'
      + '<dt>Type</dt><dd>' + typeText[d.type] + (d.model ? ', ' + esc(d.model) : '') + '</dd>'
      + '<dt>IP</dt><dd>' + esc(d.ip) + '</dd>'
      + (d.type === 'router' ? '<dt>Users online</dt><dd>' + (d.users === null ? 'Not known' : esc(d.users)) + '</dd>' : '')
      + (up ? '<dt>Connected to</dt><dd>' + up + '</dd>' : '')
      + (down ? '<dt>Plugged in</dt><dd>' + down + ' device' + (down === 1 ? '' : 's') + '</dd>' : '')
      + '<dt>Location</dt><dd>' + (d.type === 'router' ? '' : esc(d.barangay || 'No barangay') + (d.landmark ? '<br>' : '')) + esc(d.landmark || '') + '</dd>'
      + '</dl><a href="' + esc(d.edit) + '">' + (d.type === 'router' ? 'Open router' : 'Edit device') + '</a>';
  }

  function visible() {
    const show = {
      ap: document.getElementById('show-ap').checked,
      switch: document.getElementById('show-switch').checked,
      router: document.getElementById('show-router').checked,
    };
    const status = document.getElementById('map-status').value;
    return devices.filter((d) => show[d.type] && (status === 'all' || d.status === status));
  }

  // Link lines sit under the pins: curved, glowing arcs from each uplink to the device,
  // with light pulses flowing outward. Red and dashed when either end is offline.
  const links = L.layerGroup().addTo(map);

  // Points along a gentle arc from a to b, bowed to one side (like flight paths).
  function arc(a, b, bow) {
    const [x1, y1] = [a.lng, a.lat], [x2, y2] = [b.lng, b.lat];
    const dx = x2 - x1, dy = y2 - y1;
    const cx = (x1 + x2) / 2 - dy * bow, cy = (y1 + y2) / 2 + dx * bow; // control point, off to the side
    const pts = [];
    for (let i = 0; i <= 32; i++) {
      const t = i / 32, u = 1 - t;
      pts.push([u * u * y1 + 2 * u * t * cy + t * t * y2, u * u * x1 + 2 * u * t * cx + t * t * x2]);
    }
    return pts;
  }

  function drawLinks(list, index) {
    links.clearLayers();
    if (!document.getElementById('show-links').checked) return;
    const shown = new Set(list.map((d) => d.key));
    list.forEach((d) => {
      const up = d.uplink && index[d.uplink];
      if (!up || !shown.has(up.key)) return;
      const state = d.status === 'offline' || up.status === 'offline' ? 'off'
        : d.status === 'unknown' || up.status === 'unknown' ? 'unk' : 'ok';
      const trunk = d.type !== 'ap'; // switch and router uplinks are drawn heavier
      const pts = arc(up, d, d.type === 'ap' ? 0.18 : 0.24);
      const tip = esc(d.name) + ' to ' + esc(up.name) + (state === 'off' ? ' (offline)' : '');

      // Soft halo, the line itself, then the moving light on top.
      // Thin lines: a faint halo, a hairline, and small moving sparks. A wide invisible
      // line on top keeps the hover label easy to reach.
      L.polyline(pts, { className: 'link-halo ' + state, weight: trunk ? 3 : 2, interactive: false }).addTo(links);
      L.polyline(pts, { className: 'link ' + state, weight: trunk ? 1 : 0.7, dashArray: state === 'off' ? '4 4' : null, interactive: false }).addTo(links);
      if (state === 'ok') {
        L.polyline(pts, { className: 'link-flow' + (trunk ? ' trunk' : ''), weight: trunk ? 1.6 : 1.2, dashArray: '1.5 22', interactive: false }).addTo(links);
      }
      L.polyline(pts, { className: 'link-hit', weight: 10, opacity: 0 }).bindTooltip(tip, { sticky: true }).addTo(links);
    });
  }

  function render() {
    const list = visible();
    const index = byKey();
    cluster.clearLayers();
    cluster.addLayers(list.map((d) => L.marker([d.lat, d.lng], {
      icon: L.divIcon({ className: 'pin pin-' + d.type + ' ' + d.status, iconSize: d.type === 'ap' ? [14, 14] : d.type === 'router' ? [14, 14] : [13, 13] }),
      title: d.name + ', ' + statusText[d.status],
      dev: d,
    }).bindPopup(popup(d, index))));
    drawLinks(list, index);

    const count = (t) => devices.filter((d) => d.type === t).length;
    const off = devices.filter((d) => d.status === 'offline').length;
    document.getElementById('map-summary').innerHTML =
      '<b>' + count('ap') + '</b> access points, <b>' + count('switch') + '</b> switches and <b>' + count('router') + '</b> routers on the map'
      + (off ? ', <b class="off">' + off + ' offline</b>' : '')
      + (list.length !== devices.length ? '. Showing ' + list.length + '.' : '.');
    document.getElementById('map-empty').hidden = devices.length > 0;
  }

  function fit(list) {
    if (!list.length) return;
    map.fitBounds(L.latLngBounds(list.map((d) => [d.lat, d.lng])), { padding: [40, 40], maxZoom: 17 });
  }

  ['show-ap', 'show-switch', 'show-router', 'show-links', 'map-status'].forEach((id) => document.getElementById(id).addEventListener('change', render));
  document.getElementById('map-barangay').addEventListener('change', (e) => {
    if (e.target.value) fit(devices.filter((d) => d.barangay === e.target.value));
    else if (center.fixed) map.setView([center.lat, center.lng], center.zoom); // back to the Settings view
    else fit(devices);
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
  // A center set in Settings wins; otherwise the map frames every device.
  if (!center.fixed) fit(devices);
  setInterval(refresh, 60000);
})();
</script>
