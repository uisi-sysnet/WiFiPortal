<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Barangay;
use App\Models\MikrotikRouter;
use App\Models\NetworkDevice;
use App\Models\User;
use App\Services\Mikrotik\SubnetAllocator;
use App\Services\Snmp\SnmpProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/** User activity log: what people did, each with a Log ID; not page views, not the pollers. */
class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Barangay $pob;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        // No real SNMP: every device "does not answer"
        $this->app->instance(SnmpProbe::class, new class extends SnmpProbe
        {
            public function check(NetworkDevice $device): array
            {
                throw new RuntimeException('No answer.');
            }
        });
        $this->admin = User::factory()->create(['name' => 'Maria Santos']);
        $this->pob = Barangay::create(['name' => 'Poblacion']);
    }

    public function test_adding_editing_and_deleting_a_device_is_logged_with_what_changed(): void
    {
        $this->actingAs($this->admin);
        $form = [
            'name' => 'POB-AP-01', 'brand' => 'Ubiquiti', 'barangay_id' => $this->pob->id, 'location' => 'Plaza',
            'latitude' => 14.38, 'longitude' => 121.04, 'host' => '172.20.0.11', 'snmp_port' => 161, 'snmp_version' => '2c', 'community' => 'secret-community',
        ];

        $this->post('/access-points', $form)->assertRedirect();
        $ap = NetworkDevice::sole();
        $this->put("/devices/{$ap->id}", ['name' => 'POB-AP-07', 'location' => 'Near the church', 'community' => 'new-community'] + $form)->assertRedirect();
        $this->delete("/devices/{$ap->id}")->assertRedirect();

        $logs = ActivityLog::orderBy('id')->get();
        $this->assertSame(['created', 'updated', 'deleted'], $logs->pluck('action')->all());
        $this->assertSame(['Added access point POB-AP-01', 'Edited access point POB-AP-07', 'Deleted access point POB-AP-07'], $logs->pluck('description')->all());
        $this->assertSame(['Maria Santos', 'admin', $this->admin->id], [$logs[1]->user_name, $logs[1]->user_role, $logs[1]->user_id]);
        $this->assertSame(['access point', $ap->id, 'POB-AP-07'], [$logs[1]->subject_type, $logs[1]->subject_id, $logs[1]->subject_label]);

        // Only what a person changed; secrets masked; the offline status from the SNMP check is not an "edit"
        $this->assertSame(['name' => ['POB-AP-01', 'POB-AP-07'], 'location' => ['Plaza', 'Near the church'], 'community' => ['(hidden)', '(changed)']],
            $logs[1]->changes);
        $this->assertSame([null, 'Ubiquiti'], $logs[0]->changes['brand']);
        $this->assertSame([null, 'Poblacion'], $logs[0]->changes['barangay_id']); // names, not ids
        $this->assertSame([null, 'Access point'], $logs[0]->changes['type']);
        $this->assertArrayNotHasKey('community', $logs[0]->changes);
        $this->assertStringNotContainsString('secret-community', json_encode($logs->toArray()));
        $this->assertNotNull($logs[0]->ip);
    }

    public function test_bulk_delete_logs_each_device_and_a_check_is_logged(): void
    {
        $a = $this->device('SW-1', 'switch');   // set up before signing in: not part of the test
        $b = $this->device('SW-2', 'switch');
        $this->actingAs($this->admin);

        $this->post("/devices/{$a->id}/check")->assertRedirect();
        $this->post('/devices/bulk', ['action' => 'delete', 'ids' => [$a->id, $b->id]])->assertRedirect();

        $this->assertSame(['Checked switch SW-1 now: offline', 'Deleted switch SW-1', 'Deleted switch SW-2'],
            ActivityLog::orderBy('id')->pluck('description')->all());
    }

    public function test_system_changes_and_page_views_are_not_logged(): void
    {
        $ap = $this->device('AP-1');                       // nobody signed in: not logged
        $ap->forceFill(['status' => 'online', 'clients' => 12, 'last_seen_at' => now()])->save();

        $this->actingAs($this->admin);
        $ap->forceFill(['status' => 'offline', 'failures' => 3])->save(); // a poll result, even while someone is signed in
        foreach (['/dashboard', '/users', '/settings', '/radius', '/access-points', '/logs', '/logs?tab=events'] as $url) {
            $this->get($url)->assertOk();
        }

        $this->assertSame(0, ActivityLog::count());
    }

    public function test_router_configuration_and_settings_changes_are_logged(): void
    {
        $this->actingAs($this->admin);
        $router = MikrotikRouter::create(['name' => 'MUN-CORE-01', 'host' => '10.10.0.1', 'api_port' => 8728, 'username' => 'api', 'password' => 'router-pass',
            'model' => 'ccr2216', 'port_roles' => [], 'wan_interface' => 'ether1', 'vlans' => []] + app(SubnetAllocator::class)->next());
        $this->post("/routers/{$router->id}/provision")->assertRedirect();

        $this->put('/settings/validity', ['resident_amount' => 30, 'resident_unit' => 'days', 'visitor_amount' => 24, 'visitor_unit' => 'hours',
            'student_amount' => 24, 'student_unit' => 'hours'])->assertRedirect();
        $this->put('/settings/telegram', ['enabled' => '1', 'token' => '123456789:AAHfakeTokenForTests_abcdefghijklmnop', 'chats' => '5551234', 'events' => ['router']])->assertSessionHasNoErrors();
        $this->put('/settings/validity', ['resident_amount' => 30, 'resident_unit' => 'days', 'visitor_amount' => 24, 'visitor_unit' => 'hours',
            'student_amount' => 24, 'student_unit' => 'hours'])->assertRedirect(); // nothing changed: nothing logged

        $logs = ActivityLog::orderBy('id')->get()->keyBy('description');
        $this->assertArrayNotHasKey('password', $logs['Added router MUN-CORE-01']->changes);
        $this->assertArrayNotHasKey('mgmt_subnet', $logs['Added router MUN-CORE-01']->changes); // assigned by the system
        $this->assertSame('provisioned', $logs['Re-applied the configuration of router MUN-CORE-01']->action);
        $this->assertSame(['Residents (citizens)' => ['24 hours', '30 days']], $logs['Changed internet access time settings']->changes);
        $tg = $logs['Changed Telegram settings']->changes;
        $this->assertSame(['(hidden)', '(changed)'], $tg['token']);
        $this->assertSame(['no', 'yes'], $tg['enabled']);
        $this->assertStringNotContainsString('AAHfakeToken', json_encode($tg));
        $this->assertSame(1, ActivityLog::where('description', 'Changed internet access time settings')->count());
    }

    public function test_generating_reports_is_logged(): void
    {
        $this->actingAs(User::factory()->role('user')->create(['name' => 'Juan Dela Cruz']));

        $this->get('/users/report.pdf?range=day&aps=1')->assertOk();
        $this->get('/users/report.pdf?range=week&format=png')->assertOk();
        $this->get('/users/access-points.csv?range=month')->assertOk()->streamedContent();

        $logs = ActivityLog::orderBy('id')->get();
        $this->assertSame(['generated', 'generated', 'generated'], $logs->pluck('action')->all());
        $this->assertSame('Generated the users report: PDF, last 24 hours', $logs[0]->description);
        $this->assertSame([null, 'yes'], $logs[0]->changes['clients per access point']);
        $this->assertStringStartsWith('UR-', $logs[0]->changes['reference'][1]);
        $this->assertSame('Generated the users report: image (PNG), last 7 days', $logs[1]->description);
        $this->assertSame('Downloaded clients per access point (CSV): last 30 days', $logs[2]->description);
        $this->assertSame(['Juan Dela Cruz', 'user'], [$logs[2]->user_name, $logs[2]->user_role]);
    }

    public function test_sign_in_sign_out_and_failed_sign_in(): void
    {
        $user = User::factory()->create(['email' => 'juan@uplinkph.net', 'password' => 'a-strong-password-1', 'name' => 'Juan Dela Cruz']);

        $this->post('/login', ['email' => 'juan@uplinkph.net', 'password' => 'wrong'])->assertRedirect();
        $this->post('/login', ['email' => 'juan@uplinkph.net', 'password' => 'a-strong-password-1'])->assertRedirect(route('dashboard'));
        $this->post('/logout')->assertRedirect(route('login'));

        $logs = ActivityLog::orderBy('id')->get();
        $this->assertSame(['sign_in_failed', 'signed_in', 'signed_out'], $logs->pluck('action')->all());
        $this->assertSame([null, 'juan@uplinkph.net'], [$logs[0]->user_id, $logs[0]->user_name]);
        $this->assertSame([$user->id, 'Juan Dela Cruz'], [$logs[1]->user_id, $logs[1]->user_name]);
    }

    public function test_system_users_and_barangays_are_logged_without_passwords(): void
    {
        $this->actingAs($this->admin);
        $this->post('/settings/barangays', ['name' => 'Cupang'])->assertRedirect();
        $this->post('/settings/accounts', ['name' => 'Juan Dela Cruz', 'email' => 'juan@uplinkph.net', 'position' => 'Engineer', 'department' => 'IT',
            'contact' => '09171234567', 'role' => 'viewer', 'password' => 'a-strong-password-1', 'password_confirmation' => 'a-strong-password-1'])->assertRedirect();
        $juan = User::where('email', 'juan@uplinkph.net')->sole();
        $this->put("/settings/accounts/{$juan->id}", ['name' => 'Juan Dela Cruz', 'email' => 'juan@uplinkph.net', 'position' => 'Engineer', 'department' => 'IT',
            'contact' => '09171234567', 'role' => 'user', 'password' => 'another-password-2', 'password_confirmation' => 'another-password-2'])->assertRedirect();
        $this->delete("/settings/accounts/{$juan->id}")->assertRedirect();

        $logs = ActivityLog::orderBy('id')->get();
        $this->assertSame(['Added barangay Cupang', 'Added system user Juan Dela Cruz', 'Edited system user Juan Dela Cruz', 'Deleted system user Juan Dela Cruz'],
            $logs->pluck('description')->all());
        $this->assertEquals(['role' => ['viewer', 'user'], 'password' => ['(hidden)', '(changed)']], $logs[2]->changes);
        $this->assertStringNotContainsString('a-strong-password-1', json_encode($logs->toArray()));
        $this->assertStringNotContainsString('another-password-2', json_encode($logs->toArray()));
    }

    public function test_logs_page_shows_log_ids_filters_and_exports(): void
    {
        $juan = User::factory()->role('user')->create(['name' => 'Juan Dela Cruz']);
        $this->actingAs($this->admin);
        $this->post('/settings/barangays', ['name' => 'Cupang']);
        $this->post('/settings/barangays', ['name' => 'Sucat']);
        $first = ActivityLog::orderBy('id')->first();
        ActivityLog::record('generated', 'Generated the users report: PDF, last 24 hours', ['type' => 'report'], null, $juan);

        $this->get('/logs')->assertOk()->assertSee('User activity')->assertSee('#'.$first->id)
            ->assertSeeInOrder(['Generated the users report', 'Added barangay Sucat', 'Added barangay Cupang']);
        $this->get('/logs?user='.$juan->id)->assertOk()->assertSee('Generated the users report')->assertDontSee('Added barangay Sucat');
        $this->get('/logs?action=created&q=cupang')->assertOk()->assertSee('Added barangay Cupang')->assertDontSee('Added barangay Sucat');
        $this->get('/logs?q=%23'.$first->id)->assertOk()->assertSee('Added barangay Cupang')->assertDontSee('Added barangay Sucat');
        $this->get('/logs?from=2026-10-05&to=2026-10-01')->assertSessionHasErrors('to');

        $csv = $this->get('/logs/activity.csv?action=created')->assertOk()->streamedContent();
        $lines = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
        $this->assertSame('Log ID', $lines[0][0]);
        $this->assertCount(3, $lines); // header + 2 barangays
        $this->assertSame((string) $first->id, $lines[2][0]);
        $this->assertSame('Exported the user activity log (CSV)', ActivityLog::latest('id')->first()->description);

        // Users and viewers can't open the log
        $this->actingAs($juan)->get('/logs')->assertRedirect(route('dashboard'));
    }

    public function test_entries_stay_readable_after_the_user_is_deleted(): void
    {
        $juan = User::factory()->create(['name' => 'Juan Dela Cruz', 'role' => 'admin']);
        $this->actingAs($juan)->post('/settings/barangays', ['name' => 'Cupang']);
        $this->actingAs($this->admin)->delete("/settings/accounts/{$juan->id}");

        $log = ActivityLog::where('description', 'Added barangay Cupang')->sole();
        $this->assertSame([null, 'Juan Dela Cruz'], [$log->user_id, $log->user_name]);
        $this->get('/logs')->assertSee('Juan Dela Cruz')->assertSee('(account deleted)');
    }

    private function device(string $name, string $type = 'ap'): NetworkDevice
    {
        return NetworkDevice::forceCreate(['type' => $type, 'name' => $name, 'host' => '172.20.0.'.(NetworkDevice::count() + 2), 'snmp_port' => 161,
            'snmp_version' => '2c', 'community' => 'public', 'barangay_id' => $this->pob->id, 'status' => 'unknown']);
    }
}
