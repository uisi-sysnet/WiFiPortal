<?php

namespace App\Services\Mikrotik;

use App\Models\HotspotNetwork;
use App\Models\MikrotikRouter;
use Illuminate\Support\Collection;
use RouterOS\Client;
use RouterOS\Query;
use RuntimeException;
use Throwable;

/**
 * Pushes the public-WiFi setup to a MikroTik through the RouterOS API
 * (package: evilfreelancer/routeros-api-php):
 *
 *   WAN port       -> internet (keep / DHCP client / static)
 *   Trunk ports    -> bridge-trunk, tagged: every hotspot VLAN, management and test
 *   Hotspot ports  -> bridge-trunk, untagged in one hotspot network's VLAN (plain APs, wlan)
 *   LAN ports      -> bridge-lan, untagged office network (optional)
 *
 * Each hotspot network gets its own VLAN interface, subnet, DHCP server and
 * hotspot server; they are isolated from each other and from internal networks.
 *
 * Every step is idempotent: objects are found by name or by a
 * "publicwifi:*" comment and updated in place, so Re-apply repairs drift.
 */
class HotspotProvisioner
{
    public const TRUNK = 'bridge-trunk';
    public const LAN_BRIDGE = 'bridge-lan';
    public const SERVER_PROFILE = 'hsprof-publicwifi';
    public const SERVER = 'hotspot-publicwifi';
    public const TAG = 'publicwifi';

    private const MGMT_PORTS = '21,22,23,8291,8728,8729';

    /** @var Client|object|null RouterOS API client */
    private ?object $client = null;
    private array $log = [];

    /**
     * Logs in and reads the basics only. Used by the "Test API connection" button.
     */
    public function testConnection(MikrotikRouter $router): array
    {
        $this->connect($router);
        $resource = $this->rows(new Query('/system/resource/print'))[0] ?? [];

        return [
            'identity' => $this->rows(new Query('/system/identity/print'))[0]['name'] ?? null,
            'board_name' => $resource['board-name'] ?? null,
            'ros_version' => $resource['version'] ?? null,
            'uptime' => $resource['uptime'] ?? null,
        ];
    }

    /**
     * Turns library/socket errors into something an admin can act on.
     */
    public static function explain(Throwable $e, MikrotikRouter $router): string
    {
        $raw = $e->getMessage();
        $class = class_basename($e);
        $target = "{$router->host}:{$router->api_port}";

        if (str_contains($class, 'Credentials') || stripos($raw, 'invalid user name or password') !== false
            || stripos($raw, 'cannot log in') !== false) {
            return 'Wrong API username or password, or this user is not allowed to log in from the dashboard server\'s IP.';
        }
        if (stripos($raw, 'timed out') !== false || stripos($raw, 'timeout') !== false) {
            return "No answer from {$target}. Check the IP, that the router is online, and that a firewall is not dropping the API port.";
        }
        if (stripos($raw, 'refused') !== false || stripos($raw, 'unable to establish') !== false
            || str_contains($class, 'Connect')) {
            return "Could not open {$target}. Check that the ".($router->use_ssl ? 'api-ssl' : 'api')
                .' service is enabled in /ip service and that its "Available From" includes this server.';
        }
        if ($router->use_ssl && (stripos($raw, 'ssl') !== false || stripos($raw, 'crypto') !== false)) {
            return 'The SSL handshake failed. Make sure api-ssl has a certificate assigned in /ip service.';
        }

        return $raw;
    }

    /**
     * Logs a device into the hotspot from the server: the router checks the
     * username/password (locally or through RADIUS) and opens internet for
     * that MAC and IP. The phone never sees the credentials.
     */
    public function hotspotLogin(MikrotikRouter $router, string $username, string $password, string $mac, string $ip): void
    {
        $this->connect($router);
        $this->run((new Query('/ip/hotspot/active/login'))
            ->equal('user', $username)
            ->equal('password', $password)
            ->equal('mac-address', $mac)
            ->equal('ip', $ip), 'Hotspot login:');
    }

    /** Creates or updates a local hotspot user (used when RADIUS is not set up yet). */
    public function upsertGuestUser(MikrotikRouter $router, string $username, string $password, ?string $mac = null): void
    {
        $this->connect($router);
        $this->ensure('/ip/hotspot/user', ['name' => $username], array_filter([
            'password' => $password,
            'profile' => 'default',
            'mac-address' => $mac, // only this device can log in with it
            'comment' => self::TAG.':guest',
        ]));
    }

    /** Removes expired local hotspot users by name. */
    public function removeGuestUsers(MikrotikRouter $router, array $usernames): void
    {
        $this->connect($router);
        foreach ($usernames as $name) {
            $id = $this->rows((new Query('/ip/hotspot/user/print'))->where('name', $name))[0]['.id'] ?? null;
            if ($id) {
                $this->run((new Query('/ip/hotspot/user/remove'))->equal('.id', $id));
            }
        }
    }

    /**
     * Points each network's hotspot at its login page. The router has one
     * login.html (downloaded from this app); it reads $(server-name) and
     * forwards phones to that network's portal or custom URL. Networks set to
     * the built-in page get a plain username/password form from the same file.
     *
     * @param  Collection<int, HotspotNetwork>  $networks
     */
    private function setupExternalLogin(Collection $networks, string $dir): void
    {
        $hosts = $networks->filter->usesExternalLogin()
            ->map(fn (HotspotNetwork $n) => parse_url((string) $n->loginTarget(), PHP_URL_HOST))
            ->filter()->unique()->values();

        // The first host keeps the comment used before networks existed, so re-apply updates it in place.
        foreach ($hosts as $i => $host) {
            $suffix = $i === 0 ? '' : ':'.$host;
            $this->ensure('/ip/hotspot/walled-garden', ['comment' => self::TAG.':login-host'.$suffix], [
                'dst-host' => $host,
                'action' => 'allow',
            ]);
            $this->ensure('/ip/hotspot/walled-garden/ip', ['comment' => self::TAG.':login-host-ip'.$suffix], [
                ...(filter_var($host, FILTER_VALIDATE_IP) ? ['dst-address' => $host] : ['dst-host' => $host]),
                'action' => 'accept',
            ]);
        }

        // The router downloads its login.html from this app (works on RouterOS 6 and 7).
        $url = $networks->first()->loginFileUrl();
        $fetch = (new Query('/tool/fetch'))
            ->equal('url', $url)
            ->equal('dst-path', "{$dir}/login.html");
        if (str_starts_with($url, 'https://')) {
            $fetch->equal('check-certificate', 'no');
        }
        $this->run($fetch, "Could not download login.html from {$url}:");

        foreach ($networks as $n) {
            $this->step(match ($n->login_mode) {
                'portal', 'custom' => "Login page for {$n->name}: forwards to {$n->loginTarget()}",
                default => "Login page for {$n->name}: username and password form",
            });
        }
        if ($hosts->isNotEmpty()) {
            $this->step('Reachable before login: '.$hosts->implode(', '));
        }
    }

    private function connect(MikrotikRouter $router): void
    {
        $this->client = $this->makeClient([
            'host' => $router->host,
            'user' => $router->username,
            'pass' => $router->password,
            'port' => $router->api_port,
            'ssl' => $router->use_ssl,
            'timeout' => config('hotspot.api.timeout'),
            'attempts' => 1,
        ]);
    }

    /** Separate so tests can swap in a fake router. */
    protected function makeClient(array $config): object
    {
        return new Client($config);
    }

    /**
     * Reads facts and the physical ports, marking which bridge each port is in
     * and which port(s) carry the dashboard's own API connection.
     */
    public function inspect(MikrotikRouter $router): array
    {
        $this->connect($router);

        $resource = $this->rows(new Query('/system/resource/print'))[0] ?? [];
        $identity = $this->rows(new Query('/system/identity/print'))[0]['name'] ?? null;

        $members = [];
        foreach ($this->rows(new Query('/interface/bridge/port/print')) as $row) {
            if (isset($row['interface'], $row['bridge'])) {
                $members[$row['interface']] = $row['bridge'];
            }
        }

        // Which interface holds the IP we are connected to? (null if behind NAT)
        $apiIp = $this->apiIp($router);
        $apiInterface = null;
        foreach ($this->rows(new Query('/ip/address/print')) as $row) {
            if (strtok((string) ($row['address'] ?? ''), '/') === $apiIp) {
                $apiInterface = $row['interface'] ?? null;
                break;
            }
        }

        $ports = [];
        foreach ($this->rows(new Query('/interface/ethernet/print')) as $row) {
            $ports[] = $this->port($row['name'], $members, $apiInterface);
        }
        // Legacy wireless (v6 / v7 "wireless") and the v7 "wifi" package; either may be absent.
        foreach (['/interface/wireless/print', '/interface/wifi/print'] as $menu) {
            try {
                foreach ($this->rows(new Query($menu)) as $row) {
                    $ports[] = $this->port($row['name'], $members, $apiInterface, 'wireless');
                }
            } catch (Throwable) {
                // package not installed on this router
            }
        }

        return [
            'identity' => $identity,
            'board_name' => $resource['board-name'] ?? null,
            'ros_version' => $resource['version'] ?? null,
            'api_interface' => $apiInterface,
            'ports' => $ports,
        ];
    }

    /**
     * Inspect + check the chosen port roles are safe. Returns facts to store.
     *
     * @param  Collection<int, HotspotNetwork>  $networks  the router's hotspot networks (may be unsaved)
     */
    public function probe(MikrotikRouter $router, Collection $networks): array
    {
        $info = $this->inspect($router);
        $this->checkPlan($router, $info, $networks);

        return [
            'identity' => $info['identity'],
            'board_name' => $info['board_name'],
            'ros_version' => $info['ros_version'],
        ];
    }

    public function provision(MikrotikRouter $router): array
    {
        $this->log = [];
        $cfg = config('hotspot');
        $radius = ! empty($cfg['radius']['host']) && ! empty($cfg['radius']['secret']);
        $networks = $router->hotspotNetworks()->get();

        $info = $this->inspect($router);
        $this->checkPlan($router, $info, $networks);
        $ports = array_column($info['ports'], null, 'name');

        $wan = $router->wan_interface;
        $lanPorts = $router->portsWith('lan');
        $trunkPorts = $router->portsWith('trunk');
        $native = $router->mgmtNative();
        $vlan = fn (string $net) => $router->vlan($net);

        $this->step("Connected to {$info['identity']} ({$info['board_name']}, RouterOS {$info['ros_version']})");

        // Identity doubles as the RADIUS NAS-Identifier, so reports show the site name.
        $this->run((new Query('/system/identity/set'))->equal('name', $router->name));
        $facts = ['identity' => $router->name, 'board_name' => $info['board_name'], 'ros_version' => $info['ros_version']];
        $this->step("Router identity set to \"{$router->name}\"");

        /* ---------- WAN ---------- */

        if (! empty($ports[$wan]['bridge'])) {
            $id = $this->rows((new Query('/interface/bridge/port/print'))->where('interface', $wan))[0]['.id'] ?? null;
            if ($id) {
                $this->run((new Query('/interface/bridge/port/remove'))->equal('.id', $id));
            }
            $this->step("Took {$wan} out of bridge {$ports[$wan]['bridge']} to use it as WAN");
        }

        match ($router->wan_mode) {
            'dhcp' => $this->ensure('/ip/dhcp-client', ['interface' => $wan], [
                'add-default-route' => 'yes',
                'use-peer-dns' => 'no',
                'disabled' => 'no',
                'comment' => self::TAG.':wan',
            ]),
            'static' => (function () use ($router, $wan) {
                $this->ensure('/ip/address', ['comment' => self::TAG.':wan'], [
                    'address' => $router->wan_address,
                    'interface' => $wan,
                ]);
                $this->ensure('/ip/route', ['comment' => self::TAG.':default-route'], [
                    'dst-address' => '0.0.0.0/0',
                    'gateway' => $router->wan_gateway,
                ]);
            })(),
            default => null,
        };

        // With "keep", the internet may run over PPPoE on top of the WAN port.
        $uplink = $router->wan_mode === 'keep' ? ($this->pppoeOn($wan) ?? $wan) : $wan;
        $this->step(match ($router->wan_mode) {
            'dhcp' => "WAN {$wan}: DHCP client",
            'static' => "WAN {$wan}: {$router->wan_address}, gateway {$router->wan_gateway}",
            default => "WAN {$wan}: existing IP settings kept".($uplink !== $wan ? ", internet through {$uplink}" : ''),
        });

        /* ---------- Trunk bridge with VLAN filtering ---------- */

        // Created with filtering off; it is switched on once the VLAN table is complete.
        $this->ensure('/interface/bridge', ['name' => self::TRUNK], ['comment' => self::TAG.':trunk']);

        foreach ($trunkPorts as $port) {
            $this->ensure('/interface/bridge/port', ['interface' => $port], [
                'bridge' => self::TRUNK,
                'pvid' => $native ? (string) $vlan('mgmt')['id'] : '1',
                'frame-types' => $native ? 'admit-all' : 'admit-only-vlan-tagged',
                'ingress-filtering' => 'yes',
                'comment' => self::TAG.':trunk-port',
            ]);
        }
        foreach ($networks as $n) {
            foreach ($router->accessPortsFor($n) as $port) {
                $this->ensure('/interface/bridge/port', ['interface' => $port], [
                    'bridge' => self::TRUNK,
                    'pvid' => (string) $n->vlan_id,
                    'frame-types' => 'admit-only-untagged-and-priority-tagged',
                    'ingress-filtering' => 'yes',
                    'comment' => self::TAG.':hotspot-access-port',
                ]);
            }
        }

        // Bridge VLAN table. The bridge itself is tagged so the router can route each VLAN.
        // Every hotspot VLAN is tagged on every trunk port.
        $tagged = fn (array $list) => implode(',', [self::TRUNK, ...$list]);
        $table = [];
        foreach ($networks as $n) {
            $table[$n->key] = [$n->vlan_id, $n->interface, $tagged($trunkPorts), implode(',', $router->accessPortsFor($n))];
        }
        $table['mgmt'] = [$vlan('mgmt')['id'], $vlan('mgmt')['name'], $tagged($native ? [] : $trunkPorts), $native ? implode(',', $trunkPorts) : ''];
        $table['test'] = [$vlan('test')['id'], $vlan('test')['name'], $tagged($trunkPorts), ''];

        foreach ($table as $key => [$id, $iface, $taggedPorts, $untaggedPorts]) {
            $this->ensure('/interface/bridge/vlan', ['comment' => self::TAG.":vlan-{$key}"], [
                'bridge' => self::TRUNK,
                'vlan-ids' => (string) $id,
                'tagged' => $taggedPorts,
                'untagged' => $untaggedPorts,
            ]);
            $this->ensure('/interface/vlan', ['name' => $iface], [
                'interface' => self::TRUNK,
                'vlan-id' => (string) $id,
                'comment' => self::TAG.":vlan-{$key}",
            ]);
        }

        $this->ensure('/interface/bridge', ['name' => self::TRUNK], ['vlan-filtering' => 'yes']);
        $this->step(sprintf(
            '%s: trunk %s. Hotspot VLANs %s; management %d%s, test %d',
            self::TRUNK,
            $trunkPorts ? implode(', ', $trunkPorts) : 'none',
            $networks->map(function (HotspotNetwork $n) use ($router) {
                $untagged = $router->accessPortsFor($n);

                return "{$n->vlan_id} ({$n->name}".($untagged ? ', untagged on '.implode(', ', $untagged) : '').')';
            })->implode(', '),
            $vlan('mgmt')['id'], $native ? ' (untagged on trunks)' : '', $vlan('test')['id']
        ));

        /* ---------- Optional untagged LAN bridge ---------- */

        if ($lanPorts) {
            $this->ensure('/interface/bridge', ['name' => self::LAN_BRIDGE], ['comment' => self::TAG.':lan-bridge']);
            foreach ($lanPorts as $port) {
                $this->ensure('/interface/bridge/port', ['interface' => $port],
                    ['bridge' => self::LAN_BRIDGE, 'comment' => self::TAG.':lan-port']);
            }
            $this->step('LAN bridge '.self::LAN_BRIDGE.': '.implode(', ', $lanPorts));
        }

        /* ---------- Interface lists (keep the default firewall working) ---------- */

        $lists = array_column($this->rows(new Query('/interface/list/print')), 'name');
        if (in_array('LAN', $lists, true)) {
            $internal = [$vlan('mgmt')['name'], $vlan('test')['name'], ...($lanPorts ? [self::LAN_BRIDGE] : [])];
            foreach ($internal as $iface) {
                $this->ensure('/interface/list/member', ['list' => 'LAN', 'interface' => $iface],
                    ['comment' => self::TAG.':lan-list']);
            }
        }
        if (in_array('WAN', $lists, true)) {
            foreach (array_unique([$wan, $uplink]) as $iface) {
                $this->ensure('/interface/list/member', ['list' => 'WAN', 'interface' => $iface],
                    ['comment' => self::TAG.':wan-list']);
            }
        }

        /* ---------- Addressing and DHCP per network ---------- */

        $addressing = [];
        foreach ($networks as $n) {
            $addressing[$n->key] = [$n->name, $n->interface, $n->addressing(), $cfg['lease_time']];
        }
        $addressing['mgmt'] = ['Management', $vlan('mgmt')['name'], $router->network('mgmt'), $cfg['mgmt_lease_time']];
        $addressing['test'] = ['Test', $vlan('test')['name'], $router->network('test'), $cfg['test_lease_time']];
        if ($lanPorts) {
            $addressing['lan'] = ['LAN', self::LAN_BRIDGE, $router->network('lan'), $cfg['lan_lease_time']];
        }

        foreach ($addressing as $key => [$label, $iface, $n, $lease]) {
            [, $prefix] = explode('/', $n['subnet']);

            $this->ensure('/ip/address', ['comment' => self::TAG.":gateway-{$key}"], [
                'address' => "{$n['gateway']}/{$prefix}",
                'interface' => $iface,
            ]);
            $this->ensure('/ip/pool', ['name' => "pool-{$key}"], [
                'ranges' => "{$n['pool_start']}-{$n['pool_end']}",
            ]);
            $this->ensure('/ip/dhcp-server/network', ['comment' => self::TAG.":dhcp-{$key}"], [
                'address' => $n['subnet'],
                'gateway' => $n['gateway'],
                'dns-server' => $n['gateway'],
            ]);
            $this->ensure('/ip/dhcp-server', ['name' => "dhcp-{$key}"], [
                'interface' => $iface,
                'address-pool' => "pool-{$key}",
                'lease-time' => $lease,
                'disabled' => 'no',
            ]);
            $this->ensure('/ip/firewall/nat', ['comment' => self::TAG.":masquerade-{$key}"], [
                'chain' => 'srcnat', 'action' => 'masquerade',
                'src-address' => $n['subnet'], 'out-interface' => $uplink,
            ]);
            $this->step(sprintf('%s on %s: %s, gateway %s, DHCP %s-%s, lease %s',
                $label, $iface, $n['subnet'], $n['gateway'], $n['pool_start'], $n['pool_end'], $lease));
        }

        /* ---------- DNS and firewall ---------- */

        $this->run((new Query('/ip/dns/set'))
            ->equal('servers', $cfg['dns_servers'])
            ->equal('allow-remote-requests', 'yes'));

        // The router answers DNS for its own networks only, never from the internet
        // (open resolvers get abused for attacks).
        foreach (['udp', 'tcp'] as $protocol) {
            $this->ensure('/ip/firewall/filter', ['comment' => self::TAG.":dns-from-wan-{$protocol}"], [
                'chain' => 'input', 'in-interface' => $uplink, 'protocol' => $protocol,
                'dst-port' => '53', 'action' => 'drop',
            ]);
        }

        // Hotspot networks and test may only reach the internet: not each other, nothing internal,
        // and nothing internal may reach them. They cannot open Winbox/SSH/API on the router.
        $hotspotInterfaces = $networks->mapWithKeys(fn (HotspotNetwork $n) => [$n->key => $n->interface])->all();
        foreach ($hotspotInterfaces + ['test' => $vlan('test')['name']] as $key => $iface) {
            $this->ensure('/ip/firewall/filter', ['comment' => self::TAG.":isolate-{$key}-out"], [
                'chain' => 'forward', 'in-interface' => $iface, 'out-interface' => '!'.$uplink, 'action' => 'drop',
            ]);
            $this->ensure('/ip/firewall/filter', ['comment' => self::TAG.":isolate-{$key}-in"], [
                'chain' => 'forward', 'out-interface' => $iface, 'in-interface' => '!'.$uplink, 'action' => 'drop',
            ]);
            $this->ensure('/ip/firewall/filter', ['comment' => self::TAG.":protect-mgmt-{$key}"], [
                'chain' => 'input', 'in-interface' => $iface, 'protocol' => 'tcp',
                'dst-port' => self::MGMT_PORTS, 'action' => 'drop',
            ]);
        }
        $this->step("DNS {$cfg['dns_servers']}, not answered from the internet. Hotspot networks and test reach only the internet; management VLAN can manage the router");

        // FastTrack skips queues and accounting, so hotspot speed limits and RADIUS byte counts
        // would stop working. Hotspot traffic is accepted just above the FastTrack rule instead.
        if ($this->exemptFromFasttrack($hotspotInterfaces)) {
            $this->step('FastTrack skipped for hotspot traffic, so speed limits and accounting apply');
        }

        /* ---------- RADIUS ---------- */

        if ($radius) {
            $this->ensure('/radius', ['comment' => self::TAG.':radius'], [
                'service' => 'hotspot',
                'address' => $cfg['radius']['host'],
                'secret' => $cfg['radius']['secret'],
                'authentication-port' => (string) $cfg['radius']['auth_port'],
                'accounting-port' => (string) $cfg['radius']['acct_port'],
                'timeout' => $cfg['radius']['timeout'],
            ]);
            $this->run((new Query('/radius/incoming/set'))->equal('accept', 'yes'));
            $this->step("RADIUS client pointed at {$cfg['radius']['host']}");
        } else {
            $this->step('RADIUS_HOST not set: users must be created on this router locally');
        }

        /* ---------- One hotspot server per network ---------- */

        // Our login.html replaces the router's page for every network as soon as one network uses it.
        $ownLoginFile = $networks->contains(fn (HotspotNetwork $n) => $n->usesExternalLogin());

        $this->ensure('/ip/hotspot/user/profile', ['name' => 'default'], [
            'shared-users' => '1',
            'rate-limit' => $cfg['rate_limit'],
            'session-timeout' => $cfg['session_timeout'],
            'keepalive-timeout' => $cfg['keepalive_timeout'],
            'add-mac-cookie' => 'yes',
            'mac-cookie-timeout' => $cfg['mac_cookie_timeout'],
        ]);

        $dir = null;
        foreach ($networks as $n) {
            $this->ensure('/ip/hotspot/profile', ['name' => $n->profileName()], [
                'hotspot-address' => $n->gateway,
                'dns-name' => $cfg['dns_name'],
                // Our pages log users in with a plain username/password (PAP)
                'login-by' => $ownLoginFile ? 'http-chap,http-pap,mac-cookie' : 'http-chap,mac-cookie',
                'use-radius' => $radius ? 'yes' : 'no',
                'radius-accounting' => $radius ? 'yes' : 'no',
                'radius-interim-update' => $cfg['radius']['interim_update'],
                'nas-port-type' => 'wireless-802.11',
            ]);

            // Every network uses the first network's page folder: one login.html serves them all.
            if ($dir === null) {
                $profile = $this->rows((new Query('/ip/hotspot/profile/print'))->where('name', $n->profileName()))[0] ?? [];
                $dir = trim((string) ($profile['html-directory'] ?? ''), '/') ?: 'hotspot';
            }
            $this->ensure('/ip/hotspot/profile', ['name' => $n->profileName()], ['html-directory' => $dir]);

            $this->ensure('/ip/hotspot', ['name' => $n->serverName()], [
                'interface' => $n->interface,
                'profile' => $n->profileName(),
                'idle-timeout' => $cfg['idle_timeout'],
                'disabled' => 'no',
            ]);
            $this->step("Hotspot {$n->serverName()} for {$n->name} on {$n->interface}, {$cfg['rate_limit']} per user");
        }

        if ($ownLoginFile) {
            $this->setupExternalLogin($networks, $dir);
        } else {
            $this->step("Login page: the router's built-in page");
        }

        foreach ($cfg['walled_garden'] as $host) {
            $this->ensure('/ip/hotspot/walled-garden', ['comment' => self::TAG.':wg:'.$host], [
                'dst-host' => $host,
                'action' => 'allow',
            ]);
        }
        if ($cfg['walled_garden']) {
            $this->step('Walled garden: '.implode(', ', $cfg['walled_garden']));
        }

        $this->step('Done');

        return ['facts' => $facts, 'log' => $this->log];
    }

    public function log(): array
    {
        return $this->log;
    }

    /**
     * Refuses plans that reference missing ports or networks, or would cut
     * the dashboard's own connection to the router halfway through.
     *
     * @param  Collection<int, HotspotNetwork>  $networks
     */
    private function checkPlan(MikrotikRouter $router, array $info, Collection $networks): void
    {
        $roles = $router->port_roles ?? [];
        $ports = array_column($info['ports'], null, 'name');

        if ($networks->isEmpty()) {
            throw new RuntimeException('Add at least one hotspot network.');
        }
        $vlanIds = $networks->pluck('vlan_id')->map(fn ($id) => (int) $id)->all();

        foreach ($roles as $port => $role) {
            if ($role !== 'none' && ! isset($ports[$port])) {
                throw new RuntimeException(sprintf(
                    'Port "%s" does not exist on this router. It has: %s. Use "Read ports from router" to get the right list.',
                    $port, implode(', ', array_keys($ports))
                ));
            }
            if (str_starts_with($role, 'access:') && ! in_array((int) substr($role, 7), $vlanIds, true)) {
                throw new RuntimeException("Port \"{$port}\" is set to hotspot VLAN ".substr($role, 7).', but no hotspot network uses that VLAN.');
            }
        }
        if (count($router->portsWith('wan')) !== 1) {
            throw new RuntimeException('Choose exactly one WAN port.');
        }
        if (! $router->portsWith('trunk') && ! $router->accessPorts()) {
            throw new RuntimeException('Choose at least one trunk or hotspot port.');
        }

        $api = $info['api_interface'];
        if ($api === null) {
            return; // connected through NAT or a hostname we can't match: nothing to check
        }

        // Ports whose bridge membership changes during provisioning
        $moved = [];
        foreach ($roles as $port => $role) {
            $current = $ports[$port]['bridge'] ?? null;
            $target = match (true) {
                $role === 'lan' => self::LAN_BRIDGE,
                $role === 'trunk', MikrotikRouter::isAccessRole($role) => self::TRUNK,
                default => null,
            };
            if (($target && $current !== $target) || ($role === 'wan' && $current)) {
                $moved[] = $port;
            }
        }

        if (in_array($api, $moved, true)) {
            throw new RuntimeException(
                "The dashboard connects to this router through {$api}, and that port would be moved into a bridge, ".
                "cutting the connection. Set {$api} as WAN or Not used, or connect to the router through its WAN address."
            );
        }

        $apiBridgePorts = array_keys(array_filter($ports, fn ($p) => ($p['bridge'] ?? null) === $api));
        if ($apiBridgePorts && ! array_diff($apiBridgePorts, $moved)) {
            throw new RuntimeException(
                "The dashboard connects through {$api} ({$router->host}), and every port of that bridge would be moved ".
                '('.implode(', ', $apiBridgePorts).'). Leave the port the server is plugged into as Not used, '.
                'or connect to the router through its WAN address.'
            );
        }

        if ($router->wan_mode === 'static' && $api === $router->wan_interface
            && strtok((string) $router->wan_address, '/') !== $this->apiIp($router)) {
            throw new RuntimeException(
                "The dashboard connects through the WAN port, and the new static address would replace {$router->host}. ".
                'Choose "Keep current settings" for WAN, or use the address the dashboard connects to.'
            );
        }
    }

    /** Name of a PPPoE client running on the WAN port, if any. */
    private function pppoeOn(string $wan): ?string
    {
        try {
            return $this->rows((new Query('/interface/pppoe-client/print'))->where('interface', $wan))[0]['name'] ?? null;
        } catch (Throwable) {
            return null; // PPP package missing
        }
    }

    /**
     * Adds "accept established/related" for each hotspot interface just above the
     * first FastTrack rule, so hotspot connections are never fast-tracked.
     * Returns false when the router has no FastTrack rule.
     */
    private function exemptFromFasttrack(array $interfaces): bool
    {
        $fasttrack = null;
        foreach ($this->rows(new Query('/ip/firewall/filter/print')) as $row) {
            if (($row['action'] ?? '') === 'fasttrack-connection' && ($row['dynamic'] ?? 'false') !== 'true') {
                $fasttrack = $row['.id'];
                break;
            }
        }
        if ($fasttrack === null) {
            return false;
        }

        foreach ($interfaces as $key => $iface) {
            foreach (['in-interface' => 'from', 'out-interface' => 'to'] as $side => $label) {
                $this->ensureBefore('/ip/firewall/filter', self::TAG.":no-fasttrack-{$key}-{$label}", [
                    'chain' => 'forward',
                    'connection-state' => 'established,related',
                    $side => $iface,
                    'action' => 'accept',
                ], $fasttrack);
            }
        }

        return true;
    }

    /** Like ensure(), found by comment, and kept above the rule $beforeId. */
    private function ensureBefore(string $menu, string $comment, array $attrs, string $beforeId): void
    {
        $rows = $this->rows(new Query("{$menu}/print"));
        $position = array_search($beforeId, array_column($rows, '.id'), true);

        foreach ($rows as $i => $row) {
            if (($row['comment'] ?? null) !== $comment) {
                continue;
            }
            $set = (new Query("{$menu}/set"))->equal('.id', $row['.id']);
            foreach ($attrs as $key => $value) {
                $set->equal($key, $value);
            }
            $this->run($set, $menu);

            if ($position !== false && $i > $position) {
                $this->run((new Query("{$menu}/move"))->equal('numbers', $row['.id'])->equal('destination', $beforeId), $menu);
            }

            return;
        }

        $add = (new Query("{$menu}/add"))->equal('comment', $comment)->equal('place-before', $beforeId);
        foreach ($attrs as $key => $value) {
            $add->equal($key, $value);
        }
        $this->run($add, $menu);
    }

    private function port(string $name, array $members, ?string $api, ?string $type = null): array
    {
        $type ??= match (true) {
            str_starts_with($name, 'sfp'), str_starts_with($name, 'qsfp') => 'sfp',
            str_starts_with($name, 'combo') => 'combo',
            default => 'ethernet',
        };
        $bridge = $members[$name] ?? null;

        return [
            'name' => $name,
            'type' => $type,
            'bridge' => $bridge,
            'api' => $api !== null && ($name === $api || $bridge === $api),
        ];
    }

    private function apiIp(MikrotikRouter $router): string
    {
        return filter_var($router->host, FILTER_VALIDATE_IP) ? $router->host : gethostbyname($router->host);
    }

    /** Find an object by $match; update it with $attrs if it exists, add it otherwise. */
    private function ensure(string $menu, array $match, array $attrs): void
    {
        $find = new Query("{$menu}/print");
        foreach ($match as $key => $value) {
            $find->where($key, $value);
        }
        $existing = $this->rows($find)[0]['.id'] ?? null;

        if ($existing) {
            $query = (new Query("{$menu}/set"))->equal('.id', $existing);
            foreach ($attrs as $key => $value) {
                $query->equal($key, $value);
            }
        } else {
            $query = new Query("{$menu}/add");
            foreach (array_merge($match, $attrs) as $key => $value) {
                $query->equal($key, $value);
            }
        }

        $this->run($query, $menu);
    }

    /** Rows from a print command, without the trailing status entry. */
    private function rows(Query $query): array
    {
        return array_values(array_filter(
            $this->run($query),
            fn ($row, $key) => is_int($key) && is_array($row),
            ARRAY_FILTER_USE_BOTH
        ));
    }

    private function run(Query $query, string $label = ''): array
    {
        $response = $this->client->query($query)->read();

        if (isset($response['after']['message'])) {
            throw new RuntimeException(trim($label.' '.$response['after']['message']));
        }

        return $response;
    }

    private function step(string $message): void
    {
        $this->log[] = ['at' => now()->toIso8601String(), 'message' => $message];
    }
}
