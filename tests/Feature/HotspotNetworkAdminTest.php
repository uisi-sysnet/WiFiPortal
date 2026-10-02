<?php

namespace Tests\Feature;

use App\Jobs\ProvisionHotspot;
use App\Models\HotspotNetwork;
use App\Models\MikrotikRouter;
use App\Models\SplashPage;
use App\Models\User;
use App\Services\Mikrotik\HotspotProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeRouterOs;
use Tests\TestCase;

class HotspotNetworkAdminTest extends TestCase
{
    use RefreshDatabase;

    private const CONNECTION = ['host' => '192.0.2.1', 'api_port' => 8728, 'username' => 'api', 'password' => 'secret'];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->actingAs(User::factory()->create());

        // Every provisioner the app resolves talks to a fake hEX.
        $this->app->bind(HotspotProvisioner::class, fn () => new class extends HotspotProvisioner
        {
            protected function makeClient(array $config): object
            {
                return new FakeRouterOs([
                    '/system/resource' => [['board-name' => 'hEX', 'version' => '7.16']],
                    '/system/identity' => [['name' => 'MikroTik']],
                    '/interface/ethernet' => array_map(fn ($i) => ['name' => "ether{$i}"], range(1, 5)),
                ]);
            }
        });
    }

    public function test_pages_render(): void
    {
        $router = $this->addRouter()->assertRedirect() ? MikrotikRouter::sole() : null;

        $this->get('/routers/create')->assertOk()->assertSee('Hotspot networks')->assertSee('Main portal');
        $this->get('/routers')->assertOk()->assertSee('School WiFi');
        $this->get("/routers/{$router->id}")->assertOk()->assertSee('School WiFi')->assertSee('Add hotspot network');
        $this->get('/splash')->assertRedirect(route('splash.login')); // the login page editor opens first
        $this->get('/splash/'.SplashPage::current()->id)->assertRedirect(route('splash.login.design', SplashPage::current()));
        $this->get(route('splash.login.design', SplashPage::current()))->assertOk()->assertSee('shown on')->assertSee('site-a / Public WiFi');
    }

    public function test_router_is_added_with_several_networks(): void
    {
        $school = SplashPage::create(['name' => 'School'] + SplashPage::defaults());

        $this->addRouter(['networks' => [
            ['name' => 'Public WiFi', 'vlan_id' => 10, 'interface' => 'vlan10-hotspot', 'login_mode' => 'portal'],
            ['name' => 'School WiFi', 'vlan_id' => 11, 'interface' => 'vlan11-school', 'login_mode' => 'portal',
                'login_page_id' => SplashPage::current()->id, 'ad_page_id' => $school->id],
        ]])->assertSessionHasNoErrors()->assertRedirect();

        $router = MikrotikRouter::sole();
        [$public, $schoolNet] = $router->hotspotNetworks;
        $this->assertSame(['hotspot', 'hotspot-11'], [$public->key, $schoolNet->key]);
        $this->assertNotSame($public->subnet, $schoolNet->subnet);
        $this->assertSame($school->id, $schoolNet->ad_page_id);
        $this->assertSame(['ether4'], $router->accessPortsFor($schoolNet));
        Queue::assertPushed(ProvisionHotspot::class);
    }

    public function test_vlan_ids_must_be_unique_across_networks(): void
    {
        $this->addRouter(['networks' => [
            ['name' => 'A', 'vlan_id' => 10, 'interface' => 'vlan10-a', 'login_mode' => 'portal'],
            ['name' => 'B', 'vlan_id' => 99, 'interface' => 'vlan99-b', 'login_mode' => 'portal'], // = management
        ], 'ports' => ['ether1' => 'wan', 'ether2' => 'trunk']])->assertSessionHasErrors('vlans');

        $this->assertSame(0, MikrotikRouter::count());
    }

    public function test_network_can_be_added_later_and_changed(): void
    {
        $this->addRouter();
        $router = MikrotikRouter::sole();
        Queue::fake();

        $this->post("/routers/{$router->id}/networks", [
            'name' => 'Market', 'vlan_id' => 12, 'interface' => 'vlan12-market', 'login_mode' => 'portal',
        ])->assertSessionHasNoErrors();
        $market = HotspotNetwork::where('name', 'Market')->sole();
        $this->assertSame('hotspot-12', $market->key);
        Queue::assertPushed(ProvisionHotspot::class, 1);

        // A taken VLAN is refused
        $this->post("/routers/{$router->id}/networks", [
            'name' => 'Dup', 'vlan_id' => 12, 'interface' => 'vlan12-dup', 'login_mode' => 'portal',
        ])->assertSessionHasErrors('vlan_id');

        // New ad design: saved, no router change needed
        $ad = SplashPage::create(['name' => 'Market ads'] + SplashPage::defaults());
        $this->put("/networks/{$market->id}", ['name' => 'Market', 'subnet' => $market->subnet, 'login_mode' => 'portal', 'ad_page_id' => $ad->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($ad->id, $market->fresh()->ad_page_id);
        Queue::assertPushed(ProvisionHotspot::class, 1);

        // New login page type: the router's login.html must be updated
        $this->put("/networks/{$market->id}", ['name' => 'Market', 'subnet' => $market->subnet, 'login_mode' => 'custom', 'login_url' => 'https://market.example/login'])
            ->assertSessionHasNoErrors();
        Queue::assertPushed(ProvisionHotspot::class, 2);
    }

    public function test_designs_can_be_created_but_not_deleted_while_in_use(): void
    {
        $this->addRouter();
        $default = SplashPage::current();

        $this->post('/splash', ['design_name' => 'Plaza ads', 'from' => $default->id])->assertRedirect();
        $plaza = SplashPage::where('name', 'Plaza ads')->sole();
        $this->assertSame($default->html, $plaza->html);

        HotspotNetwork::first()->update(['ad_page_id' => $plaza->id]);
        $this->delete("/splash/{$plaza->id}")->assertSessionHasErrors('design');
        $this->delete("/splash/{$default->id}")->assertSessionHasErrors('design');

        HotspotNetwork::first()->update(['ad_page_id' => $default->id]);
        $this->delete("/splash/{$plaza->id}")->assertSessionHasNoErrors();
        $this->assertNull(SplashPage::find($plaza->id));
    }

    public function test_networks_get_the_chosen_size_a_typed_address_and_a_user_limit(): void
    {
        $this->addRouter(['networks' => [
            'a' => ['name' => 'Small', 'vlan_id' => 10, 'interface' => 'vlan10-small', 'login_mode' => 'portal', 'prefix' => 24],
            'b' => ['name' => 'Typed', 'vlan_id' => 11, 'interface' => 'vlan11-typed', 'login_mode' => 'portal',
                'subnet' => '192.168.40.0/22', 'max_users' => 500],
            'c' => ['name' => 'Big', 'vlan_id' => 12, 'interface' => 'vlan12-big', 'login_mode' => 'portal', 'prefix' => 16],
        ], 'ports' => ['ether1' => 'wan', 'ether2' => 'trunk']])->assertSessionHasNoErrors();

        [$small, $typed, $big] = MikrotikRouter::sole()->hotspotNetworks;
        $this->assertSame(['10.64.0.0/24', '10.64.0.1', '10.64.0.254', null],
            [$small->subnet, $small->gateway, $small->pool_end, $small->max_users]);
        // Limit of 500: DHCP .2 to .501 of the /22
        $this->assertSame(['192.168.40.0/22', '192.168.40.1', '192.168.40.2', '192.168.41.245', 500],
            [$typed->subnet, $typed->gateway, $typed->pool_start, $typed->pool_end, $typed->max_users]);
        // The /16 skips past the /24 to the next aligned /16
        $this->assertSame('10.65.0.0/16', $big->subnet);
        $this->assertSame(65533, $big->capacity());
    }

    public function test_bad_addresses_and_limits_are_refused(): void
    {
        $this->addRouter();
        $taken = HotspotNetwork::first()->subnet; // 10.64.0.0/20

        $cases = [
            [['subnet' => '10.64.8.0/24'], 'overlaps Public WiFi on site-a'],
            [['subnet' => '10.70.0.5/22'], 'Use 10.70.0.0/22'],
            [['subnet' => '10.70.0.0/25'], 'Use a size from /16'],
            [['subnet' => '8.8.0.0/24'], 'private range'],
            [['subnet' => '172.20.5.0/24'], 'management address plan'],
            [['subnet' => '10.80.0.0/24', 'max_users' => 300], 'below the smallest limit of 254'], // a /24 holds 253
            [['subnet' => '10.80.0.0/22', 'max_users' => 2000], 'from 254 to 1,021'],
            [['subnet' => '10.80.0.0/22', 'max_users' => 100], 'at least 254'],
        ];
        foreach ($cases as [$input, $message]) {
            $field = isset($input['max_users']) ? 'max_users' : 'subnet';
            $response = $this->post('/routers/'.MikrotikRouter::sole()->id.'/networks', $input + [
                'name' => 'Extra', 'vlan_id' => 30, 'interface' => 'vlan30-extra', 'login_mode' => 'portal',
            ]);
            $response->assertSessionHasErrors($field);
            $this->assertStringContainsString($message, session('errors')->first($field), json_encode($input));
        }
        $this->assertSame(2, HotspotNetwork::count());
        $this->assertSame($taken, HotspotNetwork::first()->subnet);
    }

    public function test_address_and_limit_can_be_changed_later(): void
    {
        $this->addRouter();
        $network = HotspotNetwork::first();
        Queue::fake();

        $this->put("/networks/{$network->id}", [
            'name' => $network->name, 'login_mode' => 'portal', 'subnet' => '192.168.100.0/23', 'max_users' => 400,
        ])->assertSessionHasNoErrors();

        $network->refresh();
        $this->assertSame(['192.168.100.0/23', '192.168.100.1', '192.168.101.145', 400],
            [$network->subnet, $network->gateway, $network->pool_end, $network->max_users]);
        Queue::assertPushed(ProvisionHotspot::class, 1);

        // Its own current address is not an overlap; removing the limit reopens the whole subnet
        $this->put("/networks/{$network->id}", [
            'name' => $network->name, 'login_mode' => 'portal', 'subnet' => '192.168.100.0/23', 'max_users' => '',
        ])->assertSessionHasNoErrors();
        $this->assertSame(['192.168.101.254', null], [$network->fresh()->pool_end, $network->fresh()->max_users]);
    }

    private function addRouter(array $overrides = [])
    {
        $this->postJson('/routers/test-connection', self::CONNECTION)->assertOk();

        return $this->post('/routers', $overrides + self::CONNECTION + [
            'name' => 'site-a',
            'model' => 'hex',
            'ports' => ['ether1' => 'wan', 'ether2' => 'trunk', 'ether3' => 'access:10', 'ether4' => 'access:11', 'ether5' => 'none'],
            'wan_mode' => 'keep',
            'vlans' => ['mgmt' => ['id' => 99, 'name' => 'vlan99-mgmt'], 'test' => ['id' => 20, 'name' => 'vlan20-test']],
            'networks' => [
                ['name' => 'Public WiFi', 'vlan_id' => 10, 'interface' => 'vlan10-hotspot', 'login_mode' => 'portal'],
                ['name' => 'School WiFi', 'vlan_id' => 11, 'interface' => 'vlan11-school', 'login_mode' => 'portal'],
            ],
        ]);
    }
}
