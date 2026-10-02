<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\CapacityAlert;
use App\Models\MikrotikRouter;
use App\Models\NetworkDevice;
use App\Services\Mikrotik\SubnetAllocator;
use App\Services\Monitoring\Troubleshooter;
use App\Services\Reports\ReportImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** First troubleshooting from "Connected to": what is the root cause, what is just behind it. */
class TroubleshooterTest extends TestCase
{
    use RefreshDatabase;

    private MikrotikRouter $core;

    protected function setUp(): void
    {
        parent::setUp();
        $this->core = $this->router('MUN-CORE-01', 'online');
    }

    public function test_everything_online_is_normal(): void
    {
        $sw = $this->dev('switch', 'POB-SW-01', 'online');
        $this->dev('ap', 'POB-AP-01', 'online', $sw);

        $t = app(Troubleshooter::class)->analyse();
        $this->assertSame('normal', $t['status']['level']);
        $this->assertSame([], $t['roots']);
        $this->assertSame(['total' => 1, 'online' => 1, 'offline' => 0, 'unknown' => 0], $t['counts']['ap']);
        $this->assertSame(['total' => 1, 'online' => 1, 'offline' => 0, 'unknown' => 0], $t['counts']['router']);
    }

    public function test_ap_offline_with_its_switch_online_is_the_problem_itself(): void
    {
        $sw = $this->dev('switch', 'POB-SW-01', 'online');
        $this->dev('ap', 'POB-AP-07', 'offline', $sw, 'Near the church');
        foreach (range(1, 5) as $i) {
            $this->dev('ap', 'POB-AP-0'.$i, 'online', $sw);
        }

        $t = app(Troubleshooter::class)->analyse();
        $this->assertSame('warning', $t['status']['level']);
        $root = $t['roots'][0];
        $this->assertSame(['ap', 'POB-AP-07', 'Poblacion · Near the church'], [$root['type'], $root['name'], $root['where']]);
        $this->assertStringContainsString('(Switch POB-SW-01) is online, so the problem is at this access point', $root['diagnosis']['finding']);
        $this->assertContains('Check power: PoE from POB-SW-01 or the adapter.', $root['diagnosis']['steps']);
        $this->assertNull($t['offline'][0]['cause']);
    }

    public function test_switch_offline_is_the_root_cause_for_its_access_points(): void
    {
        $sw = $this->dev('switch', 'BUL-SW-02', 'offline');
        $cascade = $this->dev('switch', 'BUL-SW-03', 'offline', $sw);   // cascaded from it
        $this->dev('ap', 'BUL-AP-01', 'offline', $sw);
        $this->dev('ap', 'BUL-AP-02', 'offline', $cascade);
        $this->dev('ap', 'BUL-AP-03', 'online', null);

        $t = app(Troubleshooter::class)->analyse();
        $this->assertCount(1, $t['roots']);
        $root = $t['roots'][0];
        $this->assertSame('BUL-SW-02', $root['name']);
        $this->assertSame(['switch' => 1, 'ap' => 2], $root['behind_count']);
        $this->assertStringContainsString('(Router MUN-CORE-01) is online, so the problem is at this switch', $root['diagnosis']['finding']);
        $this->assertSame('3 devices behind it are offline because of it; they come back with it.', $root['diagnosis']['affected']);
        $causes = collect($t['offline'])->pluck('cause', 'name');
        $this->assertSame([null, 'Switch BUL-SW-02', 'Switch BUL-SW-02'], [$causes['BUL-SW-02'], $causes['BUL-SW-03'], $causes['BUL-AP-02']]);
    }

    public function test_router_down_is_critical_and_comes_first(): void
    {
        $tun = $this->router('TUN-07', 'offline');
        $sw = $this->dev('switch', 'TUN-SW-01', 'offline', null, 'Health center', $tun);
        $this->dev('ap', 'TUN-AP-01', 'offline', $sw, 'Lobby', $tun);
        $other = $this->dev('switch', 'POB-SW-01', 'online');
        $this->dev('ap', 'POB-AP-07', 'offline', $other);

        $t = app(Troubleshooter::class)->analyse();
        $this->assertSame('critical', $t['status']['level']);
        $this->assertStringStartsWith('1 router is down', $t['status']['reasons'][0]);
        $this->assertSame(['TUN-07', 'POB-AP-07'], array_column($t['roots'], 'name'));
        $this->assertSame('The router is not answering.', $t['roots'][0]['diagnosis']['finding']);
        $this->assertCount(2, $t['roots'][0]['behind']);
    }

    public function test_never_answered_and_unknown_uplink_get_their_own_advice(): void
    {
        $this->dev('ap', 'CUP-AP-031', 'offline', null, 'School', null, null);
        $this->dev('ap', 'ALA-AP-12', 'offline', null, 'Market', null);

        $roots = collect(app(Troubleshooter::class)->analyse()['roots'])->keyBy('name');
        $this->assertSame('Has never answered since it was added.', $roots['CUP-AP-031']['diagnosis']['finding']);
        $this->assertStringContainsString('"Connected to" is not set', $roots['ALA-AP-12']['diagnosis']['finding']);
    }

    public function test_many_access_points_down_or_a_full_router_is_critical(): void
    {
        config(['devices.critical_ap_percent' => 25]);
        $sw = $this->dev('switch', 'SW', 'online');
        $this->dev('ap', 'AP-1', 'offline', $sw);
        foreach (range(2, 4) as $i) {
            $this->dev('ap', 'AP-'.$i, 'online', $sw);
        }
        $this->assertSame('critical', app(Troubleshooter::class)->analyse()['status']['level']); // 25%

        NetworkDevice::query()->update(['status' => 'online']);
        CapacityAlert::create(['mikrotik_router_id' => $this->core->id, 'kind' => 'users', 'level' => 'full', 'value' => 97, 'message' => 'Add a gateway.', 'opened_at' => now()]);
        $t = app(Troubleshooter::class)->analyse();
        $this->assertSame('critical', $t['status']['level']);
        $this->assertStringContainsString('full', $t['status']['reasons'][0]);
    }

    public function test_picture_is_drawn_for_a_big_outage_within_telegram_limits(): void
    {
        $sw = $this->dev('switch', 'BIG-SW', 'offline');
        foreach (range(1, 150) as $i) {
            $this->dev('ap', 'AP-'.$i, 'offline', $i % 3 ? $sw : null);
        }

        $png = app(ReportImage::class)->png(app(Troubleshooter::class)->analyse(),
            ['at' => now('Asia/Manila'), 'users' => 0, 'outages' => 151, 'recoveries' => 0, 'period' => 'Last 24 hours']);
        [$w, $h] = getimagesizefromstring($png);
        $this->assertSame(1080, $w);
        $this->assertLessThanOrEqual(10000 - 1080, $h); // Telegram: width + height <= 10,000
    }

    private function router(string $name, string $status): MikrotikRouter
    {
        return MikrotikRouter::forceCreate(['name' => $name, 'host' => '10.10.0.'.(MikrotikRouter::count() + 1), 'api_port' => 8728, 'username' => 'api',
            'password' => 'x', 'model' => 'hex', 'port_roles' => [], 'wan_interface' => 'ether1', 'vlans' => [], 'link_status' => $status,
            'last_seen_at' => now()->subHour()] + app(SubnetAllocator::class)->next());
    }

    private function dev(string $type, string $name, string $status, ?NetworkDevice $uplink = null, ?string $where = null, ?MikrotikRouter $router = null, $seen = 'default'): NetworkDevice
    {
        return NetworkDevice::forceCreate([
            'type' => $type, 'name' => $name, 'host' => '172.20.'.(NetworkDevice::count() % 250).'.'.(NetworkDevice::count() + 1), 'snmp_version' => '2c',
            'status' => $status, 'barangay_id' => Barangay::firstOrCreate(['name' => 'Poblacion'])->id, 'location' => $where,
            'uplink_device_id' => $uplink?->id,
            'mikrotik_router_id' => $uplink ? $uplink->mikrotik_router_id : (func_num_args() >= 6 && $router === null ? null : ($router ?? $this->core)->id),
            'last_seen_at' => $seen === 'default' ? now()->subMinutes(30) : $seen,
        ]);
    }
}
