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

## Network layout per router

```
            ISP
             |
           [WAN]  keep / DHCP / static
             |
        MikroTik router ---- [LAN ports] bridge-lan (untagged office LAN, optional)
             |
        bridge-trunk  (VLAN filtering)
         |        |
   [Trunk ports]  [Hotspot ports]
   tagged:        untagged in hotspot VLAN
   hotspot 10     (plain APs, wlan interfaces)
   mgmt    99
   test    20
```

| Network | Default VLAN | Address plan (block N per router) | Reaches |
|---|---|---|---|
| Hotspot | 10 | `/20` from `10.64.0.0/10`, login + RADIUS | internet only |
| Management | 99 | `/24` from `172.20.0.0/14` | internet, router, LAN |
| Test | 20 | `/24` from `172.24.0.0/14`, no login | internet only |
| LAN (optional) | untagged | `/24` from `172.16.0.0/14` | internet, router, management |

VLAN IDs and interface names are set per router on the Add router page (defaults in
`.env`). Tick "Send management untagged" when APs or switches boot without a tag.
On management, test and LAN, addresses `.2`-`.63` are kept for fixed IPs.

## What "Add router" configures

All objects are tagged `publicwifi:*` or use fixed names, so Re-apply updates in place.

| Step | RouterOS |
|---|---|
| Identity | `/system identity` = router name (used as NAS-Identifier) |
| WAN | chosen port taken out of any bridge; keep / DHCP client / static IP + default route |
| Trunk bridge | `bridge-trunk`; trunk ports `admit-only-vlan-tagged` (or native mgmt), hotspot ports untagged PVID; ingress filtering |
| VLAN table | `/interface bridge vlan` per network, bridge tagged; VLAN filtering switched on last |
| VLAN interfaces | one `/interface vlan` per network on `bridge-trunk` |
| Addressing | gateway `.1`, pool, DHCP server and masquerade per network |
| Interface lists | management, test and LAN in list `LAN`, WAN port in list `WAN` |
| Firewall | hotspot and test: internet only both ways, router management ports blocked |
| RADIUS | hotspot service, accounting, interim 5m, CoA accept |
| Hotspot | on the hotspot VLAN interface, CHAP + MAC cookie, 1 device per account |
| Walled garden | hosts from `HOTSPOT_WALLED_GARDEN` |

## Ports and models

Each port gets a role: WAN (exactly one), Trunk, Hotspot (untagged), LAN, or Not used.
Models and their default port names are in `config/mikrotik_models.php`; add your own there.
"Read ports from router" pulls the real list over the API, including wireless interfaces.

Before changing anything the router is checked: every port must exist, and the plan is
refused if it would move the port (or every port of the bridge) the dashboard is connected
through. Easiest setup: let the server reach each router on its WAN address.

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
