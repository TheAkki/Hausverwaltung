<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_admin_with_empty_password_requiring_change(): void
    {
        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', 'admin@example.com')->first();

        $this->assertNotNull($admin);
        $this->assertTrue($admin->must_change_password);
        $this->assertTrue(Hash::check('', $admin->password));
        $this->assertNotNull($admin->groups()->first());
    }

    public function test_seeding_twice_does_not_reset_the_flag(): void
    {
        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', 'admin@example.com')->first();
        $admin->must_change_password = false;
        $admin->save();

        $this->seed(AdminUserSeeder::class);

        $this->assertFalse($admin->refresh()->must_change_password);
    }

    public function test_admin_can_log_in_with_an_empty_password(): void
    {
        $this->seed(AdminUserSeeder::class);

        $response = $this->post('/login', [
            'email' => 'admin@example.com',
            'password' => '',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_admin_is_redirected_to_profile_until_password_is_changed(): void
    {
        $this->seed(AdminUserSeeder::class);
        $admin = User::where('email', 'admin@example.com')->first();

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertRedirect(route('profile.edit'));
    }

    public function test_changing_the_password_clears_the_must_change_flag_and_unlocks_the_app(): void
    {
        $this->seed(AdminUserSeeder::class);
        $admin = User::where('email', 'admin@example.com')->first();

        $response = $this->actingAs($admin)->put('/password', [
            'current_password' => '',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertFalse($admin->refresh()->must_change_password);

        $this->actingAs($admin)->get('/dashboard')->assertOk();
    }
}
