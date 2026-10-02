<?php

namespace Tests\Feature;

use App\Models\CapacityAlert;
use App\Models\HotspotNetwork;
use App\Models\MikrotikRouter;
use App\Models\SystemEvent;
use App\Models\User;
use App\Services\Mikrotik\HotspotProvisioner;
use App\Services\Mikrotik\RouterMonitor;
use App\Services\Mikrotik\SubnetAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\FakeRouterOs;
use Tests\TestCase;

class CapacityTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, FakeRouterOs> host => fake router */
    private array $routers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $routers = &$this->routers;
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

    public function test_rated_users_come_from_the_router_its_model_or_the_default(): void
    {
        $ccr = $this->router(['model' => 'detected', 'board_name' => 'CCR2216-1G-12XS-2XQ']);
        $hex = $this->router(['model' => 'hex']);
        $unknown = $this->router(['model' => 'detected', 'board_name' => 'RB2011UiAS']);
        $set = $this->router(['model' => 'ccr2216', 'rated_users' => 1800]);

        $this->assertSame([2500, 150, 200, 1800], [$ccr->ratedUsers(), $hex->ratedUsers(), $unknown->ratedUsers(), $set->ratedUsers()]);
    }

    public function test_router_over_its_rating_says_to_add_a_gateway_not_a_vlan(): void
    {
        $router = $this->router(['rated_users' => 100]);
        $monitor = app(RouterMonitor::class);

        $this->online($router, users: 85);
        $monitor->refresh($router);
        $alert = CapacityAlert::open()->sole();
        $this->assertSame(['users', 'busy', 85.0], [$alert->kind, $alert->level, $alert->value]);
        $this->assertStringContainsString('Add another gateway router', $alert->message);
        $this->assertStringContainsString('Another VLAN on this router will not add capacity', $alert->message);

        $this->online($router, users: 97);
        $monitor->refresh($router->fresh());
        $this->assertSame('full', CapacityAlert::open()->sole()->level); // same alert, now full
        $this->assertSame(1, CapacityAlert::count());

        $this->online($router, users: 77); // under 80 but not 5 under: stays open (no flicker)
        $monitor->refresh($router->fresh());
        $this->assertSame(1, CapacityAlert::open()->count());

        $this->online($router, users: 60);
        $monitor->refresh($router->fresh());
        $this->assertSame(0, CapacityAlert::open()->count());
        $this->assertNotNull(CapacityAlert::sole()->resolved_at);

        // Logged (and sent to Telegram) when it opens, turns full and clears; not on every poll
        $this->assertSame([['warn', 'Busy: '], ['down', 'Full: '], ['ok', 'Back to normal: ']],
            SystemEvent::where('kind', 'capacity')->orderBy('id')->get()->map(fn ($e) => [$e->level, substr($e->title, 0, strpos($e->title, ':') + 2)])->all());
    }

    public function test_full_address_pool_says_to_add_a_vlan(): void
    {
        $router = $this->router(['rated_users' => 2500]);
        $net = $this->network($router, 10, maxUsers: 300); // pool of 300 addresses

        $this->online($router, users: 280, leases: ['dhcp-hotspot' => 290]);
        app(RouterMonitor::class)->refresh($router);

        $alert = CapacityAlert::open()->sole();
        $this->assertSame(['pool', 'full', $net->id], [$alert->kind, $alert->level, $alert->hotspot_network_id]);
        $this->assertStringContainsString('has handed out 290 of its 300 addresses', $alert->message);
        $this->assertStringContainsString('add another hotspot network (VLAN)', $alert->message);
        $this->assertStringNotContainsString('put the new network on another router', $alert->message);
        $this->assertSame(290, $net->fresh()->leases);
    }

    public function test_pool_and_router_both_full_says_to_put_the_new_vlan_on_another_router(): void
    {
        $router = $this->router(['rated_users' => 300]);
        $this->network($router, 10, maxUsers: 300);

        $this->online($router, users: 295, leases: ['dhcp-hotspot' => 296]);
        app(RouterMonitor::class)->refresh($router);

        $this->assertEqualsCanonicalizing(['users', 'pool'], CapacityAlert::open()->pluck('kind')->all());
        $this->assertStringContainsString('put the new network on another router', CapacityAlert::where('kind', 'pool')->sole()->message);
    }

    public function test_cpu_is_smoothed_so_one_spike_is_not_an_alert(): void
    {
        $router = $this->router(['rated_users' => 2500]);
        $monitor = app(RouterMonitor::class);

        $this->online($router, users: 10, cpu: 40);
        $monitor->refresh($router);
        $this->online($router, users: 10, cpu: 100); // one spike
        $monitor->refresh($router->fresh());

        $router->refresh();
        $this->assertSame([100, 58.0], [$router->cpu_load, $router->cpu_avg]);
        $this->assertSame(0, CapacityAlert::count());

        foreach (range(1, 6) as $i) { // stays high
            $monitor->refresh($router->fresh());
        }
        $alert = CapacityAlert::open()->sole();
        $this->assertSame('cpu', $alert->kind);
        $this->assertStringContainsString('another VLAN on this router will not help', $alert->message);
    }

    public function test_memory_is_read_and_offline_clears_live_readings(): void
    {
        $router = $this->router(['rated_users' => 2500]);
        $this->network($router, 10);
        $this->online($router, users: 10, leases: ['dhcp-hotspot' => 12]);
        app(RouterMonitor::class)->refresh($router);
        $this->assertEqualsWithDelta(75.0, $router->fresh()->memoryPercent(), 0.01);

        unset($this->routers[$router->host]);
        $router->forceFill(['poll_failures' => 5])->save();
        app(RouterMonitor::class)->refresh($router->fresh());

        $router->refresh();
        $this->assertSame(['offline', null, null], [$router->link_status, $router->cpu_load, $router->hotspotNetworks->first()->leases]);
    }

    public function test_pages_show_capacity_and_alerts(): void
    {
        $this->actingAs(User::factory()->create());
        $router = $this->router(['rated_users' => 100, 'name' => 'POB-RTR']);
        $this->network($router, 10);
        $this->online($router, users: 96, leases: ['dhcp-hotspot' => 96]);
        app(RouterMonitor::class)->refresh($router);

        $this->get("/routers/{$router->id}")->assertOk()
            ->assertSee('Capacity')->assertSee('of 100 rated')->assertSee('96% of rated capacity')
            ->assertSee('Full:')->assertSee('Another VLAN on this router will not add capacity');
        $this->get('/routers')->assertOk()->assertSee('96 of 100 users');
        $this->get('/dashboard')->assertOk()->assertSee('Capacity alerts')->assertSee('POB-RTR: users at 96% of capacity');
        $this->assertStringContainsString('Capacity alerts', $this->getJson('/dashboard/live')->json('alerts'));

        // Raising the rating clears the alert right away
        $this->put("/routers/{$router->id}/capacity", ['rated_users' => 2500])->assertRedirect();
        $this->assertSame(2500, $router->fresh()->ratedUsers());
        $this->assertSame(0, CapacityAlert::open()->count());
        $this->get('/dashboard')->assertDontSee('id="alerts-title"', false);

        // Empty goes back to the model's figure
        $this->put("/routers/{$router->id}/capacity", ['rated_users' => ''])->assertSessionHasNoErrors();
        $this->assertNull($router->fresh()->rated_users);
        $this->put("/routers/{$router->id}/capacity", ['rated_users' => 0])->assertSessionHasErrors('rated_users');
    }

    public function test_ccr2216_is_in_the_model_list(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/routers/create')->assertOk()->assertSee('CCR2216-1G-12XS-2XQ (21 ports)');
    }

    /** A fake router with users online, CPU and DHCP leases per server (8 of 32 GB free). */
    private function online(MikrotikRouter $router, int $users, int $cpu = 20, array $leases = []): void
    {
        $sessions = array_map(fn ($i) => ['server' => 'hotspot-publicwifi', 'user' => "wifi-{$i}"], range(1, $users));
        $rows = [];
        foreach ($leases as $server => $n) {
            for ($i = 0; $i < $n; $i++) {
                $rows[] = ['server' => $server, 'status' => 'bound', 'address' => "10.64.0.{$i}"];
            }
            $rows[] = ['server' => $server, 'status' => 'waiting'];
        }
        $this->routers[$router->host] = new FakeRouterOs([
            '/ip/hotspot/active' => $sessions,
            '/system/resource' => [['cpu-load' => (string) $cpu, 'free-memory' => (string) (8 * 1024 ** 3), 'total-memory' => (string) (32 * 1024 ** 3)]],
            '/ip/dhcp-server/lease' => $rows,
        ]);
    }

    private function router(array $attrs = []): MikrotikRouter
    {
        static $n = 0;
        $n++;

        return MikrotikRouter::create($attrs + [
            'name' => "site-{$n}", 'host' => "192.0.2.{$n}", 'api_port' => 8728, 'username' => 'api', 'password' => 'x',
            'model' => 'hex', 'port_roles' => [], 'wan_interface' => 'ether1', 'vlans' => [],
        ] + app(SubnetAllocator::class)->next());
    }

    private function network(MikrotikRouter $router, int $vlan, ?int $maxUsers = null): HotspotNetwork
    {
        $alloc = app(SubnetAllocator::class);
        $subnet = $alloc->nextHotspotSubnet($maxUsers ? 22 : null);

        return $router->hotspotNetworks()->create($alloc->hotspotAddressing($subnet, $maxUsers) + [
            'name' => 'Public WiFi', 'key' => HotspotNetwork::keyFor($vlan, true), 'vlan_id' => $vlan, 'interface' => "vlan{$vlan}",
        ]);
    }
}
