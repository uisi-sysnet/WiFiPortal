# Public WiFi Control (Laravel + MikroTik Hotspot)

Admin login, a router list, and an "Add router" page. Adding a MikroTik
connects to it over the RouterOS API and configures a complete hotspot.

## Install

```bash
composer create-project laravel/laravel publicwifi
cd publicwifi
composer require evilfreelancer/routeros-api-php
# copy this folder's files over the new project, then:
cat .env.hotspot.example >> .env        # edit DB_*, RADIUS_* etc.
php artisan migrate
php artisan wifi:make-admin you@example.com --name="Network Admin"
php artisan queue:work                   # keep running (systemd/supervisor in production)
php artisan serve
```

Requires Laravel 11 or newer (uses `casts()` and the `Queueable` job trait).
`routes/console.php` here only holds the admin command; merge it into yours.

## Prepare each MikroTik (once, from Winbox or terminal)

```
/user group add name=wifi-api policy=read,write,api,test
/user add name=wifi-api group=wifi-api password=CHANGE_ME address=<LARAVEL_SERVER_IP>/32
/ip service set api address=<LARAVEL_SERVER_IP>/32 disabled=no
```

Keep API traffic on a management VLAN or VPN. For API-SSL, create and sign a
certificate, then `/ip service set api-ssl certificate=<cert> address=<LARAVEL_SERVER_IP>/32 disabled=no`.

## What "Add router" configures

All objects are tagged `publicwifi:*` or use fixed names, so Re-apply updates in place.

| Step | RouterOS |
|---|---|
| Identity | `/system identity` = router name (used as NAS-Identifier) |
| Bridge | `bridge-hotspot`, hotspot port added to it |
| Address | gateway `.1` of the allocated block |
| Pool + DHCP | `pool-hotspot`, `dhcp-hotspot`, lease 1h |
| DNS | upstream servers, router answers clients |
| NAT | masquerade block out the WAN port |
| Firewall | drop FTP/SSH/Telnet/Winbox/API from the hotspot side |
| RADIUS | hotspot service, accounting, interim 5m, CoA accept |
| Hotspot | profile `hsprof-publicwifi` (CHAP + MAC cookie), server `hotspot-publicwifi` |
| User profile | `default`: 1 device per account, 5M/10M, 4h session |
| Walled garden | hosts from `HOTSPOT_WALLED_GARDEN` |

## Designed for 500,000 users

No single MikroTik can hold 500,000 hotspot sessions, so capacity comes from the design:

1. **Address plan.** `10.64.0.0/10` is split into 1,024 `/20` blocks of 4,093 devices
   each: 4.19 million client addresses. 500,000 concurrent devices fill about 123 blocks.
   Each router gets the next free block automatically, so subnets never overlap and
   traffic stays traceable to a site.
2. **Many gateways, one user database.** Accounts live in RADIUS, not on the routers.
   500,000 accounts are a small table for PostgreSQL; the routers only carry active sessions.
3. **Churn control.** 1h DHCP leases, 5m idle timeout and 2m keepalive release addresses
   and hotspot host entries quickly.
4. **Headroom per gateway.** Size each site's concurrency to the router model (hotspot
   is CPU-bound; a CCR handles far more than an hAP). Split busy sites across more
   gateways rather than enlarging the block.

Change `HOTSPOT_SITE_PREFIX` (e.g. `/21` for smaller sites) **before** adding routers;
changing it later would overlap existing blocks.

## Next steps

- FreeRADIUS with the `sql` module on the same PostgreSQL (`radcheck`, `radreply`, `radacct`)
  so Laravel creates users and reads usage.
- User registration portal (the hotspot `login.html` pointing at Laravel).
- Health checks: a scheduled job reading `/ip/hotspot/active` counts per router.
