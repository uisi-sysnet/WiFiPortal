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

    // System status is CRITICAL when at least this share of access points is offline (or a router is down)
    'critical_ap_percent' => (int) env('CRITICAL_AP_PERCENT', 25),

    // Devices per queued job. More queue workers = faster rounds.
    'poll_batch' => (int) env('SNMP_POLL_BATCH', 50),

    /*
    | Clients connected per access point, read on every poll. There is no standard
    | MIB for this, so each brand has its own OID. The AP's brand is tried first,
    | then the others; the first that answers is used. Any other AP: set its own
    | OID on the device (Edit > SNMP > Client count), found with snmpwalk.
    |   sum    add up the values (one count per radio or SSID)
    |   count  count the rows (one row per connected client)
    */
    'client_oids' => [
        'unifi' => ['label' => 'Ubiquiti UniFi', 'brands' => ['ubiquiti', 'unifi', 'ubnt'],
            'oid' => '1.3.6.1.4.1.41112.1.6.1.2.1.8', 'mode' => 'sum'],       // UBNT-UniFi-MIB unifiVapNumStations
        'airmax' => ['label' => 'Ubiquiti airMAX', 'brands' => ['ubiquiti', 'ubnt', 'airmax'],
            'oid' => '1.3.6.1.4.1.41112.1.4.5.1.15', 'mode' => 'sum'],        // UBNT-AirMAX-MIB ubntWlStatStaCount
        'mikrotik' => ['label' => 'MikroTik', 'brands' => ['mikrotik', 'routerboard'],
            'oid' => '1.3.6.1.4.1.14988.1.1.1.3.1.6', 'mode' => 'sum'],       // MIKROTIK-MIB mtxrWlApClientCount
    ],
    // An AP that answered none of them is asked again after this many minutes
    'client_retry_minutes' => (int) env('AP_CLIENTS_RETRY_MINUTES', 60),

    // Hourly client logs per access point are kept this long
    'client_stats_days' => (int) env('AP_CLIENT_STATS_DAYS', 730),

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
