<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\MikrotikRouter;
use App\Models\NetworkDevice;
use App\Models\User;
use App\Services\Mikrotik\SubnetAllocator;
use App\Services\Snmp\SnmpProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceLinksTest extends TestCase
{
    use RefreshDatabase;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->barangay = Barangay::create(['name' => 'Poblacion']);
        // No SNMP traffic in tests
        $this->mock(SnmpProbe::class, fn ($m) => $m->shouldReceive('refresh')->andReturnUsing(fn ($d) => $d));
    }

    public function test_access_point_connects_to_a_switch_and_inherits_its_router(): void
    {
        $router = $this->router('site-a');
        $switch = $this->add('switches', 'SW-1', "router:{$router->id}");
        $ap = $this->add('access-points', 'AP-1', "switch:{$switch->id}");

        $this->assertSame([$switch->id, $router->id], [$ap->uplink_device_id, $ap->mikrotik_router_id]);
        $this->assertSame([null, $router->id], [$switch->uplink_device_id, $switch->mikrotik_router_id]);
        $this->assertSame('switch:'.$switch->id, $ap->uplinkValue());
    }

    public function test_switches_cascade_but_never_loop(): void
    {
        $router = $this->router('site-a');
        $core = $this->add('switches', 'SW-CORE', "router:{$router->id}");
        $edge = $this->add('switches', 'SW-EDGE', "switch:{$core->id}");
        $ap = $this->add('access-points', 'AP-1', "switch:{$edge->id}");

        // SW-CORE under SW-EDGE (or under itself) would loop
        $this->put("/devices/{$core->id}", $this->fields('SW-CORE', "switch:{$edge->id}"))->assertSessionHasErrors('uplink');
        $this->put("/devices/{$core->id}", $this->fields('SW-CORE', "switch:{$core->id}"))->assertSessionHasErrors('uplink');
        $this->assertSame('router:'.$router->id, $core->fresh()->uplinkValue());

        // Moving the core switch to another router moves everything behind it
        $other = $this->router('site-b');
        $this->put("/devices/{$core->id}", $this->fields('SW-CORE', "router:{$other->id}"))->assertSessionHasNoErrors();
        $this->assertSame([$other->id, $other->id], [$edge->fresh()->mikrotik_router_id, $ap->fresh()->mikrotik_router_id]);
        $this->assertSame($core->id, $edge->fresh()->uplink_device_id); // still cascaded from the core
    }

    public function test_switch_list_on_the_edit_form_leaves_out_loops(): void
    {
        $core = $this->add('switches', 'SW-CORE', '');
        $edge = $this->add('switches', 'SW-EDGE', "switch:{$core->id}");
        $this->add('switches', 'SW-OTHER', '');

        $page = $this->get("/devices/{$core->id}/edit")->assertOk();
        $page->assertSee('value="switch:'.$this->id('SW-OTHER').'"', false);
        $page->assertDontSee('value="switch:'.$edge->id.'"', false);
        $page->assertDontSee('value="switch:'.$core->id.'"', false);
    }

    public function test_removing_a_switch_leaves_its_devices_unconnected(): void
    {
        $switch = $this->add('switches', 'SW-1', '');
        $ap = $this->add('access-points', 'AP-1', "switch:{$switch->id}");

        $this->delete("/devices/{$switch->id}");

        $this->assertNull($ap->fresh()->uplink_device_id);
    }

    public function test_map_data_has_routers_and_the_links_between_devices(): void
    {
        $router = $this->router('site-a');
        $this->put("/routers/{$router->id}/position", ['latitude' => '14.41', 'longitude' => '121.04'])->assertSessionHasNoErrors();
        $hidden = $this->router('site-b'); // no position: not on the map
        $switch = $this->add('switches', 'SW-1', "router:{$router->id}");
        $ap = $this->add('access-points', 'AP-1', "switch:{$switch->id}");

        $devices = collect($this->getJson('/dashboard/map-data')->assertOk()->json('devices'))->keyBy('key');

        $this->assertSame('router', $devices["router:{$router->id}"]['type']);
        $this->assertArrayNotHasKey("router:{$hidden->id}", $devices);
        $this->assertSame("router:{$router->id}", $devices["switch:{$switch->id}"]['uplink']);
        $this->assertSame("switch:{$switch->id}", $devices["ap:{$ap->id}"]['uplink']);
        $this->get('/dashboard')->assertOk()->assertSee('id="show-links"', false);
    }

    public function test_brand_is_saved_listed_and_shown_on_the_map(): void
    {
        $this->post('/access-points', ['brand' => 'Ubiquiti', 'model' => 'U6-Pro'] + $this->fields('AP-1', ''))->assertSessionHasNoErrors();
        $ap = NetworkDevice::where('name', 'AP-1')->sole();

        $this->assertSame('Ubiquiti', $ap->brand);
        // Brand and Device model columns; brands already used are suggested in the Add pop-up
        $this->get('/access-points')->assertOk()->assertSeeInOrder(['Ubiquiti', 'U6-Pro'])->assertSee('<option value="Ubiquiti">', false);
        $this->get("/devices/{$ap->id}/edit")->assertOk()->assertSee('value="Ubiquiti"', false);
        $map = collect($this->getJson('/dashboard/map-data')->json('devices'))->firstWhere('key', "ap:{$ap->id}");
        $this->assertSame('Ubiquiti U6-Pro', $map['model']);
    }

    public function test_edit_dialog_gets_brand_and_connection_so_saving_keeps_them(): void
    {
        $switch = $this->add('switches', 'SW-1', '');
        $this->post('/access-points', ['brand' => 'TP-Link'] + $this->fields('AP-1', "switch:{$switch->id}"))->assertSessionHasNoErrors();

        $this->get('/access-points')->assertOk()
            ->assertSee('data-edit-brand="TP-Link"', false)
            ->assertSee('data-edit-uplink="switch:'.$switch->id.'"', false)
            ->assertSee("setField('uplink',", false)
            ->assertSee("setField('brand',", false);
    }

    public function test_router_position_needs_both_coordinates(): void
    {
        $router = $this->router('site-a');

        $this->put("/routers/{$router->id}/position", ['latitude' => '14.41'])->assertSessionHasErrors('longitude');
        $this->put("/routers/{$router->id}/position", ['latitude' => '', 'longitude' => ''])->assertSessionHasNoErrors();
        $this->assertNull($router->fresh()->latitude);
    }

    private function add(string $path, string $name, string $uplink): NetworkDevice
    {
        $this->post("/{$path}", $this->fields($name, $uplink))->assertSessionHasNoErrors();

        return NetworkDevice::where('name', $name)->sole();
    }

    private function fields(string $name, string $uplink): array
    {
        static $host = 10;

        return [
            'name' => $name, 'uplink' => $uplink, 'barangay_id' => $this->barangay->id,
            'latitude' => '14.4'.$host, 'longitude' => '121.0'.$host,
            'host' => NetworkDevice::where('name', $name)->value('host') ?? '172.20.0.'.$host++,
            'snmp_port' => 161, 'snmp_version' => '2c', 'community' => 'public',
        ];
    }

    private function id(string $name): int
    {
        return NetworkDevice::where('name', $name)->value('id');
    }

    private function router(string $name): MikrotikRouter
    {
        return MikrotikRouter::create([
            'name' => $name, 'host' => '192.0.2.1', 'api_port' => 8728, 'username' => 'api', 'password' => 'x',
            'port_roles' => [], 'wan_interface' => 'ether1', 'vlans' => [],
        ] + app(SubnetAllocator::class)->next());
    }
}
