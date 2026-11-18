<?php

namespace Tests\Feature;

use App\Models\Theme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_the_users_theme_preference_can_be_updated(): void
    {
        $user = User::factory()->create();
        $theme = Theme::create(['name' => 'Custom', 'slug' => 'custom-theme', 'css' => 'body {}']);

        $response = $this->actingAs($user)->patch('/profile/theme', [
            'theme_id' => $theme->id,
        ]);

        $response->assertRedirect(route('profile.edit'));
        $response->assertSessionHas('status', 'theme-updated');
        $this->assertSame($theme->id, $user->fresh()->theme_id);
    }

    public function test_the_theme_preference_can_be_cleared(): void
    {
        $theme = Theme::create(['name' => 'Custom', 'slug' => 'custom-theme', 'css' => 'body {}']);
        $user = User::factory()->create(['theme_id' => $theme->id]);

        $response = $this->actingAs($user)->patch('/profile/theme', [
            'theme_id' => '',
        ]);

        $response->assertRedirect(route('profile.edit'));
        $this->assertNull($user->fresh()->theme_id);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }
}
