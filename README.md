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
migrations; starts three queue workers (`wifiportal-queue@1..3`) and the scheduler cron; and
sets up FreeRADIUS (below).
Then edit `.env` (`APP_URL`, `PORTAL_URL`, check `RADIUS_HOST`), run
`sudo -u www-data php artisan optimize`, and create an admin with
`sudo -u www-data php artisan wifi:make-admin`.

### RADIUS (FreeRADIUS on the same server)

The routers do not store the logins. Each MikroTik is a RADIUS client; FreeRADIUS runs on this
server and reads the logins Laravel writes to the `radcheck` table in the same PostgreSQL database.
One registration therefore works on every router in the city.

```
Phone -> MikroTik (RADIUS client) -> FreeRADIUS (UDP 1812/1813) -> PostgreSQL radcheck <- Laravel
```

`setup-server.sh` runs `deploy/setup-radius.sh`, which:

- installs `freeradius`, `freeradius-postgresql`, `freeradius-utils`;
- creates the FreeRADIUS tables (`radacct`, `radpostauth`, ...) next to the app's `radcheck`;
- adds a `radius` database role that can only read logins and write accounting;
- enables the `sql` module (`/etc/freeradius/3.0/mods-available/wifiportal-sql`); the default
  site's `expiration` check ends each login at its access time;
- allows the routers as RADIUS clients and fills `RADIUS_HOST`, `RADIUS_SECRET` (random) and
  `RADIUS_TIMEZONE` in `.env`;
- opens UDP 1812/1813 to the routers only, when `ufw` is on;
- tests a temporary login with `radtest`.

Routers are allowed from the private ranges by default. Set where they come from, and the
address they reach this server on, when it differs:

```bash
sudo RADIUS_CLIENTS=10.10.0.0/16,203.0.113.0/24 RADIUS_ADDRESS=10.10.0.5 bash /var/www/wifiportal/deploy/setup-radius.sh
```

Run it on its own to add RADIUS to a server set up before; skip it during setup with
`WITH_RADIUS=no`. Afterwards use **Re-apply configuration** on each router so it points at RADIUS.
Debug with `sudo systemctl stop freeradius && sudo freeradius -X` (shows every request).

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
   tagged:        untagged in one hotspot network's VLAN
   hotspot 10,    (plain APs, wlan interfaces)
     11, ...
   mgmt    99
   test    20
```

| Network | Default VLAN | Address plan | Reaches |
|---|---|---|---|
| Hotspot networks (1 to 8) | 10, 11, ... | one subnet **per network**, `/24` to `/16` (default `/20` from `10.64.0.0/10`), login + RADIUS | internet only (not each other) |
| Management | 99 | `/24` from `172.20.0.0/14` | internet, router, LAN |
| Test | 20 | `/24` from `172.24.0.0/14`, no login | internet only |
| LAN (optional) | untagged | `/24` from `172.16.0.0/14` | internet, router, management |

Each hotspot network (e.g. "Public WiFi" on VLAN 10, "School" on VLAN 11) gets its own
VLAN, subnet, DHCP server and hotspot server. Management, test and LAN are one per router.

### Hotspot network size, address and user limit

| Setting | Choices |
|---|---|
| Size | `/24` (254 addresses: 253 users + gateway, the minimum) up to `/16` (65,533 users) |
| Network address | empty = the next free subnet of that size in `HOTSPOT_SUPERNET`; or type any private subnet (10.x, 172.16-31.x, 192.168.x, 100.64-127.x) |
| User limit | empty = no limit (DHCP hands out the whole subnet); otherwise 254 up to the subnet's size. The DHCP range is cut to exactly that many addresses, so no more devices can join |

A typed address must be the start of its block (`10.70.0.0/22`, not `10.70.0.5/22`) and may not
overlap another hotspot network on any router, the management/test/LAN plans, or the portal
server's IP. The address and limit can be changed later on the router's page; saving re-applies
the router, updating the gateway, DHCP, NAT and hotspot entries in place (connected phones get
a new address).
VLAN IDs and interface names are set per router on the Add router page (defaults in
`.env`). Tick "Send management untagged" when APs or switches boot without a tag.
On management, test and LAN, addresses `.2`-`.63` are kept for fixed IPs.

## What "Add router" configures

All objects are tagged `publicwifi:*` or use fixed names, so Re-apply updates in place.

| Step | RouterOS |
|---|---|
| Identity | `/system identity` = router name (used as NAS-Identifier) |
| WAN | chosen port taken out of any bridge; keep / DHCP client / static IP + default route |
| Trunk bridge | `bridge-trunk`; trunk ports `admit-only-vlan-tagged` (or native mgmt), hotspot ports untagged with their network's PVID; ingress filtering |
| VLAN table | `/interface bridge vlan` per network (every hotspot VLAN tagged on every trunk), bridge tagged; VLAN filtering switched on last |
| VLAN interfaces | one `/interface vlan` per network on `bridge-trunk` |
| Addressing | gateway `.1`, pool, DHCP server and masquerade per network (masquerade out of the PPPoE interface when WAN is kept and runs PPPoE) |
| Interface lists | management, test and LAN in list `LAN`, WAN port (and its PPPoE) in list `WAN` |
| Firewall | each hotspot network and test: internet only both ways, router management ports blocked; DNS not answered from the WAN side |
| FastTrack | hotspot traffic accepted just above the FastTrack rule, so per-user speed limits and RADIUS byte counts work |
| RADIUS | hotspot service, accounting, interim 5m, CoA accept |
| Hotspot | one server + profile per network (`hotspot-publicwifi`, `hotspot-publicwifi-11`, ...), CHAP + MAC cookie, 1 device per account |
| Walled garden | portal host(s), plus hosts from `HOTSPOT_WALLED_GARDEN` |

## Adding a router

1. Fill in the API connection and press **Test API connection**. "Add and configure router"
   stays disabled until a test succeeds; "Read ports from router" also counts as a test.
2. Changing the IP, port, SSL, username or password afterwards cancels the test.
3. The server enforces the same rule: it stores an HMAC fingerprint of the tested details in
   the session (valid 15 minutes) and refuses to save a router whose details don't match.

## Hotspot login page

Chosen per hotspot network (on the Add router page, or later on the router's page):

| Option | What happens |
|---|---|
| Captive portal from this system | Phones go to `PORTAL_URL/portal/{network code}`, showing the designs chosen for that network |
| Custom URL | Phones go to your external portal |
| Router's built-in page | MikroTik's own page (or, if another network on the router uses the first two, a plain username/password form) |

If any network uses the first two, provisioning downloads one `login.html` from this app with
`/tool fetch` into the shared hotspot folder, adds the portal host(s) to the walled garden
(HTTP and IP), and enables `http-pap`. That one file serves every network: it reads the
router's `$(server-name)` and forwards the phone to that network's target.

### Captive portal designs

A design holds a login page (HTML, Terms, form rules) and an advertisement page. Keep as many
as you like under **Captive portal** in the menu, and pick two per hotspot network: the design
for its login page and the design for its advertisement page.

| You want | Choose |
|---|---|
| One portal and ad on every network | the same design for both pages on every network |
| Each network its own portal and ad | a different design per network |
| One portal, a different ad per network | the same login design everywhere, a different advertisement design per network |

Design changes show on the next page load. Changing a network's login page type or custom
URL re-applies the router, because its `login.html` has to change. Networks that never picked
a design use the first one (the default), which can't be deleted; other designs can be deleted
once no network uses them.

### Splash page flow

1. Phone joins the hotspot SSID and opens any site; the router serves `login.html`.
2. `login.html` sends the phone to the splash page with `mac`, `ip`, `link-login-only`, `link-orig`.
3. The person picks a type: **Resident** (citizen ID only), **Visitor** (full name + mobile
   number or email) or **Student** (full name, school, student ID). Checked in the browser
   and again on the server.
4. "Log in" opens the Terms pop-up. "I agree, connect me" submits.
5. The app records the registration (`hotspot_guests`, with a hash of the accepted Terms),
   creates the credentials, and redirects to the router's login link. Router logs the phone in.

Credentials: a unique username (`wifi-xxxxxxxx`) and random password per registration, locked
to the phone's MAC. With `RADIUS_HOST` set they go into `radcheck` for FreeRADIUS; without it
they are created as local hotspot users on the router through the API (fine for testing, not for scale).

### Access time and roaming

**Settings > Internet access time** sets how long each type of user stays online after
registering, in hours or days (up to 365 days), e.g. residents 30 days, visitors 12 hours,
students 7 days. A change applies to new registrations; people already registered keep their end time.
Default: `HOTSPOT_CREDENTIAL_HOURS`.

Until then the phone goes online on **every router and hotspot network without the captive portal**:

- **With RADIUS:** each login gets an `Expiration` in `radcheck`, and the phone's MAC is
  added as its own login. Routers try the MAC first (`login-by=mac,...`), so a registered
  phone is let in before any page opens, and FreeRADIUS cuts the session at the end time.
  Set `RADIUS_TIMEZONE` to the clock of the RADIUS server.
- **Without RADIUS (or if the MAC login misses):** when the phone reaches the portal, the app
  finds its valid registration, copies the login to that router if needed, and logs the phone
  in through the API, skipping the login and advertisement pages. Counted as "Roamed" on the Users page.

After the time ends, `hotspot:expire-credentials` removes the login everywhere (and ends any
open session) and the person registers again.

Phones use a different random MAC per WiFi name. Use **one SSID city-wide** so a phone keeps
the same MAC, and is recognised, at every site.

### Validation

- **Name:** letters only (accents and ñ allowed), first and last name, no numbers or symbols
  except `.` `'` `-` inside names (Ma., O'Brien, Santos-Reyes). Rejects keyboard mashing
  (asdfg, qwert), repeated letters or syllables (aaa, hahaha), words without vowels, long
  consonant runs, and anything in the editable blocked list.
- **Mobile:** Philippine mobile numbers, 09XX XXX XXXX, +639XX..., 639XX...; stored as +639XXXXXXXXX.
- **Email:** RFC format plus a DNS check that the domain exists.
- **Citizen ID:** must fully match the format set in the editor (regular expression).
- **Student:** school name (3-120 characters) and student ID (letters, numbers, dashes).

### Editing

**Captive portal** in the menu: pick or create a design, then edit its page HTML (with
`[[form]]`, `[[site_name]]`, `[[network_name]]`, `[[router_name]]`, `[[location]]`), Terms in
Markdown, resident ID label/format/help, blocked names, the advertisement page, and an optional
page to open after login. **Preview in new tab** shows unsaved changes. The page lists which
networks show the design's login page and which show its advertisement.

## Ports and models

Each port gets a role: WAN (exactly one), Trunk, Hotspot (untagged, one column per hotspot
network), LAN, or Not used. Trunks carry every hotspot VLAN. A network added later from the
router's page is carried on the trunks only.
Models and their default port names are in `config/mikrotik_models.php`; add your own there.
"Read ports from router" pulls the real list over the API, including wireless interfaces.

Before changing anything the router is checked: every port must exist, and the plan is
refused if it would move the port (or every port of the bridge) the dashboard is connected
through. Easiest setup: let the server reach each router on its WAN address.

## Designed for 500,000 users

No single MikroTik can hold 500,000 hotspot sessions, so capacity comes from the design:

1. **Address plan.** `10.64.0.0/10` is split into 1,024 `/20` blocks of 4,093 devices
   each: 4.19 million client addresses. 500,000 concurrent devices fill about 123 blocks.
   Each hotspot network gets the next free block automatically, so subnets never overlap and
   traffic stays traceable to a site and network. (A router with three hotspot networks uses
   three blocks.)
2. **Many gateways, one user database.** Accounts live in RADIUS, not on the routers.
   500,000 accounts are a small table for PostgreSQL; the routers only carry active sessions.
3. **Churn control.** 1h DHCP leases, 5m idle timeout and 2m keepalive release addresses
   and hotspot host entries quickly.
4. **Headroom per gateway.** Size each site's concurrency to the router model (hotspot
   is CPU-bound; a CCR handles far more than an hAP). Split busy sites across more
   gateways rather than enlarging the block.

Change `HOTSPOT_SITE_PREFIX` (e.g. `/21` for smaller sites) **before** adding routers;
changing it later would overlap existing blocks.

## Dashboard

The top row is live: **Users online**, **Routers**, **Switches**, **Access points**.

- `routers:poll` (scheduler, every `ROUTER_POLL_SECONDS`, default 30) asks each router's API
  for the number of logged-in hotspot users (`/ip/hotspot/active`, counted on the router).
  Users online is the sum over every router that answers; a router that stops answering
  shows offline after `ROUTER_OFFLINE_AFTER` missed polls (default 2) and its users drop out
  of the total.
- Switches and access points come from SNMP (`devices:poll`, every minute).
- The page refreshes the row every 10 seconds without reloading.

Sub-minute polling needs the scheduler cron from `deploy/` (Laravel keeps `schedule:run`
alive for the rest of the minute) and running queue workers.

## Clients per access point and heat map

Every minute `devices:poll` also asks each online access point how many clients are connected.
There is no standard SNMP value for this, so it tries the AP's brand first:

| Brand | OID |
|---|---|
| Ubiquiti UniFi | `1.3.6.1.4.1.41112.1.6.1.2.1.8` (unifiVapNumStations, added up per SSID/radio) |
| Ubiquiti airMAX | `1.3.6.1.4.1.41112.1.4.5.1.15` (ubntWlStatStaCount) |
| MikroTik | `1.3.6.1.4.1.14988.1.1.1.3.1.6` (mtxrWlApClientCount) |

Any other brand: on the AP's **Edit > SNMP > Client count OID**, enter the OID from the
vendor's MIB, and whether it gives a count per radio (added up) or a row per client (rows
counted). Find it with `snmpwalk -v2c -c <community> <ap-ip> <oid>`. **Test SNMP** shows the
count before you save. An AP that answers none of them is tried again every hour
(`AP_CLIENTS_RETRY_MINUTES`). More brands go in `config/devices.php` (`client_oids`).

Counts are logged per AP per hour (average and peak) in `ap_client_stats`, kept for
`AP_CLIENT_STATS_DAYS` (default 730) and pruned daily.

- **Users page > Clients per access point:** every AP for the period chosen at the top (Day to
  All): clients now, average, peak and when, share of all clients; busiest hour; CSV download.
- **Report export:** tick "Add clients per access point" for a second page (top 40 APs).
- **Heat map** (Users > Heat map): average or peak clients over a period or date range, or
  clients now, per barangay or all; busiest barangays and APs; Print. APs need a map position.
- **Dashboard map:** the **Heat** checkbox overlays clients connected now; AP pop-ups show clients.

## Events, Telegram alerts and the automatic report

Every change of state is logged (**Logs**, and **Recent events** on the dashboard): a router
stops answering or comes back, an access point or switch goes offline or comes back (after
`SNMP_OFFLINE_AFTER` missed checks, so one lost packet is not an alarm), a router or network
gets busy, full, or back to normal, and reports sent.

**Telegram** (Settings > Telegram alerts): create a bot with @BotFather, send `/start` to it
(or add it to your team's group), paste the token, press **Find chats**, pick the chat, save,
and **Send test message**. `notify:send` runs every minute and puts everything new into one
message, so 200 access points going down at once is one message. Choose which events are
sent. If Telegram can't be reached the events wait and are retried; ones older than 6 hours
are dropped. The server needs HTTPS out to `api.telegram.org`. The token is stored encrypted.

**Network status picture** (Settings > Telegram > Network status picture): a troubleshooting
report as one picture (1080 px wide, made on the server with GD), sent to the Telegram chats
twice a day: an **AM report** (default 08:00, any time 00:00-11:59) and a **PM report** (default
20:00, any time 12:00-23:59), Asia/Manila. Either can be switched off. Each picture and caption is
marked "AM report" or "PM report".

- **System status:** CRITICAL (a router is down, `CRITICAL_AP_PERCENT` (25) % of access points are
  offline, or a router/network is full), WARNING (any switch or access point offline, or busy),
  or NORMAL, with the reasons.
- **Devices:** routers, switches and access points online, offline and not checked yet.
- **Check these first:** the root causes, most important first, each with its location, how
  long it has been down, a first diagnosis and what to check on site. Worked out from
  "Connected to": an access point that is offline while its switch is online is the problem
  itself (check its PoE / power, cable and port); when the switch is offline too, the switch is
  the cause and its access points are listed under it, as they come back with it. Routers come
  first, then switches, then access points. "Never answered" points to the IP / SNMP settings.
- **All offline devices:** name, barangay and landmark, how long down, and whether it is the
  problem itself or behind another device.
- Capacity alerts and a short troubleshooting guide.

The Telegram caption repeats the status and the first five things to check. **Preview picture**
shows it; **Send the picture now** sends one (`php artisan telegram:report --now`).
`telegram:report` runs every minute; a time missed by more than 3 hours is skipped. The
diagnosis is in `App\Services\Monitoring\Troubleshooter`.

**Email** (Settings > Email): an SMTP server (for Gmail: smtp.gmail.com, 587, TLS, an app
password), stored encrypted; **Send test email** checks it. Left empty, `MAIL_*` in `.env` is used.

**Automatic report** (Settings > Automatic report): every day (default 08:00), every week
on a chosen day, or every month on day 1-28, at a chosen time (Asia/Manila). It covers the
last 24 hours / 7 days / 30 days: what is down now, outages and recoveries, users online and
the peak, registrations, clients per access point, open capacity alerts and RADIUS logins,
with the Users report PDF attached. It also goes to Telegram (summary + PDF) when "Scheduled
report" is ticked there. **Send a report now** sends one at once; `php artisan reports:send --now`
does the same. `reports:send` runs every minute and sends a due report once; if the server was
off for more than 6 hours past the time, that one is skipped.

## RADIUS page

**RADIUS** in the menu, for "I registered but I can't connect":

- **Overview:** is RADIUS answering, logins accepted and refused (last hour, 24 hours),
  sessions online, and valid registrations that have no login in RADIUS (made before RADIUS
  was switched on).
- **Login attempts:** every attempt with the likely reason for a refusal: access time ended,
  a different phone than the one that registered, unknown login, or a phone not registered yet.
  Routers try a phone's MAC first, so unregistered phones are refused once by MAC before they
  see the portal: these are counted apart, as they are normal.
- **Sessions:** online now or all, per router: start, time online, data down/up, why it ended.
- **Look up a phone:** by MAC (WiFi settings > the network > details) or login: its
  registration, its login in RADIUS with the end time, recent attempts and sessions.

Passwords are never shown, and `setup-radius.sh` makes the database blank the password
FreeRADIUS logs with each attempt. Attempts are kept `RADIUS_AUTH_LOG_DAYS` (30) and finished
sessions `RADIUS_ACCT_DAYS` (365); `radius:prune` runs daily.

## Capacity and alerts

Each router has a **rated users** figure: how many hotspot users it handles at once.
It comes from its model in `config/mikrotik_models.php` (field estimates, e.g. hEX 150,
RB5009 700, CCR2004 1,200, CCR2116/CCR2216 2,500), or is set on the router's page.
Load-test one unit of each model and set the real figure there.

Every `routers:poll` also reads CPU load, memory and DHCP leases per hotspot network, and
opens an alert (dashboard, router list and router page) when something is busy (80%) or
full (95%). The advice depends on what is full:

| Alert | Meaning | What to do |
|---|---|---|
| Users | users online vs the router's rating | add another gateway router or move access points; another VLAN on the same router adds no capacity |
| CPU | smoothed CPU load (85% busy) | same as above |
| Addresses | DHCP leases vs a hotspot network's pool | enlarge the network address or add another hotspot network (VLAN); on another router if this one is busy too |

Alerts close by themselves once the value is 5 points under the busy line. Thresholds:
`CAPACITY_*` in `.env`.

## Next steps

- User registration portal (the hotspot `login.html` pointing at Laravel).
- Health checks: a scheduled job reading `/ip/hotspot/active` counts per router.
