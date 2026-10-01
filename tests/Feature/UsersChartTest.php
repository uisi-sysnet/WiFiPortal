<?php

namespace Tests\Feature;

use App\Models\MikrotikRouter;
use App\Models\User;
use App\Services\Dashboard\UserHistory;
use App\Services\Mikrotik\SubnetAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UsersChartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hotspot.history.timezone' => 'Asia/Manila']);
        // 2026-10-01 19:20 in Manila
        Carbon::setTestNow(Carbon::parse('2026-10-01 19:20:00', 'Asia/Manila')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_snapshot_saves_users_of_answering_routers(): void
    {
        $this->router('online', 300, now());
        $this->router('online', 120, now());
        $this->router('offline', null, now());

        $this->artisan('users:snapshot')->expectsOutput('Saved users online.')->assertSuccessful();

        $row = DB::table('user_samples')->sole();
        $this->assertSame([420, 2], [(int) $row->users, (int) $row->routers_online]);
    }

    public function test_snapshot_skips_when_routers_are_not_being_polled(): void
    {
        $this->router('online', 300, now()->subMinutes(10)); // poller stopped

        $this->artisan('users:snapshot')->assertSuccessful();

        $this->assertSame(0, DB::table('user_samples')->count());
    }

    public function test_old_samples_are_removed(): void
    {
        $this->router('online', 10, now());
        $this->sample(now()->subDays(500), 99);

        $this->artisan('users:snapshot');

        $this->assertSame([10], DB::table('user_samples')->pluck('users')->map(fn ($v) => (int) $v)->all());
    }

    public function test_day_shows_the_peak_of_each_hour_with_gaps(): void
    {
        $this->sample($this->manila('2026-10-01 18:05'), 900);
        $this->sample($this->manila('2026-10-01 18:40'), 1200); // peak of 18:00
        $this->sample($this->manila('2026-10-01 19:15'), 1100);
        $this->sample($this->manila('2026-09-30 19:30'), 5000); // older than 24 hours

        $s = app(UserHistory::class)->series('day');

        $this->assertCount(24, $s['points']);
        $this->assertSame('2026-09-30 20:00', $s['points'][0]['at']->format('Y-m-d H:i'));
        $this->assertSame('2026-10-01 19:00', $s['points'][23]['at']->format('Y-m-d H:i'));
        $this->assertSame([1200, 1100], [$s['points'][22]['value'], $s['points'][23]['value']]);
        $this->assertNull($s['points'][10]['value']); // no data that hour: a gap
        $this->assertSame(1200, $s['peak']['value']);
        $this->assertSame(1100, $s['now']);
    }

    public function test_week_month_and_year_buckets(): void
    {
        $this->sample($this->manila('2026-10-01 08:00'), 700);
        $this->sample($this->manila('2026-09-20 21:00'), 1500);
        $this->sample($this->manila('2026-03-02 12:00'), 2500);

        $history = app(UserHistory::class);
        $week = $history->series('week');
        $month = $history->series('month');
        $year = $history->series('year');

        $this->assertCount(168, $week['points']);
        $this->assertSame(700, $week['peak']['value']);

        $this->assertCount(30, $month['points']);
        $this->assertSame('2026-09-02', $month['points'][0]['at']->format('Y-m-d'));
        $this->assertSame(1500, $month['peak']['value']);
        $this->assertSame('2026-09-20', $month['peak']['at']->format('Y-m-d'));

        $this->assertCount(12, $year['points']);
        $this->assertSame('2025-11', $year['points'][0]['at']->format('Y-m'));
        $this->assertSame(['2026-03' => 2500, '2026-09' => 1500, '2026-10' => 700],
            collect($year['points'])->filter(fn ($p) => $p['value'])->mapWithKeys(fn ($p) => [$p['at']->format('Y-m') => $p['value']])->all());
    }

    public function test_dashboard_chart_and_filter_endpoint(): void
    {
        $this->actingAs(User::factory()->create());
        $this->sample($this->manila('2026-10-01 19:15'), 19403); // 5 minutes ago: counts as "now"

        $this->get('/dashboard')->assertOk()
            ->assertSee('data-range="week"', false)
            ->assertSee('Now 19,403')
            ->assertSee('Peak <span class="peak">19,403</span>', false);

        $this->get('/dashboard/users-chart?range=year')->assertOk()
            ->assertSee('Last 12 months, highest users online in each month')
            ->assertSee('October 2026: 19,403 users');
        $this->getJson('/dashboard/users-chart?range=decade')->assertStatus(422);
    }

    public function test_empty_chart_explains_itself(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/dashboard/users-chart?range=day')->assertOk()->assertSee('No history yet');
    }

    private function manila(string $time): Carbon
    {
        return Carbon::parse($time, 'Asia/Manila')->utc();
    }

    private function sample(Carbon $at, int $users): void
    {
        DB::table('user_samples')->insert(['recorded_at' => $at, 'users' => $users, 'routers_online' => 1]);
    }

    private function router(string $link, ?int $users, Carbon $polled): MikrotikRouter
    {
        return MikrotikRouter::forceCreate([
            'name' => 'site-'.uniqid(), 'host' => '192.0.2.1', 'api_port' => 8728, 'username' => 'api', 'password' => encrypt('x'),
            'port_roles' => '{}', 'wan_interface' => 'ether1', 'vlans' => '{}',
            'link_status' => $link, 'active_users' => $users, 'last_polled_at' => $polled,
        ] + app(SubnetAllocator::class)->next());
    }
}
