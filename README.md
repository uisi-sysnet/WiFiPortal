# Public WiFi Control (Laravel + MikroTik Hotspot)

Admin login, a router list, and an "Add router" page. Adding a MikroTik
connects to it over the RouterOS API and configures a complete hotspot.

## Development (Windows)

```bash
composer install
cp .env.production.example .env         # then set APP_ENV=local, APP_DEBUG=true, DB_*, FFMPEG_PATH
php artisan key:generate
php artisan migrate
php artisan wifi:make-admin you@example.com --name="Network Admin"
php artisan queue:work                   # keep running
php artisan schedule:work                # keep running: SNMP polling, credential expiry
php artisan serve
```

PHP needs the `pdo_pgsql`, `snmp`, `gd`, `mbstring` and `intl` extensions. Upload limits
(`upload_max_filesize`, `post_max_size` = 110M) go in `php.ini`, not `.env`.

## Deploy (Ubuntu 24.04 + nginx)

Everything is in `deploy/`: nginx site, PHP limits, queue worker service, scheduler cron,
and two scripts.

**First time**, on the server:

```bash
sudo git clone https://github.com/uisi-sysnet/WiFiPortal.git /var/www/wifiportal
sudo chown -R "$USER" /var/www/wifiportal
sudo bash /var/www/wifiportal/deploy/setup-server.sh
```

It installs nginx, PHP 8.3-FPM (+ pgsql, snmp, gd), PostgreSQL, ffmpeg and Composer; creates
the database with a random password; writes `.env` from `.env.production.example`; runs
migrations; and starts three queue workers (`wifiportal-queue@1..3`) and the scheduler cron.
Then edit `.env` (`APP_URL`, `PORTAL_URL`, `RADIUS_*`), run
`sudo -u www-data php artisan optimize`, and create an admin with
`sudo -u www-data php artisan wifi:make-admin`.

**Every update**: push from Windows, then on the server:

```bash
bash /var/www/wifiportal/deploy/deploy.sh
```

Things to know:

- **Keep HTTP on port 80.** Phones open `/portal/...` before login and routers download
  `login.html` with `/tool fetch`. HTTPS for the dashboard is fine (`certbot --nginx`), but
  answer "no redirect", and leave `PORTAL_URL` on `http://`.
- **Reachability.** The server must reach each router's API (8728/8729) and each AP/switch on
  SNMP (UDP 161); phones on every hotspot VLAN must reach the server on port 80.
- **After editing `.env`** run `sudo -u www-data php artisan optimize` (config is cached).
- **Logs:** `storage/logs/laravel-*.log`, `journalctl -u wifiportal-queue@1`, `/var/log/nginx/`.
- Run artisan as `www-data` (`sudo -u www-data php artisan ...`) so cache and log files stay writable.

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

## Adding a router

1. Fill in the API connection and press **Test API connection**. "Add and configure router"
   stays disabled until a test succeeds; "Read ports from router" also counts as a test.
2. Changing the IP, port, SSL, username or password afterwards cancels the test.
3. The server enforces the same rule: it stores an HMAC fingerprint of the tested details in
   the session (valid 15 minutes) and refuses to save a router whose details don't match.

## Hotspot login page

Chosen per router on the Add router page:

| Option | What happens |
|---|---|
| Splash page from this system | Router's `login.html` forwards phones to `PORTAL_URL/portal/{code}` |
| Custom URL | Same forwarder, pointed at your external portal |
| Router's built-in page | MikroTik's own page, nothing changed |

For the first two, provisioning downloads `login.html` from this app with `/tool fetch`,
adds the portal host to the walled garden (HTTP and IP), and enables `http-pap`.

### Splash page flow

1. Phone joins the hotspot SSID and opens any site; the router serves `login.html`.
2. `login.html` sends the phone to the splash page with `mac`, `ip`, `link-login-only`, `link-orig`.
3. Visitor enters full name + mobile number or email; a resident ticks "I'm a resident"
   and enters only the citizen ID. Checked in the browser and again on the server.
4. "Log in" opens the Terms pop-up. "I agree, connect me" submits.
5. The app records the registration (`hotspot_guests`, with a hash of the accepted Terms),
   creates the credentials, and redirects to the router's login link. Router logs the phone in.

Credentials: username `mac-<device mac>` with a fresh random password each time.
With `RADIUS_HOST` set they go into `radcheck` for FreeRADIUS; without it they are created
as local hotspot users on that router through the API (fine for testing, not for scale).

### Validation

- **Name:** letters only (accents and ñ allowed), first and last name, no numbers or symbols
  except `.` `'` `-` inside names (Ma., O'Brien, Santos-Reyes). Rejects keyboard mashing
  (asdfg, qwert), repeated letters or syllables (aaa, hahaha), words without vowels, long
  consonant runs, and anything in the editable blocked list.
- **Mobile:** Philippine mobile numbers, 09XX XXX XXXX, +639XX..., 639XX...; stored as +639XXXXXXXXX.
- **Email:** RFC format plus a DNS check that the domain exists.
- **Citizen ID:** must fully match the format set in the editor (regular expression).

### Editing

**Splash page** in the menu: page HTML (with `[[form]]`, `[[site_name]]`, `[[router_name]]`,
`[[location]]`), Terms in Markdown, resident ID label/format/help, blocked names, and an
optional page to open after login. **Preview in new tab** shows unsaved changes.

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
