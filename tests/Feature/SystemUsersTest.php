<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Roles (administrator, user, viewer) and Settings > System users. */
class SystemUsersTest extends TestCase
{
    use RefreshDatabase;

    /* ---------- What each role can open ---------- */

    public function test_viewer_sees_only_the_dashboard(): void
    {
        $this->actingAs(User::factory()->role('viewer')->create());

        $this->get('/dashboard')->assertOk()->assertDontSee(route('users.index'), false)->assertDontSee(route('radius'), false)
            ->assertDontSee(route('settings'), false);
        $this->getJson('/dashboard/map-data')->assertOk();
        $this->get('/dashboard/map')->assertOk();

        foreach (['/users', '/guests', '/users/report.pdf?range=day', '/users/heatmap', '/settings', '/radius', '/logs', '/routers', '/access-points', '/splash'] as $url) {
            $this->get($url)->assertRedirect(route('dashboard'));
        }
        $this->get('/settings')->assertSessionHas('denied');
        $this->getJson('/users')->assertForbidden();
        $this->post('/settings/accounts', [])->assertRedirect(route('dashboard'));
    }

    public function test_user_gets_the_users_page_and_report_downloads_but_no_admin_pages(): void
    {
        $this->actingAs(User::factory()->role('user')->create());

        $this->get('/dashboard')->assertOk()->assertSee(route('users.index'), false)->assertDontSee(route('radius'), false);
        $this->get('/users')->assertOk()->assertDontSee(route('settings'), false);
        $this->get('/users/report.pdf?range=day')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get('/users/heatmap')->assertOk();
        $this->get('/users/access-points.csv')->assertOk();
        $this->get('/guests')->assertOk();

        foreach (['/settings', '/radius', '/logs', '/routers', '/access-points', '/switches', '/splash'] as $url) {
            $this->get($url)->assertRedirect(route('dashboard'));
        }
    }

    public function test_administrator_opens_everything(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        foreach (['/dashboard', '/users', '/guests', '/settings', '/radius', '/logs', '/routers', '/access-points', '/splash/login', '/splash/advertisement'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/dashboard')->assertSee(route('radius'), false)->assertSee(route('settings'), false);
    }

    /* ---------- Settings > System users ---------- */

    public function test_add_a_user_with_full_name_position_department_and_contact(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Ana Admin']));
        $form = [
            'name' => 'Juan Dela Cruz', 'email' => 'juan@uplinkph.net', 'position' => 'Network Engineer',
            'department' => 'System & Network Department', 'contact' => '0917 123 4567', 'role' => 'user',
            'password' => 'a-strong-password-1', 'password_confirmation' => 'a-strong-password-1',
        ];

        $this->post('/settings/accounts', ['name' => '', 'position' => '', 'department' => '', 'contact' => 'call me', 'role' => 'boss', 'password' => 'short'])
            ->assertSessionHasErrors(['name', 'email', 'position', 'department', 'contact', 'role', 'password'], null, 'newAccount');
        $this->post('/settings/accounts', ['password_confirmation' => 'different-password-1'] + $form)
            ->assertSessionHasErrors(['password'], null, 'newAccount');

        $this->post('/settings/accounts', $form)->assertRedirect(route('settings').'#accounts')
            ->assertSessionHas('status', 'Juan Dela Cruz added as User. They can sign in with juan@uplinkph.net.');
        $juan = User::where('email', 'juan@uplinkph.net')->sole();
        $this->assertSame(['Network Engineer', 'System & Network Department', '0917 123 4567', 'user'], [$juan->position, $juan->department, $juan->contact, $juan->role]);
        $this->assertTrue(Hash::check('a-strong-password-1', $juan->password));

        $this->post('/settings/accounts', $form)->assertSessionHasErrors(['email'], null, 'newAccount'); // same email twice

        $this->get('/settings')->assertOk()->assertSee('System users')->assertSee('Juan Dela Cruz')->assertSee('Network Engineer');

        // The new account can sign in
        auth()->logout();
        $this->post('/login', ['email' => 'juan@uplinkph.net', 'password' => 'a-strong-password-1'])->assertRedirect(route('dashboard'));
        $this->assertNotNull($juan->fresh()->last_login_at);
    }

    public function test_edit_role_and_password_and_keep_the_password_when_blank(): void
    {
        $this->actingAs(User::factory()->create());
        $juan = User::factory()->role('viewer')->create(['password' => 'old-password-123']);
        $form = ['name' => $juan->name, 'email' => $juan->email, 'position' => 'Technician', 'department' => 'IT', 'contact' => 'juan@example.com', 'role' => 'user'];

        $this->put("/settings/accounts/{$juan->id}", $form + ['password' => ''])->assertSessionHasNoErrors();
        $this->assertSame(['user', 'Technician'], [$juan->fresh()->role, $juan->fresh()->position]);
        $this->assertTrue(Hash::check('old-password-123', $juan->fresh()->password));

        $this->put("/settings/accounts/{$juan->id}", $form + ['password' => 'new-password-456', 'password_confirmation' => 'new-password-456'])
            ->assertSessionHas('status', $juan->name.' saved, with the new password.');
        $this->assertTrue(Hash::check('new-password-456', $juan->fresh()->password));
    }

    public function test_the_last_administrator_and_yourself_are_protected(): void
    {
        $me = User::factory()->create(['name' => 'Ana Admin']);
        $this->actingAs($me);
        $other = User::factory()->role('viewer')->create(['name' => 'Pedro Viewer']);
        $self = ['name' => $me->name, 'email' => $me->email, 'position' => 'Head', 'department' => 'IT', 'contact' => '09171234567'];

        // Can't delete or demote yourself (and here you are also the only administrator)
        $this->delete("/settings/accounts/{$me->id}")->assertSessionHas('error');
        $this->put("/settings/accounts/{$me->id}", $self + ['role' => 'viewer'])->assertSessionHas('error');
        $this->assertSame('admin', $me->fresh()->role);

        // With a second administrator, the first can be deleted by the second
        $other->update(['role' => 'admin']);
        $this->actingAs($other);
        $this->delete("/settings/accounts/{$me->id}")->assertSessionHas('status', 'Ana Admin deleted. They can no longer sign in.');
        $this->assertNull($me->fresh());

        // Pedro is now the only administrator: he can't lower his own role, and can't delete himself
        $self = ['name' => $other->name, 'email' => $other->email, 'position' => 'Head', 'department' => 'IT', 'contact' => '09171234567'];
        $this->put("/settings/accounts/{$other->id}", $self + ['role' => 'user'])->assertSessionHas('error');
        $this->delete("/settings/accounts/{$other->id}")->assertSessionHas('error');
        $this->assertSame('admin', $other->fresh()->role);
    }

    public function test_only_administrator_left_cannot_be_demoted_by_another_page_route(): void
    {
        $admin = User::factory()->create();
        $boss = User::factory()->create(['role' => 'admin']);
        $this->actingAs($boss);

        // Demote the other administrator: fine, one is left
        $this->put("/settings/accounts/{$admin->id}", ['name' => $admin->name, 'email' => $admin->email, 'position' => 'X', 'department' => 'Y',
            'contact' => '09171234567', 'role' => 'user'])->assertSessionHas('status');
        $this->assertSame('user', $admin->fresh()->role);
        $this->assertSame(1, User::where('role', 'admin')->count());
    }

    /* ---------- Reports ---------- */

    public function test_report_shows_full_name_position_and_department(): void
    {
        $this->actingAs(User::factory()->role('user')->create([
            'name' => 'Juan Dela Cruz', 'position' => 'Network Engineer', 'department' => 'System & Network Department',
        ]));

        $this->get('/users/report.pdf?range=day&format=png')->assertOk()
            ->assertSee('at the request of Juan Dela Cruz, Network Engineer, System &amp; Network Department', false);
    }
}
