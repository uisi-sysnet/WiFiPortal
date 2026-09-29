<?php

namespace App\Services\Mikrotik;

use App\Models\MikrotikRouter;
use RouterOS\Client;
use RouterOS\Query;
use RuntimeException;
use Throwable;

/**
 * Pushes the public-WiFi setup to a MikroTik through the RouterOS API
 * (package: evilfreelancer/routeros-api-php):
 *
 *   WAN port       -> internet (keep / DHCP client / static)
 *   Trunk ports    -> bridge-trunk, tagged: hotspot, management and test VLANs
 *   Hotspot ports  -> bridge-trunk, untagged in the hotspot VLAN (plain APs, wlan)
 *   LAN ports      -> bridge-lan, untagged office network (optional)
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

    private const LABELS = ['hotspot' => 'Hotspot', 'mgmt' => 'Management', 'test' => 'Test', 'lan' => 'LAN'];
    private const MGMT_PORTS = '21,22,23,8291,8728,8729';

    private ?Client $client = null;
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
     * Replaces the router's login.html with a small page that forwards users
     * (with their MAC, IP and the router's login link) to the splash page or
     * custom URL, and lets that host through before login.
     */
    private function setupExternalLogin(MikrotikRouter $router): void
    {
        $target = (string) $router->loginTarget();
        $host = parse_url($target, PHP_URL_HOST);

        if ($host) {
            $this->ensure('/ip/hotspot/walled-garden', ['comment' => self::TAG.':login-host'], [
                'dst-host' => $host,
                'action' => 'allow',
            ]);
            $this->ensure('/ip/hotspot/walled-garden/ip', ['comment' => self::TAG.':login-host-ip'], [
                ...(filter_var($host, FILTER_VALIDATE_IP) ? ['dst-address' => $host] : ['dst-host' => $host]),
                'action' => 'accept',
            ]);
        }

        $profile = $this->rows((new Query('/ip/hotspot/profile/print'))->where('name', self::SERVER_PROFILE))[0] ?? [];
        $dir = trim((string) ($profile['html-directory'] ?? ''), '/');
        if ($dir === '') {
            $dir = 'hotspot';
            $this->ensure('/ip/hotspot/profile', ['name' => self::SERVER_PROFILE], ['html-directory' => $dir]);
        }

        // The router downloads its login.html from this app (works on RouterOS 6 and 7).
        $fetch = (new Query('/tool/fetch'))
            ->equal('url', $router->loginFileUrl())
            ->equal('dst-path', "{$dir}/login.html");
        if (str_starts_with($router->loginFileUrl(), 'https://')) {
            $fetch->equal('check-certificate', 'no');
        }
        $this->run($fetch, 'Could not download login.html from '.$router->loginFileUrl().':');

        $this->step("Login page: {$dir}/login.html forwards users to {$target}; {$host} reachable before login");
    }

    private function connect(MikrotikRouter $router): void
    {
        $this->client = new Client([
            'host' => $router->host,
            'user' => $router->username,
            'pass' => $router->password,
            'port' => $router->api_port,
            'ssl' => $router->use_ssl,
            'timeout' => config('hotspot.api.timeout'),
            'attempts' => 1,
        ]);
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

    /** Inspect + check the chosen port roles are safe. Returns facts to store. */
    public function probe(MikrotikRouter $router): array
    {
        $info = $this->inspect($router);
        $this->checkPlan($router, $info);

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

        $info = $this->inspect($router);
        $this->checkPlan($router, $info);
        $ports = array_column($info['ports'], null, 'name');

        $wan = $router->wan_interface;
        $lanPorts = $router->portsWith('lan');
        $trunkPorts = $router->portsWith('trunk');
        $accessPorts = $router->portsWith('access');
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
        $this->step(match ($router->wan_mode) {
            'dhcp' => "WAN {$wan}: DHCP client",
            'static' => "WAN {$wan}: {$router->wan_address}, gateway {$router->wan_gateway}",
            default => "WAN {$wan}: existing IP settings kept",
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
        foreach ($accessPorts as $port) {
            $this->ensure('/interface/bridge/port', ['interface' => $port], [
                'bridge' => self::TRUNK,
                'pvid' => (string) $vlan('hotspot')['id'],
                'frame-types' => 'admit-only-untagged-and-priority-tagged',
                'ingress-filtering' => 'yes',
                'comment' => self::TAG.':hotspot-access-port',
            ]);
        }

        // Bridge VLAN table. The bridge itself is tagged so the router can route each VLAN.
        $tagged = fn (array $list) => implode(',', [self::TRUNK, ...$list]);
        $table = [
            'hotspot' => [$tagged($trunkPorts), implode(',', $accessPorts)],
            'mgmt' => [$tagged($native ? [] : $trunkPorts), $native ? implode(',', $trunkPorts) : ''],
            'test' => [$tagged($trunkPorts), ''],
        ];
        foreach ($table as $net => [$taggedPorts, $untaggedPorts]) {
            $this->ensure('/interface/bridge/vlan', ['comment' => self::TAG.":vlan-{$net}"], [
                'bridge' => self::TRUNK,
                'vlan-ids' => (string) $vlan($net)['id'],
                'tagged' => $taggedPorts,
                'untagged' => $untaggedPorts,
            ]);
            $this->ensure('/interface/vlan', ['name' => $vlan($net)['name']], [
                'interface' => self::TRUNK,
                'vlan-id' => (string) $vlan($net)['id'],
                'comment' => self::TAG.":vlan-{$net}",
            ]);
        }

        $this->ensure('/interface/bridge', ['name' => self::TRUNK], ['vlan-filtering' => 'yes']);
        $this->step(sprintf(
            '%s: trunk %s, hotspot untagged %s. VLANs hotspot %d, management %d%s, test %d',
            self::TRUNK,
            $trunkPorts ? implode(', ', $trunkPorts) : 'none',
            $accessPorts ? implode(', ', $accessPorts) : 'none',
            $vlan('hotspot')['id'], $vlan('mgmt')['id'], $native ? ' (untagged on trunks)' : '', $vlan('test')['id']
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
            $this->ensure('/interface/list/member', ['list' => 'WAN', 'interface' => $wan],
                ['comment' => self::TAG.':wan-list']);
        }

        /* ---------- Addressing and DHCP per network ---------- */

        $networks = [
            'hotspot' => [$vlan('hotspot')['name'], $cfg['lease_time']],
            'mgmt' => [$vlan('mgmt')['name'], $cfg['mgmt_lease_time']],
            'test' => [$vlan('test')['name'], $cfg['test_lease_time']],
        ];
        if ($lanPorts) {
            $networks['lan'] = [self::LAN_BRIDGE, $cfg['lan_lease_time']];
        }

        foreach ($networks as $net => [$iface, $lease]) {
            $n = $router->network($net);
            [, $prefix] = explode('/', $n['subnet']);

            $this->ensure('/ip/address', ['comment' => self::TAG.":gateway-{$net}"], [
                'address' => "{$n['gateway']}/{$prefix}",
                'interface' => $iface,
            ]);
            $this->ensure('/ip/pool', ['name' => "pool-{$net}"], [
                'ranges' => "{$n['pool_start']}-{$n['pool_end']}",
            ]);
            $this->ensure('/ip/dhcp-server/network', ['comment' => self::TAG.":dhcp-{$net}"], [
                'address' => $n['subnet'],
                'gateway' => $n['gateway'],
                'dns-server' => $n['gateway'],
            ]);
            $this->ensure('/ip/dhcp-server', ['name' => "dhcp-{$net}"], [
                'interface' => $iface,
                'address-pool' => "pool-{$net}",
                'lease-time' => $lease,
                'disabled' => 'no',
            ]);
            $this->ensure('/ip/firewall/nat', ['comment' => self::TAG.":masquerade-{$net}"], [
                'chain' => 'srcnat', 'action' => 'masquerade',
                'src-address' => $n['subnet'], 'out-interface' => $wan,
            ]);
            $this->step(sprintf('%s on %s: %s, gateway %s, DHCP %s-%s, lease %s',
                self::LABELS[$net], $iface, $n['subnet'], $n['gateway'], $n['pool_start'], $n['pool_end'], $lease));
        }

        /* ---------- DNS and firewall ---------- */

        $this->run((new Query('/ip/dns/set'))
            ->equal('servers', $cfg['dns_servers'])
            ->equal('allow-remote-requests', 'yes'));

        // Hotspot and test VLANs may only reach the internet: nothing internal, and
        // nothing internal may reach them. They cannot open Winbox/SSH/API on the router.
        foreach (['hotspot', 'test'] as $net) {
            $iface = $vlan($net)['name'];
            $this->ensure('/ip/firewall/filter', ['comment' => self::TAG.":isolate-{$net}-out"], [
                'chain' => 'forward', 'in-interface' => $iface, 'out-interface' => '!'.$wan, 'action' => 'drop',
            ]);
            $this->ensure('/ip/firewall/filter', ['comment' => self::TAG.":isolate-{$net}-in"], [
                'chain' => 'forward', 'out-interface' => $iface, 'in-interface' => '!'.$wan, 'action' => 'drop',
            ]);
            $this->ensure('/ip/firewall/filter', ['comment' => self::TAG.":protect-mgmt-{$net}"], [
                'chain' => 'input', 'in-interface' => $iface, 'protocol' => 'tcp',
                'dst-port' => self::MGMT_PORTS, 'action' => 'drop',
            ]);
        }
        $this->step("DNS {$cfg['dns_servers']}. Hotspot and test VLANs reach only the internet; management VLAN can manage the router");

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

        /* ---------- Hotspot on the hotspot VLAN ---------- */

        $this->ensure('/ip/hotspot/profile', ['name' => self::SERVER_PROFILE], [
            'hotspot-address' => $router->gateway,
            'dns-name' => $cfg['dns_name'],
            // External pages log users in with a plain username/password (PAP)
            'login-by' => $router->usesExternalLogin() ? 'http-chap,http-pap,mac-cookie' : 'http-chap,mac-cookie',
            'use-radius' => $radius ? 'yes' : 'no',
            'radius-accounting' => $radius ? 'yes' : 'no',
            'radius-interim-update' => $cfg['radius']['interim_update'],
            'nas-port-type' => 'wireless-802.11',
        ]);
        $this->ensure('/ip/hotspot/user/profile', ['name' => 'default'], [
            'shared-users' => '1',
            'rate-limit' => $cfg['rate_limit'],
            'session-timeout' => $cfg['session_timeout'],
            'keepalive-timeout' => $cfg['keepalive_timeout'],
            'add-mac-cookie' => 'yes',
            'mac-cookie-timeout' => $cfg['mac_cookie_timeout'],
        ]);
        $this->ensure('/ip/hotspot', ['name' => self::SERVER], [
            'interface' => $vlan('hotspot')['name'],
            'profile' => self::SERVER_PROFILE,
            'idle-timeout' => $cfg['idle_timeout'],
            'disabled' => 'no',
        ]);
        $this->step('Hotspot '.self::SERVER.' on '.$vlan('hotspot')['name']." at http://{$cfg['dns_name']}, {$cfg['rate_limit']} per user");

        if ($router->usesExternalLogin()) {
            $this->setupExternalLogin($router);
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
     * Refuses plans that reference missing ports or would cut the dashboard's
     * own connection to the router halfway through.
     */
    private function checkPlan(MikrotikRouter $router, array $info): void
    {
        $roles = $router->port_roles ?? [];
        $ports = array_column($info['ports'], null, 'name');

        foreach ($roles as $port => $role) {
            if ($role !== 'none' && ! isset($ports[$port])) {
                throw new RuntimeException(sprintf(
                    'Port "%s" does not exist on this router. It has: %s. Use "Read ports from router" to get the right list.',
                    $port, implode(', ', array_keys($ports))
                ));
            }
        }
        if (count($router->portsWith('wan')) !== 1) {
            throw new RuntimeException('Choose exactly one WAN port.');
        }
        if (! $router->portsWith('trunk') && ! $router->portsWith('access')) {
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
            $target = match ($role) { 'lan' => self::LAN_BRIDGE, 'trunk', 'access' => self::TRUNK, default => null };
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