<?php

namespace App\Services\Mikrotik;

use App\Models\MikrotikRouter;
use RouterOS\Client;
use RouterOS\Query;
use RuntimeException;

/**
 * Pushes the standard public-WiFi hotspot configuration to a MikroTik
 * through the RouterOS API (package: evilfreelancer/routeros-api-php).
 *
 * Every step is idempotent: objects are found by name or by a
 * "publicwifi:*" comment and updated in place, so running it again
 * repairs drift instead of creating duplicates.
 */
class HotspotProvisioner
{
    public const BRIDGE = 'bridge-hotspot';
    public const POOL = 'pool-hotspot';
    public const DHCP = 'dhcp-hotspot';
    public const SERVER_PROFILE = 'hsprof-publicwifi';
    public const SERVER = 'hotspot-publicwifi';
    public const TAG = 'publicwifi';

    private ?Client $client = null;
    private array $log = [];

    /**
     * Connects, reads basic facts and checks the chosen interfaces exist.
     * Works on an unsaved model, so the add form can fail fast.
     */
    public function probe(MikrotikRouter $router): array
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

        $resource = $this->rows(new Query('/system/resource/print'))[0] ?? [];
        $identity = $this->rows(new Query('/system/identity/print'))[0]['name'] ?? null;
        $interfaces = array_column($this->rows(new Query('/interface/print')), 'name');

        if ($router->wan_interface === $router->hotspot_interface) {
            throw new RuntimeException('The WAN and hotspot interfaces must be different.');
        }
        foreach ([$router->wan_interface, $router->hotspot_interface] as $iface) {
            if (! in_array($iface, $interfaces, true)) {
                throw new RuntimeException(sprintf(
                    'Interface "%s" was not found on the router. Available: %s',
                    $iface, implode(', ', $interfaces)
                ));
            }
        }

        return [
            'identity' => $identity,
            'board_name' => $resource['board-name'] ?? null,
            'ros_version' => $resource['version'] ?? null,
        ];
    }

    public function provision(MikrotikRouter $router): array
    {
        $this->log = [];
        $cfg = config('hotspot');
        $radius = ! empty($cfg['radius']['host']) && ! empty($cfg['radius']['secret']);
        [, $prefix] = explode('/', $router->subnet);

        $facts = $this->probe($router);
        $this->step("Connected to {$facts['identity']} ({$facts['board_name']}, RouterOS {$facts['ros_version']})");

        // Identity doubles as the RADIUS NAS-Identifier, so reports show the site name.
        $this->run((new Query('/system/identity/set'))->equal('name', $router->name));
        $facts['identity'] = $router->name;
        $this->step("Router identity set to \"{$router->name}\"");

        // 1. Bridge for the hotspot side (lets you add more APs/ports later)
        $this->ensure('/interface/bridge', ['name' => self::BRIDGE], ['comment' => self::TAG.':bridge']);
        $this->ensure('/interface/bridge/port',
            ['interface' => $router->hotspot_interface],
            ['bridge' => self::BRIDGE, 'comment' => self::TAG.':port']);
        $this->step("Bridge ".self::BRIDGE." created with {$router->hotspot_interface}");

        // 2. Addressing from the allocated block
        $this->ensure('/ip/address', ['comment' => self::TAG.':gateway'], [
            'address' => "{$router->gateway}/{$prefix}",
            'interface' => self::BRIDGE,
        ]);
        $this->ensure('/ip/pool', ['name' => self::POOL], [
            'ranges' => "{$router->pool_start}-{$router->pool_end}",
        ]);
        $this->step("Gateway {$router->gateway}/{$prefix}, pool {$router->pool_start}-{$router->pool_end}");

        // 3. DHCP with short leases for high client churn
        $this->ensure('/ip/dhcp-server/network', ['comment' => self::TAG.':dhcp'], [
            'address' => $router->subnet,
            'gateway' => $router->gateway,
            'dns-server' => $router->gateway,
        ]);
        $this->ensure('/ip/dhcp-server', ['name' => self::DHCP], [
            'interface' => self::BRIDGE,
            'address-pool' => self::POOL,
            'lease-time' => $cfg['lease_time'],
            'disabled' => 'no',
        ]);
        $this->step("DHCP server ".self::DHCP." enabled, lease {$cfg['lease_time']}");

        // 4. DNS cache on the router, NAT out the WAN
        $this->run((new Query('/ip/dns/set'))
            ->equal('servers', $cfg['dns_servers'])
            ->equal('allow-remote-requests', 'yes'));
        $this->ensure('/ip/firewall/nat', ['comment' => self::TAG.':masquerade'], [
            'chain' => 'srcnat',
            'action' => 'masquerade',
            'src-address' => $router->subnet,
            'out-interface' => $router->wan_interface,
        ]);
        $this->step("DNS ({$cfg['dns_servers']}) and NAT via {$router->wan_interface}");

        // 5. Keep hotspot users away from the router's management services
        $this->ensure('/ip/firewall/filter', ['comment' => self::TAG.':protect-mgmt'], [
            'chain' => 'input',
            'in-interface' => self::BRIDGE,
            'protocol' => 'tcp',
            'dst-port' => '21,22,23,8291,8728,8729',
            'action' => 'drop',
        ]);
        $this->step('Management ports blocked from the hotspot side');

        // 6. RADIUS: one central user database for every gateway
        if ($radius) {
            $this->ensure('/radius', ['comment' => self::TAG.':radius'], [
                'service' => 'hotspot',
                'address' => $cfg['radius']['host'],
                'secret' => $cfg['radius']['secret'],
                'authentication-port' => (string) $cfg['radius']['auth_port'],
                'accounting-port' => (string) $cfg['radius']['acct_port'],
                'timeout' => $cfg['radius']['timeout'],
            ]);
            // Allows the dashboard/RADIUS to disconnect users (CoA / Disconnect-Request)
            $this->run((new Query('/radius/incoming/set'))->equal('accept', 'yes'));
            $this->step("RADIUS client pointed at {$cfg['radius']['host']}");
        } else {
            $this->step('RADIUS_HOST not set: users must be created on this router locally');
        }

        // 7. Hotspot server profile
        $this->ensure('/ip/hotspot/profile', ['name' => self::SERVER_PROFILE], [
            'hotspot-address' => $router->gateway,
            'dns-name' => $cfg['dns_name'],
            'login-by' => 'http-chap,mac-cookie',
            'use-radius' => $radius ? 'yes' : 'no',
            'radius-accounting' => $radius ? 'yes' : 'no',
            'radius-interim-update' => $cfg['radius']['interim_update'],
            'nas-port-type' => 'wireless-802.11',
        ]);

        // 8. Default user profile (RADIUS users without a group land here)
        $this->ensure('/ip/hotspot/user/profile', ['name' => 'default'], [
            'shared-users' => '1',
            'rate-limit' => $cfg['rate_limit'],
            'session-timeout' => $cfg['session_timeout'],
            'keepalive-timeout' => $cfg['keepalive_timeout'],
            'add-mac-cookie' => 'yes',
            'mac-cookie-timeout' => $cfg['mac_cookie_timeout'],
        ]);

        // 9. Hotspot server on the bridge
        $this->ensure('/ip/hotspot', ['name' => self::SERVER], [
            'interface' => self::BRIDGE,
            'profile' => self::SERVER_PROFILE,
            'idle-timeout' => $cfg['idle_timeout'],
            'disabled' => 'no',
        ]);
        $this->step("Hotspot ".self::SERVER." running at http://{$cfg['dns_name']}, {$cfg['rate_limit']} per user");

        // 10. Walled garden (pages reachable before login)
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
     * Find an object by $match; update it with $attrs if it exists, add it otherwise.
     */
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
