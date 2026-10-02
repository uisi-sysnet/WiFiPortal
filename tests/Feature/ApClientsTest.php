<?php

namespace Tests\Feature;

use App\Models\ApClientStat;
use App\Models\Barangay;
use App\Models\NetworkDevice;
use App\Models\User;
use App\Services\Dashboard\ApClientReport;
use App\Services\Snmp\SnmpProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Clients per access point: SNMP count, hourly log, Users page table, CSV, heat map, report page. */
class ApClientsTest extends TestCase
{
    use RefreshDatabase;

    private Barangay $pob;

    private Barangay $sucat;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-01 19:20:00', 'Asia/Manila')->utc());
        $this->pob = Barangay::create(['name' => 'Poblacion']);
        $this->sucat = Barangay::create(['name' => 'Sucat']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ---------- Hourly log ---------- */

    public function test_each_poll_adds_to_the_hour_with_average_and_peak(): void
    {
        $ap = $this->ap('POB-AP-1', $this->pob);
        ApClientStat::record($ap->id, 10);
        ApClientStat::record($ap->id, 30);
        ApClientStat::record($ap->id, 20);
        ApClientStat::record($ap->id, 5, now()->addHour());

        $rows = ApClientStat::orderBy('hour')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([3, 60, 30], [$rows[0]->samples, $rows[0]->total, $rows[0]->peak]);
        $this->assertSame([1, 5, 5], [$rows[1]->samples, $rows[1]->total, $rows[1]->peak]);
        $this->assertSame(now()->utc()->startOfHour()->toDateTimeString(), $rows[0]->hour->toDateTimeString());
    }

    public function test_old_rows_are_pruned(): void
    {
        config(['devices.client_stats_days' => 30]);
        $ap = $this->ap('POB-AP-1', $this->pob);
        ApClientStat::record($ap->id, 4, now()->subDays(31));
        ApClientStat::record($ap->id, 4);

        $this->assertSame(1, app(ApClientReport::class)->prune());
        $this->assertSame(1, ApClientStat::count());
    }

    /* ---------- SNMP ---------- */

    public function test_brand_profile_is_tried_first_and_the_count_is_logged(): void
    {
        $probe = $this->probe(['1.3.6.1.4.1.14988.1.1.1.3.1.6' => 17, '1.3.6.1.4.1.41112.1.6.1.2.1.8' => 99]);
        $ap = $this->ap('POB-AP-1', $this->pob, ['brand' => 'MikroTik']);

        $probe->refresh($ap);

        $ap->refresh();
        $this->assertSame('online', $ap->status);
        $this->assertSame(17, $ap->clients);
        $this->assertSame('mikrotik', $ap->clients_source);
        $this->assertSame('MikroTik', $ap->clientsSourceLabel());
        $this->assertSame('1.3.6.1.4.1.14988.1.1.1.3.1.6', $probe->walked[0]);
        $this->assertSame(17, ApClientStat::sole()->peak);
    }

    public function test_unknown_brand_tries_every_profile_and_own_oid_wins(): void
    {
        $probe = $this->probe(['1.3.6.1.4.1.41112.1.4.5.1.15' => 8, '1.3.6.1.4.1.9999.1.2' => 42]);
        $ap = $this->ap('POB-AP-1', $this->pob, ['brand' => 'Acme']);
        $this->assertSame([8, 'airmax'], $probe->clients($ap));

        $ap->forceFill(['clients_oid' => '1.3.6.1.4.1.9999.1.2', 'clients_mode' => 'count'])->save();
        $this->assertSame([42, 'custom'], $probe->clients($ap));
        $this->assertSame('count', $probe->modes[array_key_last($probe->modes)]);
    }

    public function test_ap_without_a_readable_count_is_asked_again_only_after_a_while(): void
    {
        config(['devices.client_retry_minutes' => 60]);
        $probe = $this->probe([]);
        $ap = $this->ap('POB-AP-1', $this->pob);

        $probe->refresh($ap);
        $this->assertSame('none', $ap->fresh()->clients_source);
        $this->assertNull($ap->fresh()->clients);
        $asked = count($probe->walked);
        $this->assertGreaterThan(0, $asked);

        $probe->refresh($ap->fresh());
        $this->assertCount($asked, $probe->walked); // not asked again within the hour

        Carbon::setTestNow(now()->addMinutes(61));
        $probe->refresh($ap->fresh());
        $this->assertGreaterThan($asked, count($probe->walked));
        $this->assertSame(0, ApClientStat::count());
    }

    public function test_switches_are_not_asked_for_clients(): void
    {
        $probe = $this->probe(['1.3.6.1.4.1.14988.1.1.1.3.1.6' => 5]);
        $sw = $this->ap('SW-1', $this->pob, ['type' => 'switch']);
        $probe->refresh($sw);
        $this->assertSame([], $probe->walked);
    }

    public function test_device_form_validates_the_client_oid(): void
    {
        $this->actingAs(User::factory()->create());
        $ap = $this->ap('POB-AP-1', $this->pob, ['clients_source' => 'none']);
        $form = [
            'name' => 'POB-AP-1', 'barangay_id' => $this->pob->id, 'latitude' => 14.41, 'longitude' => 121.04,
            'host' => $ap->host, 'snmp_port' => 161, 'snmp_version' => '2c',
        ];

        $this->put("/devices/{$ap->id}", $form + ['clients_oid' => 'not an oid'])->assertSessionHasErrors('clients_oid');

        $this->app->instance(SnmpProbe::class, $this->probe([]));
        $this->put("/devices/{$ap->id}", $form + ['clients_oid' => '.1.3.6.1.4.1.9999.1.2', 'clients_mode' => 'count'])->assertSessionHasNoErrors();
        $ap->refresh();
        $this->assertSame('.1.3.6.1.4.1.9999.1.2', $ap->clients_oid);
        $this->assertSame('count', $ap->clients_mode);
        $this->assertSame('custom', $ap->clients_source); // re-read with the new OID straight away
    }

    /* ---------- Reports ---------- */

    public function test_report_averages_peaks_and_shares_per_ap_over_the_period(): void
    {
        [$busy, $quiet] = $this->loggedAps();
        $report = app(ApClientReport::class);

        $day = $report->perAp($report->window('day'));
        $this->assertSame(['SUC-AP-1', 'POB-AP-1', 'POB-AP-2'], array_column($day, 'name'));
        $this->assertSame(30.0, $day[0]['average']);   // (20 + 40) / 2
        $this->assertSame(40, $day[0]['peak']);
        $this->assertSame('2026-10-01 18:00', $day[0]['peak_at']->setTimezone('Asia/Manila')->format('Y-m-d H:i'));
        $this->assertEqualsWithDelta(75.0, $day[0]['share'], 0.01);  // 30 of 40
        $this->assertSame(10.0, $day[1]['average']);
        $this->assertNull($day[2]['average']);          // never reported

        // The week also includes the 3-day-old rush on POB-AP-1
        $week = $report->perAp($report->window('week'));
        $this->assertSame('POB-AP-1', $week[0]['name']);
        $this->assertSame(200, $week[0]['peak']);

        $totals = $report->totals($report->window('day'), $day);
        $this->assertSame([3, 2, 40.0, 20.0], [$totals['aps'], $totals['reporting'], $totals['average'], $totals['per_ap']]);
        $this->assertSame(50, $totals['busiest']['clients']); // 18:00: 40 + 10
        $this->assertSame('SUC-AP-1', $totals['top']['name']);

        $this->assertSame([['name' => 'Sucat', 'aps' => 1, 'value' => 30.0], ['name' => 'Poblacion', 'aps' => 2, 'value' => 10.0]],
            $report->perBarangay($day));
    }

    public function test_users_page_shows_clients_per_access_point(): void
    {
        $this->actingAs(User::factory()->create());
        $this->loggedAps();

        $this->get('/users?range=day')->assertOk()
            ->assertSee('Clients per access point')
            ->assertSeeInOrder(['SUC-AP-1', 'Sucat', '30', '40', 'POB-AP-1', 'Poblacion', '10'])
            ->assertSee('No count in this period')
            ->assertSee(route('users.heatmap', ['range' => 'day']), false);
    }

    public function test_csv_lists_every_access_point(): void
    {
        $this->actingAs(User::factory()->create());
        $this->loggedAps();

        $csv = $this->get('/users/access-points.csv?range=day')->assertOk()->streamedContent();
        $lines = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
        $this->assertSame('Clients per access point: Last 24 hours', $lines[0][0]);
        $this->assertSame(['SUC-AP-1', 'Sucat'], array_slice($lines[2], 0, 2));
        $this->assertSame(['30', '40', '2026-10-01 18:00'], [$lines[2][5], $lines[2][6], $lines[2][7]]);
        $this->assertCount(5, $lines);
    }

    public function test_heat_map_page_weights_access_points_by_clients(): void
    {
        $this->actingAs(User::factory()->create());
        $this->loggedAps();

        $page = $this->get('/users/heatmap?range=day')->assertOk()->assertSee('Heat map: clients per access point');
        $points = collect($page->viewData('points'))->keyBy('name');
        $this->assertSame(30.0, $points['SUC-AP-1']['value']);
        $this->assertSame(0.0, $points['POB-AP-2']['value']);
        $page->assertSeeInOrder(['Busiest barangays', 'Sucat', 'Poblacion']);

        $peak = collect($this->get('/users/heatmap?range=day&metric=peak')->viewData('points'))->keyBy('name');
        $this->assertSame(40.0, $peak['SUC-AP-1']['value']);

        // Live: the count read at the last poll
        $now = collect($this->get('/users/heatmap?metric=now')->assertOk()->viewData('points'))->keyBy('name');
        $this->assertSame(12.0, $now['SUC-AP-1']['value']);

        $this->get('/users/heatmap?range=day&barangay='.$this->pob->id)->assertOk()->assertDontSee('SUC-AP-1');
        $this->get('/users/heatmap?range=custom&from=2026-10-01&to=2026-09-01')->assertSessionHasErrors('from');
    }

    public function test_dashboard_map_and_heat_layer_get_live_clients(): void
    {
        $this->actingAs(User::factory()->create());
        $this->loggedAps();

        $devices = collect($this->getJson('/dashboard/map-data')->json('devices'))->keyBy('name');
        $this->assertSame(12, $devices['SUC-AP-1']['clients']);
        $this->assertNull($devices['POB-AP-2']['clients']); // offline
        $this->get('/dashboard')->assertOk()->assertSee('id="show-heat"', false)->assertSee('leaflet-heat.js', false);
    }

    public function test_report_export_can_add_a_page_of_clients_per_access_point(): void
    {
        $this->actingAs(User::factory()->create());
        $this->loggedAps();

        $one = $this->get('/users/report.pdf?range=day')->assertOk()->getContent();
        $this->assertSame(1, preg_match_all('~/Type\s*/Page(?!s)~', $one));

        $two = $this->get('/users/report.pdf?range=day&aps=1')->assertOk()->getContent();
        $this->assertSame(2, preg_match_all('~/Type\s*/Page(?!s)~', $two));

        $this->get('/users/report.pdf?range=day&aps=1&format=png')->assertOk()
            ->assertSee('Clients per access point')->assertSeeInOrder(['SUC-AP-1', 'POB-AP-1']);
    }

    /* ---------- Helpers ---------- */

    private function ap(string $name, Barangay $b, array $extra = []): NetworkDevice
    {
        return NetworkDevice::forceCreate($extra + [
            'type' => 'ap', 'name' => $name, 'host' => '172.20.0.'.(NetworkDevice::count() + 2), 'snmp_port' => 161, 'snmp_version' => '2c',
            'community' => 'public', 'barangay_id' => $b->id, 'status' => 'online', 'latitude' => 14.4 + NetworkDevice::count() / 100, 'longitude' => 121.04,
        ]);
    }

    /**
     * SUC-AP-1: 20 then 40 clients today (18:00 and 19:00), 12 now.
     * POB-AP-1: 10 today; 200 three days ago. POB-AP-2: offline, never reported.
     */
    private function loggedAps(): array
    {
        $at = fn ($t) => Carbon::parse($t, 'Asia/Manila');
        $suc = $this->ap('SUC-AP-1', $this->sucat, ['clients' => 12]);
        $pob = $this->ap('POB-AP-1', $this->pob, ['clients' => 3]);
        $this->ap('POB-AP-2', $this->pob, ['status' => 'offline']);

        ApClientStat::record($suc->id, 20, $at('2026-10-01 19:05'));
        ApClientStat::record($suc->id, 40, $at('2026-10-01 18:10'));
        ApClientStat::record($pob->id, 10, $at('2026-10-01 18:40'));
        ApClientStat::record($pob->id, 200, $at('2026-09-28 20:00'));

        return [$suc, $pob];
    }

    /** An SNMP probe that answers check() and a fixed set of OIDs, and records what it walked. */
    private function probe(array $answers): SnmpProbe
    {
        return new class($answers) extends SnmpProbe
        {
            public array $walked = [];

            public array $modes = [];

            public function __construct(private array $answers) {}

            public function check(NetworkDevice $device): array
            {
                return ['sys_name' => $device->name, 'sys_descr' => 'test', 'uptime_seconds' => 100];
            }

            protected function walkCount(NetworkDevice $device, string $oid, string $mode): ?int
            {
                $this->walked[] = ltrim($oid, '.');
                $this->modes[] = $mode;

                return $this->answers[ltrim($oid, '.')] ?? null;
            }
        };
    }
}
