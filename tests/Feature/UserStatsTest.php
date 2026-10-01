<?php

namespace Tests\Feature;

use App\Models\HotspotGuest;
use App\Models\User;
use App\Services\Portal\RegistrationStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UserStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hotspot.history.timezone' => 'Asia/Manila']);
        Carbon::setTestNow(Carbon::parse('2026-10-01 19:20:00', 'Asia/Manila')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_same_person_counts_once_as_unique_then_as_repeated(): void
    {
        $this->register('2026-10-01 08:00', contact: '+639171111111');
        $this->register('2026-10-01 12:00', contact: '+639171111111'); // same visitor again
        $this->register('2026-09-30 09:00', contact: '+639172222222');
        $this->register('2026-09-29 10:00', resident: 'MUN-1');
        $this->register('2026-10-01 18:00', resident: 'MUN-1');           // same resident again
        $this->register('2026-10-01 18:30', resident: 'MUN-1');           // and again
        $this->register('2026-10-01 07:00', mac: 'AA:BB:CC:00:00:09');    // no contact: by device

        $s = app(RegistrationStats::class)->summary('week');

        $this->assertSame([7, 4, 3, 2], [$s['accumulated'], $s['unique'], $s['repeated'], $s['returning']]);
        $this->assertSame('57.1%', RegistrationStats::percent($s['unique'], $s['accumulated']));
        $this->assertSame('42.9%', RegistrationStats::percent($s['repeated'], $s['accumulated']));
        $this->assertEqualsWithDelta(1.0, $s['average'], 0.001); // 7 over 7 days
        $this->assertSame(5, $s['peak']['total']);               // Oct 1
        $this->assertSame('2026-10-01', $s['peak']['at']->format('Y-m-d'));
    }

    public function test_bars_per_period(): void
    {
        $this->register('2026-10-01 19:05', contact: 'a@x.test');
        $this->register('2026-10-01 19:10', contact: 'a@x.test');
        $this->register('2026-09-15 10:00', contact: 'b@x.test');
        $this->register('2026-03-02 10:00', contact: 'c@x.test');
        $this->register('2025-09-02 10:00', contact: 'd@x.test'); // older than a year

        $stats = app(RegistrationStats::class);
        $day = $stats->summary('day');
        $month = $stats->summary('month');
        $year = $stats->summary('year');

        $this->assertCount(24, $day['bars']);
        $this->assertSame(['unique' => 1, 'repeated' => 1], array_intersect_key(end($day['bars']), ['unique' => 0, 'repeated' => 0]));
        $this->assertCount(30, $month['bars']);
        $this->assertSame(3, $month['accumulated']);
        $this->assertCount(12, $year['bars']);
        $this->assertSame('2025-11', $year['bars'][0]['at']->format('Y-m'));
        $this->assertSame(4, $year['accumulated']);
        $this->assertCount(7, $stats->summary('week')['bars']);
    }

    public function test_all_covers_everything_since_the_first_registration(): void
    {
        $this->register('2025-08-14 10:00', contact: 'a@x.test');  // 14 months ago
        $this->register('2026-02-02 10:00', contact: 'a@x.test');
        $this->register('2026-10-01 09:00', contact: 'b@x.test');

        $s = app(RegistrationStats::class)->summary('all');

        $this->assertSame('month', $s['step']);
        $this->assertCount(15, $s['bars']);                          // Aug 2025 .. Oct 2026
        $this->assertSame('2025-08', $s['bars'][0]['at']->format('Y-m'));
        $this->assertSame([3, 2, 1], [$s['accumulated'], $s['unique'], $s['repeated']]);
        $this->assertSame('All time, since August 2025', $s['title']);
        $this->assertEqualsWithDelta(3 / 15, $s['average'], 0.001); // per month
    }

    public function test_all_switches_to_one_bar_per_year_after_three_years(): void
    {
        $this->register('2022-05-01 10:00', contact: 'a@x.test');
        $this->register('2026-10-01 09:00', contact: 'b@x.test');

        $s = app(RegistrationStats::class)->summary('all');

        $this->assertSame('year', $s['step']);
        $this->assertSame(['2022', '2023', '2024', '2025', '2026'], array_map(fn ($b) => $b['at']->format('Y'), $s['bars']));
    }

    public function test_all_with_no_registrations_yet(): void
    {
        $s = app(RegistrationStats::class)->summary('all');

        $this->assertSame([1, 0, 'All time'], [count($s['bars']), $s['accumulated'], $s['title']]);
    }

    public function test_users_page_shows_charts_totals_and_percentages(): void
    {
        $this->actingAs(User::factory()->create());
        $this->register('2026-10-01 08:00', contact: '+639171111111');
        $this->register('2026-10-01 12:00', contact: '+639171111111');
        $this->register('2026-09-30 09:00', contact: '+639172222222');
        $this->register('2026-09-30 10:00', contact: '+639173333333');

        $page = $this->get('/users?range=week')->assertOk();

        $page->assertSeeInOrder(['Accumulated users', '4', 'Every registration', 'Unique users', '3', '75%', 'Repeated users', '1', '25%', 'Average users']);
        $page->assertDontSee('<b>100%</b>', false); // accumulated is always 100%: not shown
        $page->assertSee('class="p-unique"', false)->assertSee('class="p-repeat"', false); // pie slices
        $page->assertSee('return visits by 1 person');
        $page->assertSee('aria-current="page" >Week</a>', false);
        $page->assertSeeInOrder(['>All</a>', '>Year</a>', '>Month</a>', '>Week</a>', '>Day</a>'], false);
        $this->get('/users?range=all')->assertOk()->assertSee('aria-current="page" >All</a>', false)
            ->assertSee('Every registration so far');
        $page->assertSee('Thu, Oct 1: 2 users (1 unique, 1 repeated)');
        // Period buttons keep the list's filters
        $html = $this->get('/users?range=week&type=visitor')->getContent();
        preg_match('~href="([^"]*range=year[^"]*)"~', $html, $m);
        $this->assertStringContainsString('type=visitor', $m[1]);
    }

    public function test_export_panel_offers_every_period_and_a_date_range(): void
    {
        $this->actingAs(User::factory()->create());

        $page = $this->get('/users?range=week&type=visitor')->assertOk();

        $page->assertSee('action="'.route('users.report').'"', false);
        foreach (['all', 'year', 'month', 'week', 'day', 'custom'] as $range) {
            $page->assertSee('name="range" value="'.$range.'"', false);
        }
        $page->assertSee('name="range" value="week" checked', false); // the period on screen is preselected
        $page->assertSee('name="from"', false)->assertSee('name="to"', false);
        $page->assertSee('<input type="hidden" name="type" value="visitor">', false);
    }

    public function test_export_downloads_a_pdf_for_a_period_or_a_date_range(): void
    {
        $this->actingAs(User::factory()->create());
        $this->register('2026-10-01 08:00', contact: '+639171111111');

        foreach (['all', 'year', 'month', 'week', 'day'] as $range) {
            $res = $this->get('/users/report.pdf?range='.$range)->assertOk();
            $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
            $this->assertStringContainsString("filename=users-report-{$range}-2026-10-01-1920.pdf", $res->headers->get('Content-Disposition'));
            $this->assertStringStartsWith('%PDF-', $res->getContent());
        }

        $res = $this->get('/users/report.pdf?range=custom&from=2026-09-01&to=2026-09-30&type=visitor')->assertOk();
        $this->assertStringContainsString('filename=users-report-2026-09-01-to-2026-09-30-', $res->headers->get('Content-Disposition'));
    }

    public function test_report_fits_on_one_page_even_with_many_bars_and_networks(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (range(1, 60) as $i) {
            $this->register(Carbon::parse('2026-10-01 19:00', 'Asia/Manila')->subDays($i * 5)->format('Y-m-d H:i'), contact: 'p'.($i % 40).'@x.test');
        }

        foreach (['all', 'year', 'month', 'day'] as $range) {
            $pdf = $this->get('/users/report.pdf?range='.$range)->assertOk()->getContent();
            $this->assertSame(1, preg_match_all('~/Type\s*/Page(?!s)~', $pdf), "{$range} report should be one page");
        }
        $pdf = $this->get('/users/report.pdf?range=custom&from=2026-01-01&to=2026-09-30')->getContent();
        $this->assertSame(1, preg_match_all('~/Type\s*/Page(?!s)~', $pdf));
    }

    public function test_image_format_returns_the_report_page_for_the_browser_to_capture(): void
    {
        $this->actingAs(User::factory()->create());
        $this->register('2026-10-01 08:00', contact: '+639171111111');

        $res = $this->get('/users/report.pdf?range=week&format=png')->assertOk();

        $this->assertStringStartsWith('text/html', $res->headers->get('Content-Type'));
        $res->assertSee('body { width: 1123px;', false)
            ->assertSee('System developed by Uplink Integrated Solutions Inc.', false)
            ->assertDontSee('position: fixed', false);
        $this->get('/users')->assertSee('name="format" value="png"', false)->assertSee('html-to-image', false);
    }

    public function test_bad_date_ranges_are_refused(): void
    {
        $this->actingAs(User::factory()->create());

        $this->from('/users')->get('/users/report.pdf?range=custom&from=2026-09-30&to=2026-09-01')->assertRedirect('/users')->assertSessionHasErrors('from');
        $this->from('/users')->get('/users/report.pdf?range=custom&from=2026-09-01&to=2026-12-31')->assertSessionHasErrors('to');
        $this->from('/users')->get('/users/report.pdf?range=custom')->assertSessionHasErrors(['from', 'to']);
        $this->from('/users')->get('/users/report.pdf?range=decade')->assertSessionHasErrors('range');
    }

    public function test_date_range_picks_the_bar_size(): void
    {
        $this->register('2026-09-10 08:00', contact: 'a@x.test');
        $this->register('2026-09-10 21:00', contact: 'a@x.test');
        $this->register('2026-09-12 10:00', contact: 'b@x.test');
        $this->register('2026-09-20 10:00', contact: 'c@x.test'); // after the range

        $stats = app(RegistrationStats::class);
        $twoDays = $stats->summary('custom', [], ['2026-09-10', '2026-09-11']);
        $days = $stats->summary('custom', [], ['2026-09-01', '2026-09-15']);
        $months = $stats->summary('custom', [], ['2025-01-01', '2026-09-30']);
        $years = $stats->summary('custom', [], ['2020-01-01', '2026-09-30']);

        $this->assertSame(['hour', 48, 2, 1, 1], [$twoDays['step'], count($twoDays['bars']), $twoDays['accumulated'], $twoDays['unique'], $twoDays['repeated']]);
        $this->assertSame('Sep 10 to Sep 11, 2026', $twoDays['title']);
        $this->assertSame(['day', 15, 3], [$days['step'], count($days['bars']), $days['accumulated']]); // Sep 20 left out
        $this->assertSame(['month', 21, 4], [$months['step'], count($months['bars']), $months['accumulated']]);
        $this->assertSame(['year', 7], [$years['step'], count($years['bars'])]);
        $this->assertSame('September 10, 2026', $stats->summary('custom', [], ['2026-09-10', '2026-09-10'])['title']);
    }

    public function test_report_has_the_numbers_but_no_personal_details(): void
    {
        $this->register('2026-10-01 08:00', contact: '+639171111111');
        $this->register('2026-10-01 12:00', contact: '+639171111111');
        $this->register('2026-09-30 09:00', resident: 'MUN-2024-5678');
        $s = app(RegistrationStats::class)->summary('week');

        $html = view('users.report', [
            'stats' => $s,
            'filters' => ['range' => 'week', 'type' => 'visitor'],
            'network' => null,
            'pie' => \App\Support\PieChart::dataUri([[$s['unique'], '#0e670d'], [$s['repeated'], '#7FB77E']]),
            'generated' => now()->timezone('Asia/Manila'),
            'by' => 'Admin',
            'topNetworks' => [['name' => 'site-a / Plaza', 'count' => 3]],
            'otherNetworks' => 0,
            'reference' => 'UR-20261001-192000-ABCD',
        ])->render();

        // Footer: system-generated, when, by whom, reference, developer
        $this->assertStringContainsString('This is a system-generated report', $html);
        $this->assertStringContainsString('October 1, 2026 at 7:20 PM (Asia/Manila)', $html);
        $this->assertStringContainsString('at the request of Admin', $html);
        $this->assertStringContainsString('UR-20261001-192000-ABCD', $html);
        $this->assertStringContainsString('System developed by Uplink Integrated Solutions Inc. &ndash; System &amp; Network Department', $html);
        // Summary and breakdown
        $this->assertStringContainsString('by <b>2</b> different people', $html);
        $this->assertStringContainsString('site-a / Plaza', $html);
        $this->assertStringContainsString('Residents (resident ID)', $html);
        $this->assertStringContainsString('About these numbers', $html);

        $this->assertStringContainsString('Last 7 days', $html);
        $this->assertStringContainsString('Users: <b style="color:#0F1A1F">Visitors only</b>', $html);
        $this->assertStringContainsString('Every registration in this period', $html);
        $this->assertStringNotContainsString('<b>100%</b>', $html);
        $this->assertStringContainsString('<b>66.7%</b> of accumulated: different people', $html);
        $this->assertStringContainsString('<b>33.3%</b> of accumulated: return visits by 1 person', $html);
        $this->assertStringContainsString('src="data:image/png;base64,', $html); // pie
        $this->assertStringContainsString('Unique and repeated', $html);
        // No list: no contacts, names or resident IDs
        $this->assertStringNotContainsString('+63917', $html);
        $this->assertStringNotContainsString('Test Person', $html);
        $this->assertStringNotContainsString('5678', $html);
    }

    public function test_pie_image_is_a_png(): void
    {
        foreach ([[[3, '#0e670d'], [1, '#7FB77E']], [[5, '#0e670d'], [0, '#7FB77E']], [[0, '#0e670d'], [0, '#7FB77E']]] as $slices) {
            $uri = \App\Support\PieChart::dataUri($slices, 120);
            $png = base64_decode(substr($uri, strlen('data:image/png;base64,')));
            $this->assertSame([120, 120], array_slice(getimagesizefromstring($png), 0, 2));
        }
    }

    public function test_empty_period(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/users')->assertOk()->assertSee('No registrations in this period yet')->assertSee('aria-current="page" >Month</a>', false);
    }

    private function register(string $at, ?string $contact = null, ?string $resident = null, ?string $mac = null): void
    {
        HotspotGuest::forceCreate([
            'resident' => $resident !== null, 'citizen_number' => $resident,
            'name' => $resident ? null : 'Test Person', 'contact' => $contact,
            'mac' => $mac ?? 'AA:BB:CC:'.substr(md5(uniqid()), 0, 2).':00:01',
            'username' => 'wifi-'.uniqid(), 'terms_hash' => 'h',
            'created_at' => Carbon::parse($at, 'Asia/Manila')->utc(),
        ]);
    }
}
