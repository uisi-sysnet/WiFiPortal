<?php

namespace Tests\Feature;

use App\Models\HotspotGuest;
use App\Models\HotspotNetwork;
use App\Models\MikrotikRouter;
use App\Models\SplashPage;
use App\Models\User;
use App\Services\Mikrotik\HotspotProvisioner;
use App\Services\Mikrotik\SubnetAllocator;
use App\Services\Portal\AccessValidity;
use App\Services\Portal\GuestCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRouterOs;
use Tests\TestCase;

/** Access time per type of user, and roaming: a valid registration skips the captive portal. */
class AccessValidityTest extends TestCase
{
    use RefreshDatabase;

    private const MAC = 'AA:BB:CC:11:22:33';

    /** @var array<int, FakeRouterOs> one fake RouterOS per router id */
    private array $fakes = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['hotspot.portal_url' => 'http://portal.test', 'hotspot.radius.host' => null, 'hotspot.radius.secret' => null]);
        SplashPage::current();

        $fakes = &$this->fakes;
        $this->app->instance(HotspotProvisioner::class, new class($fakes) extends HotspotProvisioner
        {
            public function __construct(private array &$fakes) {}

            protected function makeClient(array $config): object
            {
                $router = MikrotikRouter::where('host', $config['host'])->sole();

                return $this->fakes[$router->id] ??= new FakeRouterOs;
            }
        });
    }

    /* ---------- Settings ---------- */

    public function test_admin_sets_access_time_per_type_of_user(): void
    {
        $this->actingAs(User::factory()->create());

        $this->put('/settings/validity', [
            'resident_amount' => 30, 'resident_unit' => 'days',
            'visitor_amount' => 12, 'visitor_unit' => 'hours',
            'student_amount' => 7, 'student_unit' => 'days',
        ])->assertRedirect(route('settings').'#validity');

        $v = app(AccessValidity::class);
        $this->assertSame(720, $v->hours('resident'));
        $this->assertSame(12, $v->hours('visitor'));
        $this->assertSame('7 days', $v->label('student'));
        $this->get('/settings')->assertOk()->assertSee('Internet access time');

        // At most a year
        $this->put('/settings/validity', [
            'resident_amount' => 400, 'resident_unit' => 'days',
            'visitor_amount' => 0, 'visitor_unit' => 'hours',
            'student_amount' => 7, 'student_unit' => 'weeks',
        ])->assertSessionHasErrors(['resident_amount', 'visitor_amount', 'student_unit'], null, 'validity');
        $this->assertSame(720, $v->hours('resident'));
    }

    public function test_default_is_the_credential_hours_setting(): void
    {
        config(['hotspot.credential_hours' => 24]);
        $this->assertSame(['amount' => 24, 'unit' => 'hours'], app(AccessValidity::class)->get('student'));
    }

    /* ---------- Registration ---------- */

    public function test_each_type_of_user_gets_its_own_access_time(): void
    {
        $v = app(AccessValidity::class);
        $v->set('resident', 30, 'days');
        $v->set('visitor', 6, 'hours');
        $v->set('student', 5, 'days');
        $n = $this->network();

        $this->arrive($n, 'AA:BB:CC:00:00:01');
        $this->post("/portal/{$n->portal_code}", ['category' => 'student', 'name' => 'Ana Reyes', 'school' => 'Muntinlupa  Science High School',
            'student_number' => '2026-00123', 'accept' => '1'])->assertRedirect("/portal/{$n->portal_code}/welcome");
        $this->flushSession();
        $this->arrive($n, 'AA:BB:CC:00:00:02');
        $this->post("/portal/{$n->portal_code}", ['category' => 'visitor', 'name' => 'Juan Dela Cruz', 'contact' => '09171234567', 'accept' => '1'])
            ->assertRedirect();
        $this->flushSession();
        $this->arrive($n, 'AA:BB:CC:00:00:03');
        $this->post("/portal/{$n->portal_code}", ['resident' => '1', 'citizen_number' => '1234-5678', 'accept' => '1'])->assertRedirect(); // older login page

        $student = HotspotGuest::where('mac', 'AA:BB:CC:00:00:01')->sole();
        $this->assertSame('student', $student->category);
        $this->assertSame('Muntinlupa Science High School', $student->school);
        $this->assertSame('2026-00123', $student->student_number);
        $this->assertNull($student->contact);
        $this->assertEqualsWithDelta(now()->addDays(5)->getTimestamp(), $student->expires_at->getTimestamp(), 5);
        $this->assertNotEmpty($student->password);
        $this->assertNotSame($student->password, DB::table('hotspot_guests')->where('id', $student->id)->value('password')); // encrypted at rest

        $this->assertEqualsWithDelta(now()->addHours(6)->getTimestamp(), HotspotGuest::where('mac', 'AA:BB:CC:00:00:02')->sole()->expires_at->getTimestamp(), 5);
        $resident = HotspotGuest::where('mac', 'AA:BB:CC:00:00:03')->sole();
        $this->assertSame('resident', $resident->category);
        $this->assertTrue($resident->resident);
        $this->assertEqualsWithDelta(now()->addDays(30)->getTimestamp(), $resident->expires_at->getTimestamp(), 5);
    }

    public function test_student_needs_school_and_student_id(): void
    {
        $n = $this->network();
        $this->arrive($n, self::MAC);
        $this->post("/portal/{$n->portal_code}", ['category' => 'student', 'name' => 'Ana Reyes', 'accept' => '1'])
            ->assertSessionHasErrors(['school', 'student_number']);
        $this->post("/portal/{$n->portal_code}", ['category' => 'teacher', 'name' => 'Ana Reyes', 'accept' => '1'])
            ->assertSessionHasErrors('category');
        $this->assertSame(0, HotspotGuest::count());
    }

    public function test_login_page_offers_the_three_types(): void
    {
        app(AccessValidity::class)->set('student', 3, 'days');
        $n = $this->network();
        $this->arrive($n, self::MAC);
        $this->get("/portal/{$n->portal_code}")->assertOk()
            ->assertSee('value="resident"', false)->assertSee('value="visitor"', false)->assertSee('value="student"', false)
            ->assertSee('free for 3 days', false);
    }

    /* ---------- Roaming ---------- */

    public function test_valid_phone_goes_straight_online_on_another_router(): void
    {
        $a = $this->network();
        $b = $this->network();
        $guest = $this->registered($a);

        $this->get("/portal/{$b->portal_code}?".http_build_query([
            'mac' => self::MAC, 'ip' => '10.64.0.20', 'link-login-only' => "http://{$b->gateway}/login", 'link-orig' => 'http://example.com/',
        ]))->assertRedirect('http://example.com/'); // no login page, no ads

        $fake = $this->fakes[$b->router->id];
        $this->assertNotEmpty($fake->find('/ip/hotspot/user', ['name' => $guest->username, 'mac-address' => self::MAC]));
        $login = $fake->sent('/ip/hotspot/active/login')[0];
        $this->assertSame([$guest->username, $guest->password, self::MAC, '10.64.0.20'], [$login['user'], $login['password'], $login['mac-address'], $login['ip']]);

        $guest->refresh();
        $this->assertSame(1, $guest->roams);
        $this->assertSame([$a->router->id, $b->router->id], $guest->routerIdsWithLogin());
        $this->assertNotNull($guest->last_connected_at);
    }

    public function test_router_unreachable_falls_back_to_the_phone_logging_in(): void
    {
        $n = $this->network();
        $guest = $this->registered($n, onRouter: true);

        // No IP: the server can't log the phone in, so the phone does it with the same login
        $response = $this->get("/portal/{$n->portal_code}?".http_build_query([
            'mac' => self::MAC, 'link-login-only' => "http://{$n->gateway}/login",
        ]));
        $target = $response->headers->get('Location');
        $this->assertStringStartsWith("http://{$n->gateway}/login?", $target);
        parse_str(parse_url($target, PHP_URL_QUERY), $q);
        $this->assertSame([$guest->username, $guest->password], [$q['username'], $q['password']]);
    }

    public function test_expired_unknown_or_rejected_phones_see_the_portal(): void
    {
        $n = $this->network();
        $guest = $this->registered($n);
        $go = fn (array $extra = []) => $this->get("/portal/{$n->portal_code}?".http_build_query($extra + [
            'mac' => self::MAC, 'ip' => '10.64.0.20', 'link-login-only' => "http://{$n->gateway}/login",
        ]));

        // The router just rejected a login: show the page, don't loop
        $go(['error' => 'invalid username or password'])->assertRedirect("/portal/{$n->portal_code}");
        // Another phone
        $go(['mac' => 'AA:BB:CC:99:99:99'])->assertRedirect("/portal/{$n->portal_code}");

        $guest->update(['expires_at' => now()->subMinute()]);
        $go()->assertRedirect("/portal/{$n->portal_code}");
        $this->get("/portal/{$n->portal_code}")->assertOk()->assertSee('pw-form', false);
        $this->assertSame(0, $guest->fresh()->roams);
    }

    /* ---------- RADIUS ---------- */

    public function test_radius_login_ends_at_the_validity_and_the_mac_logs_in_by_itself(): void
    {
        config(['hotspot.radius.host' => '127.0.0.1', 'hotspot.radius.secret' => 'secret', 'hotspot.radius.timezone' => 'Asia/Manila']);
        $router = $this->network()->router;
        $at = now()->setTimezone('UTC')->setDateTime(2026, 10, 9, 4, 30, 0);

        [$username, $password] = app(GuestCredentials::class)->issue($router, self::MAC, $at);

        $rows = DB::table('radcheck')->where('username', $username)->pluck('value', 'attribute')->all();
        $this->assertSame(['Cleartext-Password' => $password, 'Calling-Station-Id' => self::MAC, 'Expiration' => 'Oct 09 2026 12:30:00'], $rows);
        $mac = DB::table('radcheck')->where('username', self::MAC)->pluck('value', 'attribute')->all();
        $this->assertSame(['Cleartext-Password' => self::MAC, 'Expiration' => 'Oct 09 2026 12:30:00'], $mac);
    }

    public function test_expiring_with_radius_keeps_the_mac_login_of_a_newer_registration(): void
    {
        config(['hotspot.radius.host' => '127.0.0.1', 'hotspot.radius.secret' => 'secret']);
        $n = $this->network();
        $creds = app(GuestCredentials::class);
        $old = $this->guestFrom($n, $creds->issue($n->router, self::MAC, now()->addHour()), now()->subMinute());
        $new = $this->guestFrom($n, $creds->issue($n->router, self::MAC, now()->addDay()), now()->addDay());

        $this->assertSame(1, $creds->expire());

        $this->assertNotNull($old->fresh()->revoked_at);
        $this->assertFalse(DB::table('radcheck')->where('username', $old->username)->exists());
        $this->assertTrue(DB::table('radcheck')->where('username', $new->username)->exists());
        $this->assertTrue(DB::table('radcheck')->where('username', self::MAC)->exists());

        $new->update(['expires_at' => now()->subMinute()]);
        $creds->expire();
        $this->assertFalse(DB::table('radcheck')->where('username', self::MAC)->exists());
    }

    public function test_expiring_without_radius_cleans_every_router_the_phone_used(): void
    {
        $a = $this->network();
        $b = $this->network();
        $guest = $this->registered($a, onRouter: true);
        app(GuestCredentials::class)->roam($guest, $b->router);
        $this->fakes[$b->router->id]->menus['/ip/hotspot/active'][] = ['.id' => '*99', 'user' => $guest->username];
        $guest->update(['expires_at' => now()->subMinute()]);

        $this->assertSame(1, app(GuestCredentials::class)->expire());

        foreach ([$a, $b] as $n) {
            $this->assertSame([], $this->fakes[$n->router->id]->find('/ip/hotspot/user', ['name' => $guest->username]));
        }
        $this->assertSame([], $this->fakes[$b->router->id]->find('/ip/hotspot/active', ['user' => $guest->username]));
        $this->assertNotNull($guest->fresh()->revoked_at);
    }

    /* ---------- Users page ---------- */

    public function test_users_page_filters_and_shows_students(): void
    {
        $this->actingAs(User::factory()->create());
        $n = $this->network();
        $this->registered($n);
        HotspotGuest::create(['mikrotik_router_id' => $n->router->id, 'hotspot_network_id' => $n->id, 'category' => 'visitor',
            'name' => 'Juan Dela Cruz', 'username' => 'wifi-visitor1', 'terms_hash' => 'test', 'expires_at' => now()->addDay()]);

        $this->get('/users?type=student')->assertOk()->assertSee('Ana Reyes')->assertSee('PLMun')->assertDontSee('Juan Dela Cruz');
        $this->get('/users?q=2026-00')->assertOk()->assertSee('Ana Reyes')->assertDontSee('Juan Dela Cruz');
    }

    /* ---------- Helpers ---------- */

    private function network(): HotspotNetwork
    {
        $allocator = app(SubnetAllocator::class);
        $i = MikrotikRouter::count() + 1;
        $router = MikrotikRouter::create([
            'name' => "site-{$i}", 'host' => "192.0.2.{$i}", 'api_port' => 8728, 'username' => 'api', 'password' => 'secret',
            'model' => 'hex', 'port_roles' => ['ether1' => 'wan'], 'wan_interface' => 'ether1', 'wan_mode' => 'keep',
        ] + $allocator->next());

        return $router->hotspotNetworks()->create($allocator->nextHotspot()[0] + [
            'name' => 'Public WiFi', 'vlan_id' => 10, 'key' => HotspotNetwork::keyFor(10, true),
            'interface' => 'vlan10-hotspot', 'login_mode' => 'portal',
        ])->load('router');
    }

    private function arrive(HotspotNetwork $n, string $mac): void
    {
        $this->get("/portal/{$n->portal_code}?".http_build_query([
            'mac' => $mac, 'ip' => '10.64.0.10', 'link-login-only' => "http://{$n->gateway}/login",
        ]))->assertRedirect("/portal/{$n->portal_code}");
    }

    /** A student who registered on this network a moment ago and is still valid. */
    private function registered(HotspotNetwork $n, bool $onRouter = false): HotspotGuest
    {
        [$username, $password] = $onRouter
            ? app(GuestCredentials::class)->issue($n->router, self::MAC, now()->addDays(3))
            : ['wifi-roamer01', 'secretpass12'];

        return HotspotGuest::create([
            'mikrotik_router_id' => $n->router->id, 'hotspot_network_id' => $n->id, 'category' => 'student',
            'name' => 'Ana Reyes', 'school' => 'PLMun', 'student_number' => '2026-00123',
            'mac' => self::MAC, 'username' => $username, 'password' => $password, 'terms_hash' => 'test',
            'connected_at' => now()->subHour(), 'expires_at' => now()->addDays(3),
        ]);
    }

    private function guestFrom(HotspotNetwork $n, array $creds, $expiresAt): HotspotGuest
    {
        return HotspotGuest::create([
            'mikrotik_router_id' => $n->router->id, 'hotspot_network_id' => $n->id, 'category' => 'visitor',
            'mac' => self::MAC, 'username' => $creds[0], 'password' => $creds[1], 'terms_hash' => 'test', 'expires_at' => $expiresAt,
        ]);
    }
}
