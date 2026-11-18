<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the SetLocale middleware: switching to a supported locale via the
 * /locale/{locale} route, and falling back to the configured default when the
 * session holds an unsupported value.
 */
class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_switching_to_a_supported_locale_persists_it_in_the_session(): void
    {
        $response = $this->get('/locale/en');

        $response->assertRedirect();
        $this->assertSame('en', session('locale'));
    }

    public function test_switching_to_an_unsupported_locale_is_rejected(): void
    {
        $response = $this->get('/locale/fr');

        $response->assertNotFound();
    }

    public function test_an_unsupported_locale_stored_in_the_session_falls_back_to_the_default(): void
    {
        $user = User::factory()->create();

        $this->withSession(['locale' => 'fr'])->actingAs($user)->get('/dashboard');

        $this->assertSame(config('app.locale'), app()->getLocale());
    }
}
