<?php

// KI-generiert

namespace Tests\Feature;

use App\Http\Middleware\TransactionPerRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * Ändernde Anfragen laufen in einer Transaktion: bei Erfolg festgeschrieben, bei einer
 * Fehlerantwort oder Ausnahme vollständig zurückgenommen.
 */
class TransactionPerRequestTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'cache';

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->group(function () {
            Route::post('/_test/ok', fn () => $this->write('ok') ?? response('ok'));
            Route::post('/_test/rejected', fn () => $this->write('rejected') ?? response('nein', 422));
            Route::post('/_test/invalid', function () {
                $this->write('invalid');

                throw ValidationException::withMessages(['feld' => 'Falsch.']);
            });
            Route::post('/_test/broken', function () {
                $this->write('broken');

                throw new RuntimeException('kaputt');
            });
            Route::post('/_test/self-closed', function () {
                $this->write('self-closed');
                DB::commit();

                return response('ok');
            });
            Route::get('/_test/read', fn () => $this->write('read') ?? response('nein', 404));
        });
        Route::middleware('api')->post('/api/_test/forbidden', fn () => $this->write('api') ?? abort(403));
    }

    public function test_a_successful_change_is_committed(): void
    {
        $this->post('/_test/ok')->assertOk();

        $this->assertWritten('ok');
    }

    public function test_an_error_response_takes_back_all_changes(): void
    {
        $this->post('/_test/rejected')->assertStatus(422);
        $this->postJson('/api/_test/forbidden')->assertForbidden();

        $this->assertNotWritten('rejected');
        $this->assertNotWritten('api');
    }

    public function test_a_validation_error_is_rolled_back_but_its_messages_survive_in_the_session(): void
    {
        $this->from('/formular')->post('/_test/invalid')->assertRedirect('/formular')->assertSessionHasErrors('feld');

        $this->assertNotWritten('invalid');
    }

    public function test_an_unexpected_exception_is_rolled_back(): void
    {
        $this->withoutExceptionHandling();

        try {
            $this->post('/_test/broken');
            $this->fail('Die Ausnahme hätte durchgereicht werden müssen.');
        } catch (RuntimeException $exception) {
            $this->assertSame('kaputt', $exception->getMessage());
        }

        $this->assertNotWritten('broken');
    }

    public function test_a_rendered_exception_is_rolled_back(): void
    {
        $this->post('/_test/broken')->assertServerError();

        $this->assertNotWritten('broken');
    }

    public function test_a_transaction_closed_by_the_code_itself_is_left_alone(): void
    {
        $level = DB::transactionLevel();

        $this->post('/_test/self-closed')->assertOk();

        $this->assertSame($level, DB::transactionLevel());
        $this->assertWritten('self-closed');
    }

    public function test_reading_requests_are_not_wrapped(): void
    {
        $this->get('/_test/read')->assertNotFound();

        $this->assertWritten('read');
    }

    public function test_a_failed_login_still_counts_towards_the_lockout(): void
    {
        $user = User::factory()->create();
        $limiterDriver = config('cache.stores.'.config('cache.limiter').'.driver');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'falsch'])->assertSessionHasErrors('email');
        }
        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        // Läge der Zähler in der Datenbank, nähme die Transaktion ihn bei jedem
        // Fehlversuch mit zurück.
        $this->assertNotSame('database', $limiterDriver);
    }

    public function test_the_middleware_rolls_back_and_rethrows_when_called_directly(): void
    {
        $level = DB::transactionLevel();

        try {
            (new TransactionPerRequest)->handle(Request::create('/', 'POST'), function () {
                $this->write('direkt');

                throw new RuntimeException('direkt');
            });
            $this->fail('Die Ausnahme hätte durchgereicht werden müssen.');
        } catch (RuntimeException) {
            $this->assertSame($level, DB::transactionLevel());
        }

        $this->assertNotWritten('direkt');
    }

    public function test_a_response_built_from_an_exception_counts_as_failed(): void
    {
        $response = (new TransactionPerRequest)->handle(Request::create('/', 'DELETE'), function () {
            $this->write('mit-ausnahme');

            return (new Response('ok'))->withException(new RuntimeException('intern'));
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotWritten('mit-ausnahme');
    }

    private function write(string $key): null
    {
        DB::table(self::TABLE)->insert(['key' => $key, 'value' => 'x', 'expiration' => 0]);

        return null;
    }

    private function assertWritten(string $key): void
    {
        $this->assertTrue(DB::table(self::TABLE)->where('key', $key)->exists(), "{$key} sollte gespeichert sein.");
    }

    private function assertNotWritten(string $key): void
    {
        $this->assertFalse(DB::table(self::TABLE)->where('key', $key)->exists(), "{$key} sollte zurückgenommen sein.");
    }
}
