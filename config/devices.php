<?php

/*
| Access points and switches are monitored over SNMP (v1, v2c or v3).
| Every minute `devices:poll` asks each one for sysName, sysDescr and
| sysUpTime. An answer means online; no answer for `offline_after`
| checks in a row means offline (one lost packet doesn't flip it).
*/

return [
    'snmp_timeout_ms' => (int) env('SNMP_TIMEOUT_MS', 1500),
    'snmp_retries' => (int) env('SNMP_RETRIES', 1),
    'offline_after' => (int) env('SNMP_OFFLINE_AFTER', 2),

    // Devices per queued job. More queue workers = faster rounds.
    'poll_batch' => (int) env('SNMP_POLL_BATCH', 50),

    // Dashboard map: where it opens when no device has a position yet.
    'map' => [
        'lat' => (float) env('MAP_CENTER_LAT', 14.4081),
        'lng' => (float) env('MAP_CENTER_LNG', 121.0415),
        'zoom' => (int) env('MAP_ZOOM', 13),

        // Optional free key from https://carto.com/basemaps/apikey/ for CARTO's dark map.
        // Without it the map uses OpenStreetMap tiles (no key), darkened to match.
        'carto_key' => env('CARTO_API_KEY'),
    ],
];