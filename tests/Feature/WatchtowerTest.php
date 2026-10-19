<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\MikrotikRouter;
use App\Models\NetworkDevice;
use App\Models\SystemEvent;
use App\Models\User;
use App\Services\Mikrotik\SubnetAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Watchtower: the map dashboard with live panels over it. */
class WatchtowerTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_role_opens_it_from_the_sidebar(): void
    {
        foreach (['viewer', 'user', 'admin'] as $role) {
            $this->actingAs(User::factory()->role($role)->create());
            $this->get('/watchtower')->assertOk()->assertSee('Watchtower')->assertSee('id="wt-map"', false);
            $this->get('/dashboard')->assertSee('href="'.route('watchtower').'"', false);
        }
        auth()->logout();
        $this->get('/watchtower')->assertRedirect(route('login'));
    }

    public function test_data_has_status_counts_and_what_to_check_first_pointing_at_map_pins(): void
    {
        $this->actingAs(User::factory()->role('viewer')->create());
        $b = Barangay::create(['name' => 'Buli']);
        $router = MikrotikRouter::forceCreate(['name' => 'MUN-CORE-01', 'host' => '10.10.0.1', 'api_port' => 8728, 'username' => 'api', 'password' => 'x',
            'model' => 'hex', 'port_roles' => [], 'wan_interface' => 'ether1', 'vlans' => [], 'link_status' => 'online', 'active_users' => 812,
            'latitude' => 14.40, 'longitude' => 121.04] + app(SubnetAllocator::class)->next());
        $sw = NetworkDevice::forceCreate(['type' => 'switch', 'name' => 'BUL-SW-02', 'host' => '172.20.0.2', 'snmp_version' => '2c', 'status' => 'offline',
            'barangay_id' => $b->id, 'location' => 'Barangay hall', 'mikrotik_router_id' => $router->id, 'last_seen_at' => now()->subHour(),
            'latitude' => 14.44, 'longitude' => 121.05]);
        NetworkDevice::forceCreate(['type' => 'ap', 'name' => 'BUL-AP-01', 'host' => '172.20.1.1', 'snmp_version' => '2c', 'status' => 'offline',
            'barangay_id' => $b->id, 'uplink_device_id' => $sw->id, 'mikrotik_router_id' => $router->id, 'last_seen_at' => now()->subHour(),
            'latitude' => 14.441, 'longitude' => 121.051]);
        foreach (range(2, 5) as $i) { // 1 of 5 access points down: a warning, not critical
            NetworkDevice::forceCreate(['type' => 'ap', 'name' => "POB-AP-0{$i}", 'host' => "172.20.1.{$i}", 'snmp_version' => '2c', 'status' => 'online',
                'barangay_id' => $b->id, 'mikrotik_router_id' => $router->id, 'latitude' => 14.38, 'longitude' => 121.04]);
        }
        SystemEvent::log('down', 'switch', 'Switch BUL-SW-02 went offline');

        $d = $this->getJson('/watchtower/data')->assertOk()->json();

        $this->assertSame('warning', $d['status']['level']);
        $this->assertSame(['total' => 1, 'online' => 0, 'offline' => 1, 'unknown' => 0], $d['counts']['switch']);
        $this->assertSame(812, $d['kpis']['users']['online']);
        $this->assertCount(1, $d['roots']);
        $root = $d['roots'][0];
        $this->assertSame(['switch:'.$sw->id, 'Switch', 'BUL-SW-02', 1], [$root['key'], $root['label'], $root['name'], $root['behind']]);
        $this->assertContains($root['key'], array_column($d['devices'], 'key')); // the list can fly the map to it
        $this->assertSame('Switch BUL-SW-02 went offline', $d['events'][0]['text']);
        $this->assertSame('Buli', $d['barangays'][0]['name']);
        $this->assertArrayHasKey('points', $d['chart']);

        // Viewers look, they don't edit
        $this->assertNull(collect($d['devices'])->firstWhere('key', 'switch:'.$sw->id)['edit']);
    }

    public function test_page_starts_with_the_data_already_in_it(): void
    {
        $this->actingAs(User::factory()->create());
        SystemEvent::log('ok', 'router', 'Router SUC-03 is back online');

        $this->get('/watchtower')->assertOk()->assertSee('Router SUC-03 is back online')->assertSee('watchtower\/data', false); // the refresh URL, JSON-encoded in the page
    }
}
