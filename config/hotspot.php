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

    // VLANs on the trunk bridge. These are the defaults shown on the
    // Add router page; each router can use its own IDs and interface names.
    'vlans' => [
        'hotspot' => ['id' => (int) env('VLAN_HOTSPOT_ID', 10), 'name' => env('VLAN_HOTSPOT_NAME', 'vlan10-hotspot')],
        'mgmt' => ['id' => (int) env('VLAN_MGMT_ID', 99), 'name' => env('VLAN_MGMT_NAME', 'vlan99-mgmt')],
        'test' => ['id' => (int) env('VLAN_TEST_ID', 20), 'name' => env('VLAN_TEST_NAME', 'vlan20-test')],
    ],
    // Send management untagged (native VLAN) on trunk ports, for APs that boot untagged.
    'mgmt_native' => (bool) env('VLAN_MGMT_NATIVE', false),

    // One /24 per router from each plan, same block number as the hotspot /20.
    // Each /14 holds 1,024 /24s, matching the 1,024 hotspot blocks.
    'lan_supernet' => env('LAN_SUPERNET', '172.16.0.0/14'),    // untagged office LAN (optional)
    'lan_prefix' => (int) env('LAN_PREFIX', 24),
    'lan_lease_time' => env('LAN_LEASE_TIME', '1d'),

    'mgmt_supernet' => env('MGMT_SUPERNET', '172.20.0.0/14'),  // APs, switches, CCTV
    'mgmt_prefix' => (int) env('MGMT_PREFIX', 24),
    'mgmt_lease_time' => env('MGMT_LEASE_TIME', '1d'),

    'test_supernet' => env('TEST_SUPERNET', '172.24.0.0/14'),  // technicians, internet without login
    'test_prefix' => (int) env('TEST_PREFIX', 24),
    'test_lease_time' => env('TEST_LEASE_TIME', '1h'),

    // Public address of this Laravel app as routers and phones reach it
    // (e.g. https://wifi.example.gov.ph). Used for the splash page and login.html.
    'portal_url' => env('PORTAL_URL', env('APP_URL')),

    'dns_name' => env('HOTSPOT_DNS_NAME', 'login.wifi'),
    'dns_servers' => env('HOTSPOT_DNS_SERVERS', '1.1.1.1,8.8.8.8'),

    // Public WiFi has high churn: short leases and idle timeouts free addresses fast.
    'lease_time' => env('HOTSPOT_LEASE_TIME', '1h'),
    'idle_timeout' => env('HOTSPOT_IDLE_TIMEOUT', '5m'),
    'keepalive_timeout' => env('HOTSPOT_KEEPALIVE_TIMEOUT', '2m'),
    'session_timeout' => env('HOTSPOT_SESSION_TIMEOUT', '4h'),

    // Each registration gets its own generated username and password, locked to
    // the phone's MAC. They are deleted this many hours later; the user then
    // registers again. Prefix and length shape usernames like "wifi-k7m2p9qx".
    'credential_hours' => (int) env('HOTSPOT_CREDENTIAL_HOURS', 24),
    'username_prefix' => env('HOTSPOT_USERNAME_PREFIX', 'wifi-'),

    // Photos and videos on the advertisement page. Phones are not logged in yet
    // when they load it, so everything is sized for a slow link (budget_kbps).
    'media' => [
        'budget_kbps' => (int) env('PORTAL_MEDIA_BUDGET_KBPS', 1000), // 1 Mbps per user before login
        'image_max_width' => 1080,                                    // phone screens
        'image_target_kb' => 180,                                     // about 1.5 s at 1 Mbps
        'image_upload_max_mb' => 15,

        // With ffmpeg, any video is re-encoded to 640 px, about 500 kbps, max 30 s.
        // Without it, only MP4s up to video_raw_max_mb are accepted, unchanged.
        'ffmpeg' => env('FFMPEG_PATH'),                               // e.g. C:\ffmpeg\bin\ffmpeg.exe or /usr/bin/ffmpeg
        'video_max_seconds' => 30,
        'video_upload_max_mb' => 100,
        'video_raw_max_mb' => 2,
    ],

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
        // Time zone of the FreeRADIUS server's clock, for the Expiration of each login
        'timezone' => env('RADIUS_TIMEZONE', 'UTC'),
        // RADIUS page: login attempts and finished sessions are kept this long (days)
        'auth_log_days' => (int) env('RADIUS_AUTH_LOG_DAYS', 30),
        'acct_days' => (int) env('RADIUS_ACCT_DAYS', 365),
        // A session without an interim update for this long is not counted as online
        'online_minutes' => (int) env('RADIUS_ONLINE_MINUTES', 15),
    ],

    'api' => [
        'port' => (int) env('MIKROTIK_API_PORT', 8728),
        'ssl_port' => (int) env('MIKROTIK_API_SSL_PORT', 8729),
        'timeout' => (int) env('MIKROTIK_API_TIMEOUT', 10),
    ],

    // Live dashboard: `routers:poll` asks every router whether it answers and how
    // many hotspot users are logged in. Interval 15, 30 or 60 seconds.
    'poll' => [
        'seconds' => (int) env('ROUTER_POLL_SECONDS', 30),
        'timeout' => (int) env('ROUTER_POLL_TIMEOUT', 3),     // short: a dead router mustn't hold up the rest
        'batch' => (int) env('ROUTER_POLL_BATCH', 20),        // routers per queued job
        'offline_after' => (int) env('ROUTER_OFFLINE_AFTER', 2), // missed polls before "offline"
    ],

    // Capacity alerts, checked on every router poll. Percentages of the router's
    // rated users, its smoothed CPU, or a hotspot network's DHCP address pool.
    'capacity' => [
        'busy' => (int) env('CAPACITY_BUSY_PERCENT', 80),
        'full' => (int) env('CAPACITY_FULL_PERCENT', 95),
        'cpu_busy' => (int) env('CAPACITY_CPU_BUSY_PERCENT', 85),
        'cpu_full' => (int) env('CAPACITY_CPU_FULL_PERCENT', 95),
        'default_users' => (int) env('CAPACITY_DEFAULT_USERS', 200), // models not in config/mikrotik_models.php
    ],

    // "Users online" chart: `users:snapshot` saves the total every 5 minutes.
    // Logs > User activity is kept this long (days)
    'activity_log_days' => (int) env('ACTIVITY_LOG_DAYS', 1095),

    'history' => [
        'keep_days' => (int) env('USER_HISTORY_DAYS', 400),           // a little over a year
        'timezone' => env('DASHBOARD_TIMEZONE', 'Asia/Manila'),      // hours, days and months on the chart
    ],
];
