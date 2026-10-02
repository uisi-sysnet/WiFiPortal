<?php

namespace Tests\Feature;

use App\Mail\NetworkReportMail;
use App\Models\MikrotikRouter;
use App\Models\NetworkDevice;
use App\Models\Setting;
use App\Models\SystemEvent;
use App\Models\User;
use App\Services\Mikrotik\HotspotProvisioner;
use App\Services\Mikrotik\RouterMonitor;
use App\Services\Mikrotik\SubnetAllocator;
use App\Services\Notify\Notifier;
use App\Services\Notify\NotifySettings;
use App\Services\Notify\Telegram;
use App\Services\Reports\ScheduledReport;
use App\Services\Reports\TelegramPictureReport;
use App\Services\Snmp\SnmpProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Support\FakeRouterOs;
use Tests\TestCase;

/** Event log, Telegram alerts, mail server settings and the scheduled report. */
class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456789:AAHfakeTokenForTests_abcdefghijklmnop';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-02 08:30:00', 'Asia/Manila')->utc());
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ---------- Events ---------- */

    public function test_router_going_down_and_back_is_logged_once_each(): void
    {
        config(['hotspot.poll.offline_after' => 2]);
        $fake = null;
        $this->app->bind(HotspotProvisioner::class, function () use (&$fake) {
            return new class($fake) extends HotspotProvisioner
            {
                public function __construct(private ?FakeRouterOs &$fake) {}

                protected function makeClient(array $config): object
                {
                    return $this->fake ?? throw new RuntimeException('Connection timed out');
                }
            };
        });
        $router = $this->router(['name' => 'site-1', 'link_status' => 'online', 'last_seen_at' => now()->subMinutes(2)]);
        $monitor = app(RouterMonitor::class);

        $monitor->refresh($router);          // one miss: grace
        $this->assertSame(0, SystemEvent::count());
        $monitor->refresh($router->fresh()); // second miss: offline
        $monitor->refresh($router->fresh()); // still offline: no new event
        $this->assertSame(['down'], SystemEvent::pluck('level')->all());
        $this->assertSame('Router site-1 stopped answering', SystemEvent::sole()->title);

        Carbon::setTestNow(now()->addMinutes(12));
        $fake = new FakeRouterOs(['/ip/hotspot/active' => [], '/system/resource' => [['cpu-load' => '5']]]);
        $monitor->refresh($router->fresh());
        $back = SystemEvent::latest('id')->first();
        $this->assertSame(['ok', 'Router site-1 is back online'], [$back->level, $back->title]);
        $this->assertStringContainsString('Down for 14 min', $back->detail);
    }

    public function test_access_point_going_offline_is_logged_with_where_it_is(): void
    {
        config(['devices.offline_after' => 1]);
        $ap = NetworkDevice::forceCreate([
            'type' => 'ap', 'name' => 'POB-AP-07', 'host' => '172.20.0.7', 'snmp_port' => 161, 'snmp_version' => '2c',
            'community' => 'public', 'status' => 'online', 'last_seen_at' => now()->subMinutes(3), 'location' => 'Plaza',
        ]);
        $probe = new class extends SnmpProbe
        {
            public bool $up = false;

            public function check(NetworkDevice $device): array
            {
                return $this->up ? ['sys_name' => 'x', 'sys_descr' => null, 'uptime_seconds' => 1] : throw new RuntimeException('No answer from 172.20.0.7:161.');
            }

            protected function walkCount(NetworkDevice $device, string $oid, string $mode): ?int
            {
                return null;
            }
        };

        $probe->refresh($ap);
        $probe->refresh($ap->fresh());
        $probe->up = true;
        $probe->refresh($ap->fresh());

        $events = SystemEvent::orderBy('id')->get();
        $this->assertSame([['down', 'ap', 'Access point POB-AP-07 went offline'], ['ok', 'ap', 'Access point POB-AP-07 is back online']],
            $events->map(fn ($e) => [$e->level, $e->kind, $e->title])->all());
        $this->assertStringContainsString('Plaza', $events[0]->detail);
        $this->assertSame('device', $events[0]->subject_type);
    }

    public function test_dashboard_and_logs_show_real_events(): void
    {
        $this->actingAs(User::factory()->create());
        SystemEvent::log('down', 'ap', 'Access point CUP-AP-031 went offline', 'Cupang, near the church.');
        SystemEvent::log('ok', 'router', 'Router SUC-03 is back online');

        $this->get('/dashboard')->assertOk()->assertSeeInOrder(['Router SUC-03 is back online', 'Access point CUP-AP-031 went offline'])
            ->assertDontSee('Router TUN-07 stopped answering'); // the old sample data
        $this->get('/logs')->assertOk()->assertSee('near the church');
        $this->get('/logs?level=ok')->assertOk()->assertSee('SUC-03')->assertDontSee('CUP-AP-031');
        $this->get('/logs?q=cupang')->assertOk()->assertSee('CUP-AP-031')->assertDontSee('SUC-03');
    }

    /* ---------- Telegram ---------- */

    public function test_new_events_go_to_telegram_in_one_message(): void
    {
        $this->telegramOn(['router', 'ap', 'ok']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        SystemEvent::log('down', 'router', 'Router TUN-07 stopped answering', 'Connection timed out');
        SystemEvent::log('down', 'ap', 'Access point A <1> went offline');
        SystemEvent::log('warn', 'capacity', 'Busy: site-1: users at 85% of capacity'); // not asked for
        SystemEvent::log('info', 'report', 'Daily report sent', notify: false);

        $this->assertSame(2, app(Notifier::class)->dispatch());

        Http::assertSentCount(2); // one message per chat
        Http::assertSent(function (HttpRequest $r) {
            return str_contains($r->url(), '/bot'.self::TOKEN.'/sendMessage') && $r['chat_id'] === '-1001234567890'
                && str_contains($r['text'], '🔴 2 down')
                && str_contains($r['text'], 'Router TUN-07 stopped answering')
                && str_contains($r['text'], 'A &lt;1&gt;')          // escaped for Telegram's HTML
                && ! str_contains($r['text'], 'Busy:');
        });
        $this->assertSame(0, SystemEvent::whereNull('notified_at')->where('notify', true)->count());
        $this->assertSame(0, app(Notifier::class)->dispatch()); // nothing twice
    }

    public function test_telegram_failure_keeps_events_for_the_next_minute_and_old_ones_are_dropped(): void
    {
        $this->telegramOn(['router']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized'], 401)]);
        SystemEvent::log('down', 'router', 'Router A stopped answering');

        $this->assertSame(0, app(Notifier::class)->dispatch());
        $this->assertSame(1, SystemEvent::whereNull('notified_at')->count());
        Http::assertSentCount(2); // tried both chats

        // Still failing 7 hours later: too old to send, marked handled
        Carbon::setTestNow(now()->addHours(7));
        $this->assertSame(0, app(Notifier::class)->dispatch());
        $this->assertSame(0, SystemEvent::whereNull('notified_at')->count());
        Http::assertSentCount(2); // not tried again
    }

    public function test_many_events_are_summed_up_not_listed(): void
    {
        $text = app(Notifier::class)->message(collect(range(1, 40))->map(fn ($i) => SystemEvent::log('down', 'ap', "Access point AP-{$i} went offline")));
        $this->assertStringContainsString('🔴 40 down', $text);
        $this->assertStringContainsString('AP-15 went offline', $text);
        $this->assertStringNotContainsString('AP-16 went offline', $text);
        $this->assertStringContainsString('…and 25 more (25 access points)', $text);
        $this->assertLessThan(4096, mb_strlen($text));
    }

    public function test_settings_save_telegram_with_the_token_encrypted(): void
    {
        $this->actingAs(User::factory()->create());
        $form = ['enabled' => '1', 'token' => self::TOKEN, 'chats' => '-1001234567890, 98765432', 'events' => ['router', 'ap']];

        $this->put('/settings/telegram', $form)->assertRedirect(route('settings').'#telegram');
        $this->assertNotSame(self::TOKEN, Setting::read('telegram.token'));
        $this->assertSame(self::TOKEN, Crypt::decryptString(Setting::read('telegram.token')));
        $t = app(NotifySettings::class)->telegram();
        $this->assertSame([true, ['-1001234567890', '98765432'], ['router', 'ap']], [$t['enabled'], $t['chats'], $t['events']]);

        // Blank token keeps the saved one; bad values are refused
        $this->put('/settings/telegram', ['token' => ''] + $form)->assertSessionHasNoErrors();
        $this->assertSame(self::TOKEN, app(NotifySettings::class)->telegram()['token']);
        $this->put('/settings/telegram', ['token' => 'nope', 'chats' => 'hello'] + $form)->assertSessionHasErrors(['token', 'chats.0'], null, 'telegram');

        $page = $this->get('/settings')->assertOk()->assertSee('Telegram alerts')->assertSee('Saved. Leave blank to keep it.');
        $page->assertDontSee(self::TOKEN);
    }

    public function test_test_message_and_find_chats_explain_telegram_errors(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake([
            'api.telegram.org/bot'.self::TOKEN.'/getUpdates*' => Http::response(['ok' => true, 'result' => [
                ['update_id' => 1, 'message' => ['chat' => ['id' => -1001234567890, 'title' => 'NOC Team', 'type' => 'supergroup']]],
                ['update_id' => 2, 'message' => ['chat' => ['id' => 555, 'first_name' => 'Ana', 'type' => 'private']]],
            ]]),
            'api.telegram.org/bot'.self::TOKEN.'/sendMessage' => Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found'], 400),
        ]);

        $chats = $this->postJson('/settings/telegram/chats', ['token' => self::TOKEN])->assertOk()->json('chats');
        $this->assertSame([['id' => '-1001234567890', 'name' => 'NOC Team', 'type' => 'supergroup'], ['id' => '555', 'name' => 'Ana', 'type' => 'private']], $chats);

        $this->postJson('/settings/telegram/test', ['token' => self::TOKEN, 'chats' => '777'])->assertStatus(422)
            ->assertJsonFragment(['message' => 'Chat 777 not found. Send /start to the bot from that chat (or add the bot to the group) first, then use Find chats.']);
    }

    /* ---------- Email and the scheduled report ---------- */

    public function test_mail_server_from_settings_is_used_and_password_kept_encrypted(): void
    {
        $this->actingAs(User::factory()->create());
        $this->put('/settings/mail', [
            'host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'noc@example.com',
            'password' => 'app-password-123', 'from_address' => 'noc@example.com', 'from_name' => 'Muntinlupa WiFi',
        ])->assertRedirect(route('settings').'#mail');
        $this->assertNotSame('app-password-123', Setting::read('mail.password'));

        app(NotifySettings::class)->applyMail();
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame(['smtp.gmail.com', 587, 'noc@example.com', 'app-password-123'],
            [config('mail.mailers.smtp.host'), config('mail.mailers.smtp.port'), config('mail.mailers.smtp.username'), config('mail.mailers.smtp.password')]);
        $this->assertSame(['address' => 'noc@example.com', 'name' => 'Muntinlupa WiFi'], config('mail.from'));

        Mail::fake();
        $this->postJson('/settings/mail/test', ['to' => 'me@example.com'])->assertOk();
        $this->postJson('/settings/mail/test', ['to' => 'not-an-email'])->assertStatus(422);
    }

    public function test_daily_report_is_sent_once_at_its_time_with_the_pdf(): void
    {
        Mail::fake();
        $this->telegramOn(['report']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $this->router(['name' => 'TUN-07', 'link_status' => 'offline', 'last_seen_at' => now()->subHours(3), 'location' => 'Tunasan hall']);
        app(NotifySettings::class)->saveReport(['enabled' => true, 'recipients' => ['noc@example.com', 'head@example.com'],
            'frequency' => 'daily', 'time' => '08:00', 'aps' => true]);
        $report = app(ScheduledReport::class);
        $report->markSent(Carbon::parse('2026-10-01 08:00', 'Asia/Manila')); // yesterday's went out

        $this->assertTrue($report->runIfDue());   // 08:30: today's 08:00 is due
        $this->assertFalse($report->runIfDue());  // not twice

        Mail::assertSent(NetworkReportMail::class, function (NetworkReportMail $m) {
            $html = $m->render();

            return $m->hasTo('noc@example.com') && $m->hasTo('head@example.com')
                && $m->envelope()->subject === 'Daily network report: Oct 2, 2026 | Public WiFi Control'
                && count($m->attachments()) === 1
                && str_contains($html, '1 device is down right now') && str_contains($html, 'TUN-07') && str_contains($html, 'Tunasan hall')
                && str_contains($html, 'Uplink Integrated Solutions Inc.');
        });
        // Multipart upload: the PDF and a caption with the headline numbers
        Http::assertSent(function (HttpRequest $r) {
            $parts = collect($r->data())->keyBy('name');

            return str_contains($r->url(), '/sendDocument') && str_contains($parts['caption']['contents'] ?? '', 'Network report')
                && str_ends_with($parts['document']['filename'] ?? '', '.pdf');
        });
        $this->assertSame('Last 24 hours report sent to 2 addresses and to Telegram', SystemEvent::where('kind', 'report')->sole()->title);

        // Tomorrow 07:59: not yet; 08:00: due again
        Carbon::setTestNow(Carbon::parse('2026-10-03 07:59', 'Asia/Manila'));
        $this->assertFalse($report->runIfDue());
        Carbon::setTestNow(Carbon::parse('2026-10-03 08:00', 'Asia/Manila'));
        $this->assertTrue($report->runIfDue());
    }

    public function test_weekly_and_monthly_slots_and_a_late_server_skips_instead_of_sending_hours_late(): void
    {
        $notify = app(NotifySettings::class);
        $report = app(ScheduledReport::class);

        // Friday Oct 2, 08:30. Weekly on Monday 07:00 -> last was Mon Sep 28; next Mon Oct 5
        $notify->saveReport(['enabled' => true, 'recipients' => ['a@example.com'], 'frequency' => 'weekly', 'time' => '07:00', 'weekday' => 1]);
        $this->assertSame('2026-09-28 07:00', $report->lastSlot()->format('Y-m-d H:i'));
        $this->assertSame('2026-10-05 07:00', $report->nextSlot()->format('Y-m-d H:i'));

        // Monthly on the 15th at 18:00 -> last Sep 15
        $notify->saveReport(['enabled' => true, 'recipients' => ['a@example.com'], 'frequency' => 'monthly', 'time' => '18:00', 'monthday' => 15]);
        $this->assertSame('2026-09-15 18:00', $report->lastSlot()->format('Y-m-d H:i'));

        // Daily 01:00, server was off until 08:30: 7.5 h late -> skipped
        Mail::fake();
        $notify->saveReport(['enabled' => true, 'recipients' => ['a@example.com'], 'frequency' => 'daily', 'time' => '01:00']);
        $this->assertFalse($report->runIfDue());
        Mail::assertNothingSent();
    }

    public function test_schedule_settings_page_and_send_now(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->create());

        $this->put('/settings/report', ['enabled' => '1', 'recipients' => "noc@example.com\nbad", 'frequency' => 'daily', 'time' => '08:00'])
            ->assertSessionHasErrors(['recipients.1'], null, 'report');
        $this->put('/settings/report', ['enabled' => '1', 'recipients' => '', 'frequency' => 'daily', 'time' => '08:00'])
            ->assertSessionHasErrors(['recipients'], null, 'report');

        $this->put('/settings/report', ['enabled' => '1', 'recipients' => 'noc@example.com; head@example.com', 'frequency' => 'weekly',
            'time' => '07:30', 'weekday' => 1, 'aps' => '1'])
            ->assertRedirect(route('settings').'#report')->assertSessionHas('status', 'Automatic report on. Next one: Mon, Oct 5, 2026 at 7:30 AM.');
        // Saving doesn't fire the one that just passed
        $this->assertFalse(app(ScheduledReport::class)->runIfDue());

        $this->get('/settings')->assertOk()->assertSee('Next report:')->assertSee('Monday, October 5, 2026 at 7:30 AM');

        $this->post('/settings/report/send')->assertRedirect(route('settings').'#report')->assertSessionHas('status', 'Report sent to 2 email addresses.');
        Mail::assertSent(NetworkReportMail::class, fn ($m) => $m->envelope()->subject === 'Weekly network report: Oct 2, 2026 | Public WiFi Control');
    }

    /* ---------- Picture report to Telegram ---------- */

    public function test_am_and_pm_status_pictures_go_to_telegram_at_their_times(): void
    {
        $this->telegramOn(['router']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $this->router(['name' => 'TUN-07', 'link_status' => 'offline', 'last_seen_at' => now()->subHour()]);
        $pic = app(TelegramPictureReport::class);
        $pic->save(true, '08:00', '20:00', true, true, 'day');  // saved at 08:30: the 08:00 one is not sent late
        $this->assertSame(['08:00', '20:00'], $pic->config()['times']);
        $this->assertSame('2026-10-02 20:00', $pic->nextSlot()->format('Y-m-d H:i'));
        $this->assertFalse($pic->runIfDue());

        Carbon::setTestNow(Carbon::parse('2026-10-02 20:00', 'Asia/Manila'));
        $this->assertTrue($pic->runIfDue());
        $this->assertFalse($pic->runIfDue());  // once per time
        Carbon::setTestNow(Carbon::parse('2026-10-03 07:59', 'Asia/Manila'));
        $this->assertFalse($pic->runIfDue());
        Carbon::setTestNow(Carbon::parse('2026-10-03 08:00', 'Asia/Manila'));
        $this->assertTrue($pic->runIfDue());

        Http::assertSentCount(4); // AM and PM x 2 chats
        $this->assertSame(['PM report sent to Telegram: critical, 1 offline', 'AM report sent to Telegram: critical, 1 offline'],
            SystemEvent::where('kind', 'report')->orderBy('id')->pluck('title')->all());
        Http::assertSent(function (HttpRequest $r) {
            $parts = collect($r->data())->keyBy('name');
            $png = $parts['photo']['contents'] ?? '';

            return str_contains($r->url(), '/sendPhoto') && str_starts_with($png, "\x89PNG")
                && getimagesizefromstring($png)[0] === 1080
                && str_contains($parts['caption']['contents'] ?? '', '<b>CRITICAL</b>') && str_contains($parts['caption']['contents'] ?? '', 'Routers 0/1')
                && str_contains($parts['caption']['contents'] ?? '', '1. Router TUN-07')
                && str_contains($parts['caption']['contents'] ?? '', '<b>PM report</b>');
        });
    }

    public function test_only_the_pm_report_when_am_is_off(): void
    {
        $this->telegramOn([]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $pic = app(TelegramPictureReport::class);
        $pic->save(true, '07:30', '18:45', false, true, 'day');
        $this->assertSame(['18:45'], $pic->config()['times']);

        Carbon::setTestNow(Carbon::parse('2026-10-03 07:30', 'Asia/Manila'));
        $this->assertFalse($pic->runIfDue());
        Carbon::setTestNow(Carbon::parse('2026-10-03 18:45', 'Asia/Manila'));
        $this->assertTrue($pic->runIfDue());
        Http::assertSentCount(2);
    }

    public function test_tall_picture_falls_back_to_a_file(): void
    {
        $this->telegramOn([]);
        Http::fake([
            'api.telegram.org/*/sendPhoto' => Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: PHOTO_INVALID_DIMENSIONS'], 400),
            'api.telegram.org/*/sendDocument' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $this->assertSame(2, app(Telegram::class)->sendPhoto("\x89PNG...", 'r.png', 'caption'));
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/sendDocument'));
    }

    public function test_picture_settings_preview_and_send_now(): void
    {
        $this->actingAs(User::factory()->create());

        // Telegram not set up yet
        $form = ['enabled' => '1', 'am_on' => '1', 'am_time' => '07:30', 'pm_on' => '1', 'pm_time' => '19:00', 'period' => 'week'];
        $this->put('/settings/telegram/picture', $form)->assertSessionHasErrors(['enabled'], null, 'picture');

        $this->telegramOn([]);
        // AM must be before noon, PM from noon
        $this->put('/settings/telegram/picture', ['am_time' => '13:00', 'pm_time' => '09:00'] + $form)
            ->assertSessionHasErrors(['am_time', 'pm_time'], null, 'picture');
        $this->put('/settings/telegram/picture', ['am_on' => '0', 'pm_on' => '0'] + $form)
            ->assertSessionHasErrors(['enabled'], null, 'picture');
        $this->put('/settings/telegram/picture', $form)
            ->assertRedirect(route('settings').'#telegram')
            ->assertSessionHas('status', 'Status pictures on: AM at 07:30 and PM at 19:00 every day. Next one: Fri, Oct 2 at 19:00.');
        $this->get('/settings')->assertOk()->assertSee('AM report')->assertSee('Every day: AM at 07:30 and PM at 19:00.')
            ->assertSee('value="07:30"', false)->assertSee('value="19:00"', false);

        $png = $this->get('/settings/telegram/picture.png')->assertOk()->assertHeader('Content-Type', 'image/png')->getContent();
        $this->assertSame(1080, getimagesizefromstring($png)[0]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $this->post('/settings/telegram/picture/send')->assertSessionHas('status', 'Picture report sent to 2 Telegram chats.');
        $this->assertSame('AM report sent to Telegram: normal', SystemEvent::where('kind', 'report')->latest('id')->first()->title); // sent at 08:30
    }

    /* ---------- Helpers ---------- */

    private function telegramOn(array $events): void
    {
        app(NotifySettings::class)->saveTelegram(true, self::TOKEN, ['-1001234567890', '555'], $events);
    }

    private function router(array $attrs = []): MikrotikRouter
    {
        static $n = 0;
        $n++;

        return MikrotikRouter::forceCreate($attrs + [
            'name' => 'site-'.$n, 'host' => '192.0.2.'.$n, 'api_port' => 8728, 'username' => 'api', 'password' => 'x',
            'model' => 'hex', 'port_roles' => [], 'wan_interface' => 'ether1', 'vlans' => [],
        ] + app(SubnetAllocator::class)->next());
    }
}
