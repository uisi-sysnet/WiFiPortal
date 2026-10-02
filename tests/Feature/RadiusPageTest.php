<?php

namespace Tests\Feature;

use App\Models\HotspotGuest;
use App\Models\HotspotNetwork;
use App\Models\MikrotikRouter;
use App\Models\User;
use App\Services\Mikrotik\SubnetAllocator;
use App\Services\Radius\RadiusLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\RadiusTables;
use Tests\TestCase;

/** The RADIUS page: overview, login attempts with likely reasons, sessions, look-up. */
class RadiusPageTest extends TestCase
{
    use RefreshDatabase;

    private MikrotikRouter $router;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-02 19:00:00', 'Asia/Manila')->utc());
        config(['hotspot.radius.host' => '127.0.0.1', 'hotspot.radius.secret' => 'secret']);
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_without_freeradius_tables_the_page_explains_how_to_set_it_up(): void
    {
        $this->get('/radius')->assertOk()->assertSee('RADIUS is not set up on this server yet')->assertSee('setup-radius.sh');
        $this->get('/radius?view=attempts')->assertOk()->assertSee('not set up');
    }

    public function test_overview_counts_logins_and_separates_unregistered_phones(): void
    {
        $this->seedRadius();

        $h = app(RadiusLog::class)->health();
        $this->assertSame(['accepted' => 2, 'rejected' => 3, 'mac_rejected' => 1, 'user_rejected' => 2], $h['hour']);
        $this->assertSame(1, $h['online']);
        $this->assertSame(1, $h['missing']); // Maria: valid, but no login in RADIUS

        $this->get('/radius')->assertOk()
            ->assertSee('RADIUS is answering')
            ->assertSee('1 valid registration has no login in RADIUS', false)
            ->assertSeeInOrder(['Latest refused logins', 'wifi-ana00001', 'Online now', 'wifi-juan0001']);
    }

    public function test_attempts_show_the_likely_reason_and_never_the_password(): void
    {
        $this->seedRadius();

        $page = $this->get('/radius?view=attempts&result=rejected')->assertOk();
        $page->assertSee('Phone not registered: normal, it is shown the captive portal')
            ->assertSee('Access time ended')                                   // Ana's registration expired
            ->assertSee('Wrong password, or a different phone (MAC) than the one that registered')
            ->assertDontSee('secret-password')
            ->assertDontSee('Accepted</span>', false);

        $this->get('/radius?view=attempts&q=AA:BB:CC:00:00:01')->assertOk()->assertSee('wifi-juan0001')->assertDontSee('wifi-ana00001');
    }

    public function test_sessions_online_and_history_with_data_used(): void
    {
        $this->seedRadius();

        $this->get('/radius?view=sessions')->assertOk()
            ->assertSee('wifi-juan0001')->assertSee('site-a')->assertSee('1.5 GB')
            ->assertDontSee('wifi-old00001');

        $all = $this->get('/radius?view=sessions&status=all')->assertOk();
        $all->assertSee('wifi-old00001')->assertSee('Session timeout')       // finished
            ->assertSee('No updates from the router');                       // left open by a router that died
    }

    public function test_look_up_a_phone_by_mac(): void
    {
        $this->seedRadius();

        $this->get('/radius?view=lookup&q=aa-bb-cc-00-00-01')->assertOk()
            ->assertSee('Juan Dela Cruz')
            ->assertSee('only for phone AA:BB:CC:00:00:01')
            ->assertSee('Oct 03 2026 19:00:00')
            ->assertSeeInOrder(['Recent login attempts', 'wifi-juan0001', 'Recent sessions', 'wifi-juan0001']);

        $this->get('/radius?view=lookup&q=AA:BB:CC:00:00:03')->assertOk()
            ->assertSee('The registration is valid but has no login in RADIUS');
    }

    public function test_old_attempts_and_sessions_are_pruned(): void
    {
        $this->seedRadius();
        config(['hotspot.radius.auth_log_days' => 30, 'hotspot.radius.acct_days' => 365]);
        DB::table('radpostauth')->insert(['username' => 'wifi-ancient', 'reply' => 'Access-Accept', 'authdate' => now()->subDays(31)]);
        DB::table('radacct')->insert(['username' => 'wifi-ancient', 'acctstarttime' => now()->subDays(400), 'acctstoptime' => now()->subDays(399)]);

        $this->assertSame([1, 1], app(RadiusLog::class)->prune());
        $this->assertFalse(DB::table('radpostauth')->where('username', 'wifi-ancient')->exists());
    }

    /**
     * Juan: valid, online now (accepted twice). Ana: expired, refused. Pedro: valid but
     * refused (different phone). Maria: valid, made before RADIUS (no radcheck row).
     * An unregistered phone refused by MAC.
     */
    private function seedRadius(): void
    {
        RadiusTables::create();
        $allocator = app(SubnetAllocator::class);
        $this->router = MikrotikRouter::forceCreate(['name' => 'site-a', 'host' => '10.10.0.1', 'api_port' => 8728, 'username' => 'api', 'password' => 'x',
            'model' => 'hex', 'port_roles' => [], 'wan_interface' => 'ether1', 'vlans' => []] + $allocator->next());
        $net = $this->router->hotspotNetworks()->create($allocator->nextHotspot()[0] + ['name' => 'Public WiFi', 'vlan_id' => 10,
            'key' => HotspotNetwork::keyFor(10, true), 'interface' => 'vlan10-hotspot', 'login_mode' => 'portal']);

        $guest = fn (string $user, string $mac, array $extra) => HotspotGuest::create($extra + [
            'mikrotik_router_id' => $this->router->id, 'hotspot_network_id' => $net->id, 'category' => 'visitor',
            'username' => $user, 'mac' => $mac, 'terms_hash' => 'x', 'expires_at' => now()->addDay(),
        ]);
        $guest('wifi-juan0001', 'AA:BB:CC:00:00:01', ['name' => 'Juan Dela Cruz']);
        $guest('wifi-ana00001', 'AA:BB:CC:00:00:02', ['name' => 'Ana Reyes', 'expires_at' => now()->subHours(2)]);
        $guest('wifi-maria001', 'AA:BB:CC:00:00:03', ['name' => 'Maria Santos']);
        $guest('wifi-pedro001', 'AA:BB:CC:00:00:04', ['name' => 'Pedro Cruz']);

        $exp = 'Oct 03 2026 19:00:00';
        foreach (['wifi-juan0001' => 'AA:BB:CC:00:00:01', 'wifi-pedro001' => 'AA:BB:CC:00:00:04'] as $user => $mac) {
            DB::table('radcheck')->insert([
                ['username' => $user, 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'secret-password'],
                ['username' => $user, 'attribute' => 'Calling-Station-Id', 'op' => '==', 'value' => $mac],
                ['username' => $user, 'attribute' => 'Expiration', 'op' => ':=', 'value' => $exp],
            ]);
        }

        $auth = fn ($user, $reply, $minutes, $mac = null) => DB::table('radpostauth')->insert([
            'username' => $user, 'pass' => 'secret-password', 'reply' => $reply, 'callingstationid' => $mac, 'calledstationid' => 'hotspot-publicwifi',
            'authdate' => now()->subMinutes($minutes),
        ]);
        $auth('wifi-juan0001', 'Access-Accept', 50, 'AA:BB:CC:00:00:01');
        $auth('AA:BB:CC:00:00:01', 'Access-Accept', 20, 'AA:BB:CC:00:00:01');
        $auth('wifi-ana00001', 'Access-Reject', 15, 'AA:BB:CC:00:00:02');
        $auth('wifi-pedro001', 'Access-Reject', 10, 'AA:BB:CC:00:00:99');
        $auth('DE:AD:BE:EF:00:01', 'Access-Reject', 5, 'DE:AD:BE:EF:00:01');

        DB::table('radacct')->insert([
            ['username' => 'wifi-juan0001', 'nasipaddress' => '10.10.0.1', 'callingstationid' => 'AA:BB:CC:00:00:01', 'framedipaddress' => '10.64.0.10',
                'acctstarttime' => now()->subMinutes(50), 'acctupdatetime' => now()->subMinutes(3), 'acctstoptime' => null,
                'acctsessiontime' => 2820, 'acctinputoctets' => 120 * 1024 ** 2, 'acctoutputoctets' => (int) (1.5 * 1024 ** 3), 'acctterminatecause' => null],
            ['username' => 'wifi-old00001', 'nasipaddress' => '10.10.0.1', 'callingstationid' => 'AA:BB:CC:00:00:05', 'framedipaddress' => null,
                'acctstarttime' => now()->subDay(), 'acctupdatetime' => now()->subHours(20), 'acctstoptime' => now()->subHours(20),
                'acctsessiontime' => 14400, 'acctinputoctets' => 1000, 'acctoutputoctets' => 5000, 'acctterminatecause' => 'Session-Timeout'],
            ['username' => 'wifi-ghost001', 'nasipaddress' => '10.10.0.9', 'callingstationid' => 'AA:BB:CC:00:00:06', 'framedipaddress' => null,
                'acctstarttime' => now()->subHours(5), 'acctupdatetime' => now()->subHours(4), 'acctstoptime' => null,
                'acctsessiontime' => 3600, 'acctinputoctets' => 0, 'acctoutputoctets' => 0, 'acctterminatecause' => null],
        ]);
    }
}
