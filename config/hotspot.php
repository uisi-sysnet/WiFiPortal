<?php

/*
|--------------------------------------------------------------------------
| Public WiFi hotspot defaults
|--------------------------------------------------------------------------
| Every MikroTik added in the dashboard gets its own client subnet carved
| out of one supernet. With the defaults (10.64.0.0/10 split into /20s):
|
|   1,024 gateway blocks x 4,093 client addresses = 4,191,232 addresses
|
| 500,000 concurrent devices therefore needs ~123 fully loaded gateways;
| plan for more, since no gateway should run at 100%.
| Accounts live in RADIUS, so one login works on every gateway.
*/

return [

    'supernet' => env('HOTSPOT_SUPERNET', '10.64.0.0/10'),
    'site_prefix' => (int) env('HOTSPOT_SITE_PREFIX', 20),

    'dns_name' => env('HOTSPOT_DNS_NAME', 'login.wifi'),
    'dns_servers' => env('HOTSPOT_DNS_SERVERS', '1.1.1.1,8.8.8.8'),

    // Public WiFi has high churn: short leases and idle timeouts free addresses fast.
    'lease_time' => env('HOTSPOT_LEASE_TIME', '1h'),
    'idle_timeout' => env('HOTSPOT_IDLE_TIMEOUT', '5m'),
    'keepalive_timeout' => env('HOTSPOT_KEEPALIVE_TIMEOUT', '2m'),
    'session_timeout' => env('HOTSPOT_SESSION_TIMEOUT', '4h'),

    // MikroTik format is upload/download from the client's view: "rx/tx".
    'rate_limit' => env('HOTSPOT_RATE_LIMIT', '5M/10M'),
    'mac_cookie_timeout' => env('HOTSPOT_MAC_COOKIE_TIMEOUT', '1d'),

    // Hosts reachable before login (e.g. your city portal). Comma separated.
    'walled_garden' => array_values(array_filter(array_map('trim',
        explode(',', (string) env('HOTSPOT_WALLED_GARDEN', ''))
    ))),

    'radius' => [
        'host' => env('RADIUS_HOST'),            // leave empty to skip RADIUS
        'secret' => env('RADIUS_SECRET'),
        'auth_port' => (int) env('RADIUS_AUTH_PORT', 1812),
        'acct_port' => (int) env('RADIUS_ACCT_PORT', 1813),
        'timeout' => env('RADIUS_TIMEOUT', '3s'),
        'interim_update' => env('RADIUS_INTERIM_UPDATE', '5m'),
    ],

    'api' => [
        'port' => (int) env('MIKROTIK_API_PORT', 8728),
        'ssl_port' => (int) env('MIKROTIK_API_SSL_PORT', 8729),
        'timeout' => (int) env('MIKROTIK_API_TIMEOUT', 10),
    ],
];
