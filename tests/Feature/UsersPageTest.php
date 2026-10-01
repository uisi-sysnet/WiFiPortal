<?php

namespace Tests\Feature;

use App\Models\HotspotGuest;
use App\Models\HotspotNetwork;
use App\Models\MikrotikRouter;
use App\Models\User;
use App\Services\Mikrotik\SubnetAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsersPageTest extends TestCase
{
    use RefreshDatabase;

    private HotspotNetwork $plaza;
    private HotspotNetwork $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());

        $alloc = app(SubnetAllocator::class);
        $router = MikrotikRouter::create([
            'name' => 'site-a', 'host' => '192.0.2.1', 'api_port' => 8728, 'username' => 'api', 'password' => 'x',
            'port_roles' => [], 'wan_interface' => 'ether1', 'vlans' => [],
        ] + $alloc->next());
        $this->plaza = $router->hotspotNetworks()->create($alloc->nextHotspot()[0] + ['name' => 'Plaza', 'key' => 'hotspot', 'vlan_id' => 10, 'interface' => 'vlan10']);
        $this->school = $router->hotspotNetworks()->create($alloc->nextHotspot()[0] + ['name' => 'School', 'key' => 'hotspot-11', 'vlan_id' => 11, 'interface' => 'vlan11']);

        $this->guest($this->plaza, ['name' => 'Juan Dela Cruz', 'contact' => '+639171234567', 'contact_type' => 'phone', 'mac' => 'AA:BB:CC:00:00:01', 'connected_at' => now()]);
        $this->guest($this->school, ['name' => 'Maria Santos', 'contact' => 'maria@example.com', 'contact_type' => 'email']);
        $this->guest($this->plaza, ['resident' => true, 'citizen_number' => 'MUN-2024-5678', 'connected_at' => now()->subDays(2), 'expires_at' => now()->subDay(), 'created_at' => now()->subDays(2)]);
    }

    public function test_menu_opens_the_users_page(): void
    {
        $this->get('/settings')->assertOk()->assertSee('href="'.route('users.index').'"', false)->assertSee('>Users</a>', false);
        $this->get('/dashboard')->assertOk()->assertSee('href="'.route('users.index').'">Users</a>', false);
    }

    public function test_lists_everyone_newest_first_with_status(): void
    {
        $page = $this->get('/users')->assertOk();

        $page->assertSeeInOrder(['Maria Santos', 'Juan Dela Cruz', 'Resident']);
        $page->assertSee('Connected <b>1</b>', false)->assertSee('Not connected <b>1</b>', false)->assertSee('Expired <b>1</b>', false);
        $page->assertSee('<b>2</b> registered today', false);
        $page->assertSee('maria@example.com')->assertSee('Plaza')->assertSee('site-a');
        // Resident IDs are hidden except the last 4
        $page->assertSee('••••••5678')->assertDontSee('MUN-2024-5678');
    }

    public function test_search_and_filters(): void
    {
        $this->get('/users?q=0917')->assertSee('Juan Dela Cruz')->assertDontSee('Maria Santos');
        $this->get('/users?q=aa:bb:cc')->assertSee('Juan Dela Cruz')->assertDontSee('Maria Santos');
        $this->get('/users?q=5678')->assertSee('••••••5678')->assertDontSee('Juan Dela Cruz');
        $this->get('/users?type=resident')->assertSee('••••••5678')->assertDontSee('Maria Santos');
        $this->get('/users?network='.$this->school->id)->assertSee('Maria Santos')->assertDontSee('Juan Dela Cruz');
        $this->get('/users?status=active')->assertSee('Juan Dela Cruz')->assertDontSee('Maria Santos');
        $this->get('/users?status=expired')->assertSee('••••••5678')->assertDontSee('Juan Dela Cruz');
        $this->get('/users?period=today')->assertDontSee('••••••5678')->assertSee('Maria Santos');
        $this->get('/users?q=nobody')->assertSee('No users match');
        $this->get('/users?status=bogus')->assertSessionHasErrors('status');
    }

    public function test_empty_list(): void
    {
        HotspotGuest::query()->delete();

        $this->get('/users')->assertOk()->assertSee('No users yet');
    }

    private function guest(HotspotNetwork $n, array $attrs): void
    {
        HotspotGuest::forceCreate($attrs + [
            'mikrotik_router_id' => $n->mikrotik_router_id, 'hotspot_network_id' => $n->id,
            'username' => 'wifi-'.uniqid(), 'terms_hash' => 'h', 'ip' => '10.64.0.'.mt_rand(2, 250),
            'expires_at' => now()->addHours(20),
        ]);
    }
}
