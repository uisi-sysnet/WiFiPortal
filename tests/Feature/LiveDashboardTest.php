<?php

namespace Tests\Feature;

use App\Jobs\PollRouters;
use App\Models\HotspotGuest;
use App\Models\HotspotNetwork;
use App\Models\MikrotikRouter;
use App\Models\NetworkDevice;
use App\Models\User;
use App\Services\Mikrotik\HotspotProvisioner;
use App\Services\Mikrotik\RouterMonitor;
use App\Services\Mikrotik\SubnetAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\FakeRouterOs;
use Tests\TestCase;

class LiveDashboardTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, FakeRouterOs|null> host => fake router (null = does not answer) */
    private array $routers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $routers = &$this->routers;
        // A closure with use (&...): an arrow function would copy the list and miss later changes.
        $this->app->bind(HotspotProvisioner::class, function () use (&$routers) {
            return new class($routers) extends HotspotProvisioner
            {
                public function __construct(private array &$routers)
                {
                }

                protected function makeClient(array $config): object
                {
                    return $this->routers[$config['host']] ?? throw new RuntimeException('Connection timed out');
                }
            };
        });
    }

    public function test_poll_counts_users_per_router_and_network(): void
    {
        $router = $this->router('192.0.2.1', ['Public WiFi' => 10, 'School' => 11]);
        $this->routers['192.0.2.1'] = $this->sessions(['hotspot-publicwifi' => 120, 'hotspot-publicwifi-11' => 35]);

        app(RouterMonitor::class)->refresh($router);

        $router->refresh();
        $this->assertSame(['online', 155, 0], [$router->link_status, $router->active_users, $router->poll_failures]);
        $this->assertNotNull($router->last_seen_at);
        $this->assertSame([120, 35], $router->hotspotNetworks->pluck('active_users')->all());
    }

    public function test_router_goes_offline_after_missed_polls(): void
    {
        $router = $this->router('192.0.2.1', ['Public WiFi' => 10]);
        $this->routers['192.0.2.1'] = $this->sessions(['hotspot-publicwifi' => 50]);
        app(RouterMonitor::class)->refresh($router);

        unset($this->routers['192.0.2.1']); // stops answering
        app(RouterMonitor::class)->refresh($router->refresh());
        $this->assertSame(['online', 50, 1], [$router->link_status, $router->active_users, $router->poll_failures]); // one miss: grace

        app(RouterMonitor::class)->refresh($router->refresh());
        $this->assertSame(['offline', null, 2], [$router->link_status, $router->active_users, $router->poll_failures]);
        $this->assertNull($router->hotspotNetworks()->first()->active_users);
        $this->assertStringContainsString('No answer', $router->poll_error);
    }

    public function test_router_that_never_answered_is_offline_at_once(): void
    {
        $router = $this->router('192.0.2.9', ['Public WiFi' => 10]);

        app(RouterMonitor::class)->refresh($router);

        $this->assertSame('offline', $router->refresh()->link_status);
    }

    public function test_dashboard_totals_users_of_all_answering_routers(): void
    {
        $this->actingAs(User::factory()->create());
        $a = $this->router('192.0.2.1', ['Public WiFi' => 10, 'School' => 11]);
        $b = $this->router('192.0.2.2', ['Public WiFi' => 10]);
        $c = $this->router('192.0.2.3', ['Public WiFi' => 10]);
        $this->routers['192.0.2.1'] = $this->sessions(['hotspot-publicwifi' => 1200, 'hotspot-publicwifi-11' => 300]);
        $this->routers['192.0.2.2'] = $this->sessions(['hotspot-publicwifi' => 734]);
        // 192.0.2.3 does not answer
        foreach ([$a, $b, $c] as $r) {
            app(RouterMonitor::class)->refresh($r);
        }
        NetworkDevice::forceCreate(['type' => 'switch', 'name' => 'sw-1', 'host' => '172.20.0.2', 'snmp_version' => '2c', 'status' => 'online']);
        NetworkDevice::forceCreate(['type' => 'ap', 'name' => 'ap-1', 'host' => '172.20.0.3', 'snmp_version' => '2c', 'status' => 'offline']);
        HotspotGuest::create(['mikrotik_router_id' => $a->id, 'username' => 'wifi-a', 'terms_hash' => 'h']);

        $page = $this->get('/dashboard')->assertOk();
        $page->assertSeeInOrder(['Users online', '2,234', 'Routers', 'Switches', 'Access points']);
        $page->assertSee('<b>2 of 3</b> routers online', false);
        $page->assertSee('1 not answering', false);
        $page->assertSee('<b>1</b> registered today', false);

        $live = $this->getJson('/dashboard/live')->assertOk();
        $live->assertJson(['users' => 2234, 'routers' => ['total' => 3, 'online' => 2, 'offline' => 1]]);
        $this->assertStringContainsString('2,234', $live->json('html'));
    }

    public function test_busiest_barangays_lists_every_barangay_with_its_ap_clients(): void
    {
        $this->actingAs(User::factory()->create());
        $pob = \App\Models\Barangay::create(['name' => 'Poblacion']);
        $sucat = \App\Models\Barangay::create(['name' => 'Sucat']);
        \App\Models\Barangay::create(['name' => 'Tunasan']); // no access points
        $ap = fn ($b, $status, $clients) => NetworkDevice::forceCreate([
            'type' => 'ap', 'name' => 'ap-'.uniqid(), 'host' => '172.20.0.'.mt_rand(2, 250), 'snmp_version' => '2c',
            'barangay_id' => $b->id, 'status' => $status, 'clients' => $clients,
        ]);
        $ap($pob, 'online', 40);
        $ap($pob, 'online', 25);
        $ap($pob, 'offline', 90);   // offline: its last count is stale, not added
        $ap($sucat, 'online', 120);
        $ap($sucat, 'online', null); // doesn't report

        $page = $this->get('/dashboard')->assertOk();
        $page->assertSee('Busiest Locations Now');
        $page->assertSeeInOrder(['Sucat', '120', 'Poblacion', '65', 'Tunasan', 'No access points']);
        $page->assertSee('2 of 3 APs online', false);
        $page->assertSee('2 of 2 APs online, 1 reporting', false);
        $page->assertDontSee('Client counts are not collected');

        $this->assertStringContainsString('Sucat', $this->getJson('/dashboard/live')->json('barangays'));
    }

    public function test_dashboard_before_any_poll(): void
    {
        $this->actingAs(User::factory()->create());
        $this->router('192.0.2.1', ['Public WiFi' => 10]);

        $this->get('/dashboard')->assertOk()->assertSee('Waiting for the first router check');
    }

    public function test_poll_command_queues_router_batches(): void
    {
        Queue::fake();
        config(['hotspot.poll.batch' => 2]);
        foreach (range(1, 5) as $i) {
            $this->router("192.0.2.{$i}", ['Public WiFi' => 10]);
        }

        $this->artisan('routers:poll')->assertSuccessful();

        Queue::assertPushed(PollRouters::class, 3);
    }

    /** A fake router with this many logged-in users per hotspot server. */
    private function sessions(array $perServer): FakeRouterOs
    {
        $rows = [];
        foreach ($perServer as $server => $count) {
            for ($i = 0; $i < $count; $i++) {
                $rows[] = ['server' => $server, 'user' => "wifi-{$i}"];
            }
        }

        return new FakeRouterOs(['/ip/hotspot/active' => $rows]);
    }

    private function router(string $host, array $networks): MikrotikRouter
    {
        $allocator = app(SubnetAllocator::class);
        $router = MikrotikRouter::create([
            'name' => 'site-'.$host, 'host' => $host, 'api_port' => 8728, 'username' => 'api', 'password' => 'secret',
            'port_roles' => ['ether1' => 'wan', 'ether2' => 'trunk'], 'wan_interface' => 'ether1', 'wan_mode' => 'keep',
            'vlans' => ['mgmt' => ['id' => 99, 'name' => 'vlan99-mgmt'], 'test' => ['id' => 20, 'name' => 'vlan20-test']],
        ] + $allocator->next());

        $first = true;
        foreach ($networks as $name => $vlan) {
            $router->hotspotNetworks()->create($allocator->nextHotspot()[0] + [
                'name' => $name, 'vlan_id' => $vlan, 'interface' => "vlan{$vlan}-hotspot",
                'key' => HotspotNetwork::keyFor($vlan, $first), 'login_mode' => 'portal',
            ]);
            $first = false;
        }

        return $router;
    }
}
