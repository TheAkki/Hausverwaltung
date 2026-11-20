<?php

// KI-generiert

namespace Tests\Feature\Api;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Die letzte Nutzung eines API-Tokens wird nur minutengenau gespeichert, damit nicht
 * jeder API-Aufruf einen Schreibvorgang auslöst.
 */
class TokenLastUsedPrecisionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_app_uses_its_own_token_model(): void
    {
        $this->assertSame(PersonalAccessToken::class, Sanctum::$personalAccessTokenModel);
        $this->assertInstanceOf(PersonalAccessToken::class, User::factory()->create()->createToken('x')->accessToken);
    }

    public function test_calls_within_a_minute_write_the_last_use_only_once(): void
    {
        Carbon::setTestNow('2026-11-20 10:00:00');
        $token = User::factory()->create()->createToken('Import')->plainTextToken;
        $writes = $this->countTokenWrites(function () use ($token) {
            foreach (['10:00:00', '10:00:30', '10:00:59'] as $time) {
                Carbon::setTestNow("2026-11-20 {$time}");
                $this->withToken($token)->getJson('/api/user')->assertOk();
                $this->app['auth']->forgetGuards();
            }
        });

        $this->assertSame(1, $writes);
        $this->assertSame('2026-11-20 10:00:00', PersonalAccessToken::sole()->last_used_at->toDateTimeString());

        Carbon::setTestNow('2026-11-20 10:01:00');
        $this->withToken($token)->getJson('/api/user')->assertOk();

        $this->assertSame('2026-11-20 10:01:00', PersonalAccessToken::sole()->last_used_at->toDateTimeString());
    }

    public function test_the_last_use_can_still_be_set_back_or_cleared(): void
    {
        Carbon::setTestNow('2026-11-20 10:00:00');
        User::factory()->create()->createToken('x');
        $model = PersonalAccessToken::sole();
        $model->forceFill(['last_used_at' => now()])->save();

        $model->forceFill(['last_used_at' => now()->subSeconds(10)])->save();
        $this->assertSame('2026-11-20 09:59:50', $model->fresh()->last_used_at->toDateTimeString());

        $model->forceFill(['last_used_at' => null])->save();
        $this->assertNull($model->fresh()->last_used_at);
    }

    private function countTokenWrites(callable $action): int
    {
        $writes = 0;
        DB::listen(function ($query) use (&$writes) {
            if (str_starts_with($query->sql, 'update "personal_access_tokens"')) {
                $writes++;
            }
        });
        $action();

        return $writes;
    }
}
