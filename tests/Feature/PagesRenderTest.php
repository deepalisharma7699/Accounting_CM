<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke tests for the Blade shells: they must compile and contain the design's
 * structure. Data itself is hydrated client-side, so there is nothing else to
 * assert server-side.
 */
class PagesRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_page_renders(): void
    {
        $response = $this->get('/login')->assertOk();

        $response->assertSee('Welcome back', escape: false)
            ->assertSee('Sign in to your AI Accounting Back Office', escape: false)
            ->assertSee('id="login-form"', escape: false)
            ->assertSee('name="email"', escape: false)
            ->assertSee('name="password"', escape: false);
    }

    public function test_the_dashboard_renders_every_section(): void
    {
        $response = $this->get('/dashboard')->assertOk();

        foreach (['Quick Actions', 'Needs Your Attention', 'Continue Working', 'Notifications', 'Recent Activity'] as $section) {
            $response->assertSee($section, escape: false);
        }

        $response->assertSee('Record Transaction', escape: false)
            ->assertSee('Low Stock Items', escape: false)
            ->assertSee('Voice Transaction', escape: false)
            ->assertSee('Load earlier activity', escape: false)
            // Amounts are rupee-formatted per the design.
            ->assertSee('₹14,500', escape: false);
    }

    public function test_the_dashboard_shell_exposes_no_user_data_to_anonymous_visitors(): void
    {
        // The shell is public; every figure behind it comes from the JWT-guarded
        // API. Nothing user-specific may be baked into the HTML.
        $user = User::factory()->create(['name' => 'Harshita Sharma']);

        $this->get('/dashboard')
            ->assertOk()
            ->assertDontSee($user->name, escape: false)
            ->assertDontSee($user->email, escape: false);
    }

    public function test_the_sidebar_marks_the_current_page(): void
    {
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('aria-current="page"', escape: false);
    }

    public function test_the_root_path_redirects_to_the_dashboard(): void
    {
        $this->get('/')->assertRedirect('/dashboard');
    }

    public function test_the_users_page_renders_its_table_and_modal(): void
    {
        $response = $this->get('/users')->assertOk();

        $response->assertSee('id="users-body"', escape: false)
            ->assertSee('id="user-form"', escape: false)
            ->assertSee('id="confirm-modal"', escape: false)
            ->assertSee('data-page="users"', escape: false)
            // The "New user" button is gated on the WRITE:USERS grant.
            ->assertSee('data-requires-permission="WRITE:USERS"', escape: false);

        // Status options come from the enum, so the filter cannot drift from it.
        foreach (UserStatus::cases() as $status) {
            $response->assertSee('value="'.$status->value.'"', escape: false);
        }
    }

    public function test_the_roles_page_renders_its_table_and_permission_matrix(): void
    {
        $this->get('/roles')
            ->assertOk()
            ->assertSee('id="roles-body"', escape: false)
            ->assertSee('id="role-form"', escape: false)
            ->assertSee('id="permission-matrix"', escape: false)
            ->assertSee('id="role-slug-preview"', escape: false)
            ->assertSee('data-page="roles"', escape: false)
            ->assertSee('data-requires-permission="WRITE:ROLES"', escape: false);
    }

    public function test_the_admin_shells_expose_no_records_to_anonymous_visitors(): void
    {
        // Both pages are public shells; rows arrive from the guarded API only.
        $user = User::factory()->create(['name' => 'Harshita Sharma']);
        $role = Role::factory()->create(['name' => 'Secret Role']);

        $this->get('/users')
            ->assertOk()
            ->assertDontSee($user->email, escape: false);

        $this->get('/roles')
            ->assertOk()
            ->assertDontSee($role->name, escape: false);
    }

    public function test_every_authenticated_page_carries_a_sign_out_control(): void
    {
        // The handler is delegated from the document, so the only thing the
        // markup has to guarantee is the hook and an accessible name.
        foreach (['/dashboard', '/users', '/roles'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertSee('data-logout', escape: false)
                ->assertSee('aria-label="Sign out"', escape: false);
        }
    }

    public function test_the_admin_nav_entries_are_permission_gated(): void
    {
        // Hidden by default and only revealed client-side once /auth/me confirms
        // the grant, so a viewer without READ:USERS never sees the entry.
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('data-requires-permission="READ:USERS"', escape: false)
            ->assertSee('data-requires-permission="READ:ROLES"', escape: false);
    }
}
