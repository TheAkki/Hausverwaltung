<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_view_the_help_page(): void
    {
        $this->get('/help')->assertRedirect('/login');
    }

    public function test_authenticated_users_can_view_the_generated_help_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/help');

        $response->assertOk();
        $response->assertSee('Bedienungsanleitung Hausverwaltung', false);
        $response->assertSee('Mietvertrag', false);
        $response->assertSee('seeddata.yaml', false);
        $response->assertSee('Ein eigenes Theme erstellen', false);
        $response->assertSee('Kopf-Bild hochladen und anzeigen', false);
        $response->assertSee('$headerImage', false);
        $response->assertSee('Templates und Benutzergruppen', false);
        $response->assertSee('Template überprüfen', false);
        $response->assertSee('Kostenzuweisung', false);
    }

    public function test_docs_generate_command_writes_the_static_html_file(): void
    {
        $path = public_path('docs/anleitung.html');

        if (is_file($path)) {
            unlink($path);
        }

        $this->artisan('docs:generate')->assertExitCode(0);

        $this->assertFileExists($path);
        $this->assertStringContainsString('Bedienungsanleitung Hausverwaltung', file_get_contents($path));
    }
}