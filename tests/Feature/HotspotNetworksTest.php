<?php

namespace Tests\Feature;

use App\Models\HotspotGuest;
use App\Models\HotspotNetwork;
use App\Models\MikrotikRouter;
use App\Models\SplashPage;
use App\Services\Mikrotik\HotspotProvisioner;
use App\Services\Mikrotik\SubnetAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\FakeRouterOs;
use Tests\TestCase;

class HotspotNetworksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hotspot.portal_url' => 'http://portal.test',
            // Credentials go to radcheck, so no router is contacted during login.
            'hotspot.radius.host' => '127.0.0.1',
            'hotspot.radius.secret' => 'secret',
        ]);
    }

    /* ---------- Provisioning ---------- */

    public function test_provisioning_builds_one_vlan_subnet_and_hotspot_per_network(): void
    {
        $router = $this->router([
            ['name' => 'Public WiFi', 'vlan_id' => 10],
            ['name' => 'School', 'vlan_id' => 11],
            ['name' => 'Staff', 'vlan_id' => 12, 'login_mode' => 'builtin'],
        ], ports: ['ether1' => 'wan', 'ether2' => 'trunk', 'ether3' => 'access:10', 'ether4' => 'access:11', 'ether5' => 'none']);
        $fake = $this->fakeRouter();

        $this->provisioner($fake)->provision($router);

        // VLAN table: every hotspot VLAN tagged on the trunk, untagged on its own ports
        $this->assertSame('bridge-trunk,ether2', $fake->find('/interface/bridge/vlan', ['vlan-ids' => '11'])[0]['tagged']);
        $this->assertSame('ether3', $fake->find('/interface/bridge/vlan', ['vlan-ids' => '10'])[0]['untagged']);
        $this->assertSame('ether4', $fake->find('/interface/bridge/vlan', ['vlan-ids' => '11'])[0]['untagged']);
        $this->assertSame('', $fake->find('/interface/bridge/vlan', ['vlan-ids' => '12'])[0]['untagged']);
        $this->assertSame('11', $fake->find('/interface/bridge/port', ['interface' => 'ether4'])[0]['pvid']);
        $this->assertSame('yes', $fake->find('/interface/bridge', ['name' => 'bridge-trunk'])[0]['vlan-filtering']);

        // Own subnet, DHCP server and hotspot server per network
        $networks = $router->hotspotNetworks()->get();
        $this->assertCount(3, $networks->pluck('subnet')->unique());
        foreach ($networks as $n) {
            $this->assertNotEmpty($fake->find('/ip/address', ['interface' => $n->interface, 'address' => $n->gateway.'/20']));
            $this->assertNotEmpty($fake->find('/ip/dhcp-server', ['name' => "dhcp-{$n->key}", 'interface' => $n->interface]));
            $server = $fake->find('/ip/hotspot', ['name' => $n->serverName()])[0];
            $this->assertSame($n->interface, $server['interface']);
            $profile = $fake->find('/ip/hotspot/profile', ['name' => $server['profile']])[0];
            $this->assertSame($n->gateway, $profile['hotspot-address']);
            $this->assertSame('hotspot', $profile['html-directory']); // one login.html for all
            $this->assertStringContainsString('http-pap', $profile['login-by']);
            // Isolated from each other and from internal networks
            $this->assertSame('!ether1', $fake->find('/ip/firewall/filter', ['comment' => "publicwifi:isolate-{$n->key}-out"])[0]['out-interface']);
        }
        $this->assertSame(['hotspot-publicwifi', 'hotspot-publicwifi-11', 'hotspot-publicwifi-12'], array_column($fake->menus['/ip/hotspot'], 'name'));

        // login.html downloaded once, from this app
        $fetch = $fake->sent('/tool/fetch');
        $this->assertCount(1, $fetch);
        $this->assertSame('hotspot/login.html', $fetch[0]['dst-path']);
        $this->assertSame($networks[0]->loginFileUrl(), $fetch[0]['url']);
        $this->assertSame('portal.test', $fake->find('/ip/hotspot/walled-garden', ['comment' => 'publicwifi:login-host'])[0]['dst-host']);

        // Hotspot traffic is accepted above the FastTrack rule
        $filter = array_column($fake->menus['/ip/firewall/filter'], 'comment');
        $fasttrack = array_search('defconf: fasttrack', $filter, true);
        foreach ($networks as $n) {
            $this->assertLessThan($fasttrack, array_search("publicwifi:no-fasttrack-{$n->key}-from", $filter, true));
            $this->assertLessThan($fasttrack, array_search("publicwifi:no-fasttrack-{$n->key}-to", $filter, true));
        }
        // DNS is not answered from the internet
        $this->assertNotEmpty($fake->find('/ip/firewall/filter', ['comment' => 'publicwifi:dns-from-wan-udp', 'in-interface' => 'ether1']));
    }

    public function test_reapplying_changes_nothing(): void
    {
        $router = $this->router([['name' => 'Public WiFi', 'vlan_id' => 10], ['name' => 'School', 'vlan_id' => 11]]);
        $fake = $this->fakeRouter();

        $this->provisioner($fake)->provision($router);
        $first = $fake->menus;
        $this->provisioner($fake)->provision($router);

        $this->assertEquals($first, $fake->menus);
    }

    public function test_user_limit_and_new_address_are_applied_in_place(): void
    {
        $router = $this->router([['name' => 'Public WiFi', 'vlan_id' => 10]]);
        $fake = $this->fakeRouter();
        $this->provisioner($fake)->provision($router);

        $n = $router->hotspotNetworks->first();
        $n->update(app(SubnetAllocator::class)->hotspotAddressing('192.168.48.0/22', 300));
        $this->provisioner($fake)->provision($router->fresh());

        $this->assertSame([['192.168.48.2-192.168.49.45']], [array_column($fake->find('/ip/pool', ['name' => 'pool-hotspot']), 'ranges')]);
        $this->assertCount(1, $fake->find('/ip/address', ['comment' => 'publicwifi:gateway-hotspot']));
        $this->assertSame('192.168.48.1/22', $fake->find('/ip/address', ['comment' => 'publicwifi:gateway-hotspot'])[0]['address']);
        $this->assertSame('192.168.48.0/22', $fake->find('/ip/dhcp-server/network', ['comment' => 'publicwifi:dhcp-hotspot'])[0]['address']);
        $this->assertSame('192.168.48.0/22', $fake->find('/ip/firewall/nat', ['comment' => 'publicwifi:masquerade-hotspot'])[0]['src-address']);
        $this->assertSame('192.168.48.1', $fake->find('/ip/hotspot/profile', ['name' => 'hsprof-publicwifi'])[0]['hotspot-address']);
    }

    public function test_pppoe_wan_is_used_for_nat_and_isolation(): void
    {
        $router = $this->router([['name' => 'Public WiFi', 'vlan_id' => 10]]);
        $fake = $this->fakeRouter(['/interface/pppoe-client' => [['name' => 'pppoe-out1', 'interface' => 'ether1']]]);

        $this->provisioner($fake)->provision($router);

        $this->assertSame('pppoe-out1', $fake->find('/ip/firewall/nat', ['comment' => 'publicwifi:masquerade-hotspot'])[0]['out-interface']);
        $this->assertSame('!pppoe-out1', $fake->find('/ip/firewall/filter', ['comment' => 'publicwifi:isolate-hotspot-out'])[0]['out-interface']);
    }

    public function test_a_port_on_an_unknown_hotspot_vlan_is_refused(): void
    {
        $router = $this->router([['name' => 'Public WiFi', 'vlan_id' => 10]],
            ports: ['ether1' => 'wan', 'ether2' => 'trunk', 'ether3' => 'access:13']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no hotspot network uses that VLAN');
        $this->provisioner($this->fakeRouter())->provision($router);
    }

    /* ---------- The three ways to share pages ---------- */

    public function test_all_networks_can_share_one_portal_and_ad(): void
    {
        $main = $this->design('Main', 'LOGIN-MAIN', 'AD-MAIN');
        $router = $this->router([
            ['name' => 'Public WiFi', 'vlan_id' => 10, 'login_page_id' => $main->id, 'ad_page_id' => $main->id],
            ['name' => 'School', 'vlan_id' => 11, 'login_page_id' => $main->id, 'ad_page_id' => $main->id],
        ]);

        foreach ($router->hotspotNetworks as $n) {
            $this->assertPages($n, 'LOGIN-MAIN', 'AD-MAIN');
        }
    }

    public function test_each_network_can_have_its_own_portal_and_ad(): void
    {
        $a = $this->design('City', 'LOGIN-CITY', 'AD-CITY');
        $b = $this->design('School', 'LOGIN-SCHOOL', 'AD-SCHOOL');
        [$city, $school] = $this->router([
            ['name' => 'City WiFi', 'vlan_id' => 10, 'login_page_id' => $a->id, 'ad_page_id' => $a->id],
            ['name' => 'School WiFi', 'vlan_id' => 11, 'login_page_id' => $b->id, 'ad_page_id' => $b->id],
        ])->hotspotNetworks;

        $this->assertPages($city, 'LOGIN-CITY', 'AD-CITY');
        $this->assertPages($school, 'LOGIN-SCHOOL', 'AD-SCHOOL');
    }

    public function test_networks_can_share_the_portal_with_different_ads(): void
    {
        $portal = $this->design('Portal', 'LOGIN-SHARED', 'AD-UNUSED');
        $adA = $this->design('Ad plaza', 'LOGIN-UNUSED', 'AD-PLAZA');
        $adB = $this->design('Ad market', 'LOGIN-UNUSED', 'AD-MARKET');
        [$plaza, $market] = $this->router([
            ['name' => 'Plaza', 'vlan_id' => 10, 'login_page_id' => $portal->id, 'ad_page_id' => $adA->id],
            ['name' => 'Market', 'vlan_id' => 11, 'login_page_id' => $portal->id, 'ad_page_id' => $adB->id],
        ])->hotspotNetworks;

        $this->assertPages($plaza, 'LOGIN-SHARED', 'AD-PLAZA');
        $this->assertPages($market, 'LOGIN-SHARED', 'AD-MARKET');
    }

    public function test_networks_without_a_choice_use_the_default_design(): void
    {
        $default = SplashPage::current();
        $default->update(['html' => 'LOGIN-DEFAULT [[form]]', 'ad_html' => 'AD-DEFAULT [[connect]]']);
        $n = $this->router([['name' => 'Public WiFi', 'vlan_id' => 10]])->hotspotNetworks->first();
        $n->update(['login_page_id' => null, 'ad_page_id' => null]);

        $this->assertPages($n->fresh(), 'LOGIN-DEFAULT', 'AD-DEFAULT');
    }

    /* ---------- Router login.html ---------- */

    public function test_router_login_file_sends_each_network_to_its_own_portal(): void
    {
        [$public, $school, $staff] = $this->router([
            ['name' => 'Public WiFi', 'vlan_id' => 10],
            ['name' => 'School', 'vlan_id' => 11, 'login_mode' => 'custom', 'login_url' => 'https://school.example/login'],
            ['name' => 'Staff', 'vlan_id' => 12, 'login_mode' => 'builtin'],
        ])->hotspotNetworks;

        $html = $this->get('/hotspot-files/'.$school->portal_code.'/login.html')->assertOk()->getContent();

        $this->assertStringContainsString('"hotspot-publicwifi":"http://portal.test/portal/'.$public->portal_code.'"', $html);
        $this->assertStringContainsString('"hotspot-publicwifi-11":"https://school.example/login"', $html);
        $this->assertStringContainsString('"hotspot-publicwifi-12":null', $html);
        $this->assertStringContainsString('action="$(link-login-only)"', $html); // form for the built-in network
        $this->assertStringContainsString('targets["$(server-name)"]', $html);
    }

    public function test_router_without_external_login_has_no_login_file(): void
    {
        $n = $this->router([['name' => 'Staff', 'vlan_id' => 12, 'login_mode' => 'builtin']])->hotspotNetworks->first();

        $this->get('/hotspot-files/'.$n->portal_code.'/login.html')->assertNotFound();
    }

    /* ---------- Upgrade of routers added before networks existed ---------- */

    public function test_existing_router_becomes_its_first_network(): void
    {
        // Back to just before hotspot networks existed, however many migrations came after.
        $steps = DB::table('migrations')->where('migration', '>=', '2026_10_09_000000_create_hotspot_networks_table')->count();
        $this->artisan('migrate:rollback', ['--step' => $steps])->assertSuccessful();
        $this->assertTrue(Schema::hasColumn('mikrotik_routers', 'portal_code'));

        $page = DB::table('splash_pages')->insertGetId(Arr::except(SplashPage::defaults(), 'name') + ['created_at' => now(), 'updated_at' => now()]);
        $routerId = DB::table('mikrotik_routers')->insertGetId([
            'name' => 'old-site', 'host' => '192.0.2.1', 'api_port' => 8728, 'username' => 'api', 'password' => encrypt('x', false),
            'wan_interface' => 'ether1', 'port_roles' => json_encode(['ether1' => 'wan', 'ether2' => 'access']),
            'vlans' => json_encode(['hotspot' => ['id' => 30, 'name' => 'vlan30-hs'], 'mgmt' => ['id' => 99, 'name' => 'vlan99-mgmt'], 'test' => ['id' => 20, 'name' => 'vlan20-test']]),
            'block_index' => 5, 'subnet' => '10.64.80.0/20', 'gateway' => '10.64.80.1', 'pool_start' => '10.64.80.2', 'pool_end' => '10.64.95.254',
            'login_mode' => 'portal', 'portal_code' => 'oldcode12345', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('hotspot_guests')->insert(['mikrotik_router_id' => $routerId, 'username' => 'wifi-x', 'terms_hash' => 'h', 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('migrate')->assertSuccessful();

        $router = MikrotikRouter::find($routerId);
        $n = $router->hotspotNetworks->sole();
        $this->assertSame(['hotspot', 30, 'vlan30-hs', '10.64.80.0/20', '10.64.95.254', null, 'oldcode12345', $page, $page],
            [$n->key, $n->vlan_id, $n->interface, $n->subnet, $n->pool_end, $n->max_users, $n->portal_code, $n->login_page_id, $n->ad_page_id]);
        $this->assertSame(['ether2'], $router->accessPortsFor($n)); // plain "access" = first network
        $this->assertArrayNotHasKey('hotspot', $router->vlans);
        $this->assertSame($n->id, HotspotGuest::sole()->hotspot_network_id);
        $this->assertFalse(Schema::hasColumn('mikrotik_routers', 'portal_code'));
        $this->assertSame('Main portal', SplashPage::find($page)->name);
    }

    /* ---------- Helpers ---------- */

    /** Walks the portal like a phone: open the login page, register, see the advertisement. */
    private function assertPages(HotspotNetwork $n, string $login, string $ad): void
    {
        $mac = sprintf('AA:BB:CC:00:00:%02X', $n->id);
        $this->get("/portal/{$n->portal_code}?".http_build_query([
            'mac' => $mac, 'ip' => '10.64.0.10', 'link-login-only' => "http://{$n->gateway}/login",
        ]))->assertRedirect();

        $this->get("/portal/{$n->portal_code}")->assertOk()->assertSee($login, false)->assertSee($n->name, false);

        $this->post("/portal/{$n->portal_code}", ['name' => 'Juan Dela Cruz', 'contact' => '09171234567', 'accept' => '1'])
            ->assertRedirect("/portal/{$n->portal_code}/welcome");

        $this->get("/portal/{$n->portal_code}/welcome")->assertOk()->assertSee($ad, false);
        $this->assertSame($n->id, HotspotGuest::where('mac', $mac)->sole()->hotspot_network_id);

        $this->flushSession();
    }

    private function design(string $name, string $login, string $ad): SplashPage
    {
        SplashPage::current(); // the default exists first, as in the app

        return SplashPage::create(['name' => $name] + [
            'html' => "<h1>{$login}</h1> [[network_name]] [[form]]",
            'ad_html' => "<h1>{$ad}</h1> [[connect]]",
        ] + SplashPage::defaults());
    }

    private function router(array $networks, array $ports = ['ether1' => 'wan', 'ether2' => 'trunk', 'ether3' => 'access:10']): MikrotikRouter
    {
        $allocator = app(SubnetAllocator::class);
        $router = MikrotikRouter::create([
            'name' => 'site-'.MikrotikRouter::count(), 'host' => '192.0.2.1', 'api_port' => 8728, 'username' => 'api', 'password' => 'secret',
            'model' => 'hex', 'port_roles' => $ports, 'wan_interface' => 'ether1', 'wan_mode' => 'keep',
            'vlans' => ['mgmt' => ['id' => 99, 'name' => 'vlan99-mgmt', 'native' => false], 'test' => ['id' => 20, 'name' => 'vlan20-test']],
        ] + $allocator->next());

        foreach ($networks as $i => $n) {
            $router->hotspotNetworks()->create($allocator->nextHotspot()[0] + $n + [
                'key' => HotspotNetwork::keyFor($n['vlan_id'], $i === 0),
                'interface' => "vlan{$n['vlan_id']}-hotspot",
                'login_mode' => 'portal',
            ]);
        }

        return $router->load('hotspotNetworks');
    }

    /** A RouterOS 7 router with the default configuration's firewall. */
    private function fakeRouter(array $extra = []): FakeRouterOs
    {
        return new FakeRouterOs($extra + [
            '/system/resource' => [['board-name' => 'hEX', 'version' => '7.16']],
            '/system/identity' => [['name' => 'MikroTik']],
            '/interface/ethernet' => array_map(fn ($i) => ['name' => "ether{$i}"], range(1, 5)),
            '/interface/bridge' => [['name' => 'bridge']],
            '/interface/bridge/port' => array_map(fn ($i) => ['interface' => "ether{$i}", 'bridge' => 'bridge'], range(2, 5)),
            '/ip/address' => [['address' => '192.168.88.1/24', 'interface' => 'bridge']],
            '/interface/list' => [['name' => 'LAN'], ['name' => 'WAN']],
            '/ip/firewall/filter' => [
                ['chain' => 'input', 'action' => 'accept', 'connection-state' => 'established,related,untracked', 'comment' => 'defconf: accept established,related,untracked'],
                ['chain' => 'input', 'action' => 'drop', 'in-interface-list' => '!LAN', 'comment' => 'defconf: drop all not coming from LAN'],
                ['chain' => 'forward', 'action' => 'fasttrack-connection', 'connection-state' => 'established,related', 'comment' => 'defconf: fasttrack'],
                ['chain' => 'forward', 'action' => 'accept', 'connection-state' => 'established,related,untracked', 'comment' => 'defconf: accept established,related, untracked'],
            ],
        ]);
    }

    private function provisioner(FakeRouterOs $fake): HotspotProvisioner
    {
        return new class($fake) extends HotspotProvisioner
        {
            public function __construct(private FakeRouterOs $fake) {}

            protected function makeClient(array $config): object
            {
                return $this->fake;
            }
        };
    }
}
