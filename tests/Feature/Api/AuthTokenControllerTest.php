<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

// KI-generiert
class AuthTokenControllerTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'geheim123';

    public function test_valid_credentials_return_a_token(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $response = $this->postJson(route('api.login'), [
            'email' => strtoupper($user->email), 'password' => self::PASSWORD, 'device_name' => 'cli',
        ]);

        $response->assertCreated()->assertJsonPath('user.id', $user->id)->assertJsonStructure(['token']);
        $this->assertSame('cli', PersonalAccessToken::first()->name);
    }

    public function test_the_token_name_defaults_to_api(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $this->postJson(route('api.login'), ['email' => $user->email, 'password' => self::PASSWORD])->assertCreated();

        $this->assertSame('api', PersonalAccessToken::first()->name);
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $response = $this->postJson(route('api.login'), ['email' => $user->email, 'password' => 'falsch']);

        $response->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_the_login_is_throttled_after_too_many_failed_attempts(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        foreach (range(1, 5) as $attempt) {
            $this->postJson(route('api.login'), ['email' => $user->email, 'password' => 'falsch']);
        }

        $response = $this->postJson(route('api.login'), ['email' => $user->email, 'password' => self::PASSWORD]);

        $response->assertUnprocessable();
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_the_issued_token_authenticates_and_logout_revokes_it(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $token = $this->postJson(route('api.login'), ['email' => $user->email, 'password' => self::PASSWORD])->json('token');

        $this->withToken($token)->getJson(route('api.user'))->assertOk()->assertJsonPath('id', $user->id);
        $this->withToken($token)->postJson(route('api.logout'))->assertNoContent();

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_protected_endpoints_require_a_token(): void
    {
        $this->getJson(route('api.tenants.index'))->assertUnauthorized();
        $this->postJson(route('api.logout'))->assertUnauthorized();
    }
}
