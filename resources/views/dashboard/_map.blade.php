{{--
  Map panel, shared by the dashboard and the full-screen map page.
  Needs: $mapDevices, $mapBarangays; $fullscreen (optional) hides the open-full-screen button.
--}}
  @php
    $mapAps = collect($mapDevices)->where('type', 'ap')->count();
    $mapSw = collect($mapDevices)->where('type', 'switch')->count();
    $mapRouters = collect($mapDevices)->where('type', 'router')->count();
  @endphp
  <section class="panel mapp" aria-labelledby="map-title">
    <div class="panel-head">
      <h2 id="map-title">Access points, switches and routers</h2>
      <div class="map-tools">
        @if ($fullscreen ?? false)
          <a class="map-btn" href="{{ route('dashboard') }}">Dashboard</a>
          <button type="button" class="map-btn" id="map-fs" aria-pressed="false">Full screen</button>
        @else
          {{-- Opens the map alone, filling a new tab --}}
          <a class="map-btn icon" href="{{ route('dashboard.map') }}" target="_blank" rel="noopener"
             title="Open the map full screen in a new tab" aria-label="Open the map full screen in a new tab">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/>
            </svg>
          </a>
        @endif
        <label><input type="checkbox" id="show-ap" checked><span class="legend-pin pin" aria-hidden="true"></span>Access points</label>
        <label><input type="checkbox" id="show-switch" checked><span class="legend-pin pin pin-switch" aria-hidden="true"></span>Switches</label>
        <label><input type="checkbox" id="show-router" checked><span class="legend-pin pin pin-router" aria-hidden="true"></span>Routers</label>
        <label><input type="checkbox" id="show-links" checked><span class="legend-line" aria-hidden="true"></span>Links</label>
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
      <p id="map-summary" aria-live="polite"><b>{{ $mapAps }}</b> access points, <b>{{ $mapSw }}</b> switches and <b>{{ $mapRouters }}</b> routers on the map.</p>
      <p>Circle is an access point, square a switch, diamond a router. Glowing arcs show what each device is plugged into, with light flowing from the uplink; red and dashed when either end is offline. Red and pulsing means offline. Refreshes every minute<span id="map-updated"></span>.</p>
    </div>
  </section>
