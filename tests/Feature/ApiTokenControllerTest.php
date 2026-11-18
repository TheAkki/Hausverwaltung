<?php

// KI-generiert

namespace Tests\Feature;

use App\Http\Controllers\ApiTokenController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTokenControllerTest extends TestCase
{
    use RefreshDatabase;

    // KI-generiert
    public function test_a_created_token_is_shown_once_and_authenticates_the_api(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('profile.api-tokens.store'), ['token_name' => 'Import-Script']);

        $response->assertRedirect(route('profile.edit'))->assertSessionHas('status', ApiTokenController::STATUS_CREATED);
        $plain = session(ApiTokenController::SESSION_PLAIN_TOKEN);
        $this->assertSame('Import-Script', $user->tokens()->sole()->name);

        $this->followRedirects($response)->assertSee($plain)->assertSee('Import-Script');
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->getJson(route('api.user'))->assertOk()->assertJsonPath('id', $user->id);
    }

    // KI-generiert
    public function test_the_profile_lists_tokens_with_their_last_use(): void
    {
        $user = User::factory()->create();
        $user->createToken('Laptop');
        $user->tokens()->update(['last_used_at' => '2026-03-04 05:06:00']);

        $this->actingAs($user)->get(route('profile.edit'))->assertOk()->assertSee('Laptop')->assertSee('04.03.2026 05:06');
    }

    // KI-generiert
    public function test_a_token_needs_a_name(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('profile.api-tokens.store'), ['token_name' => ''])
            ->assertSessionHasErrorsIn('apiToken', 'token_name');
        $this->assertSame(0, $user->tokens()->count());
    }

    // KI-generiert
    public function test_a_token_can_be_revoked_only_by_its_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $token = $owner->createToken('Script')->accessToken;

        $this->actingAs($other)->delete(route('profile.api-tokens.destroy', $token->id))->assertNotFound();
        $this->assertSame(1, $owner->tokens()->count());

        $this->actingAs($owner)->delete(route('profile.api-tokens.destroy', $token->id))
            ->assertRedirect(route('profile.edit'))->assertSessionHas('status', ApiTokenController::STATUS_REVOKED);
        $this->assertSame(0, $owner->tokens()->count());
        $this->actingAs($owner)->get(route('profile.edit'))->assertSee(__('Token widerrufen.'));
    }

    // KI-generiert
    public function test_guests_cannot_manage_tokens(): void
    {
        $this->post(route('profile.api-tokens.store'), ['token_name' => 'X'])->assertRedirect(route('login'));
    }
}
