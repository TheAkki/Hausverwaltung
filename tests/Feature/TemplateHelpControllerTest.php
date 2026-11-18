<?php

namespace Tests\Feature;

use App\Models\ManagementGroup;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the Blade-syntax/variables help page for writing Betriebskostenabrechnung
 * templates, and its fake-data preview action.
 */
class TemplateHelpControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_view_the_help_page(): void
    {
        $this->get(route('billing-templates.help'))->assertRedirect('/login');
    }

    // KI-generiert
    public function test_the_help_page_explains_printing_at_actual_size_and_the_border(): void
    {
        $this->actingAs(User::factory()->create())->get(route('billing-templates.help'))
            ->assertOk()
            ->assertSee('data-print-hint', false)
            ->assertSee('Tatsächliche Größe')
            ->assertSee('width: 83.94mm; height: 43.94mm.');
    }

    public function test_authenticated_users_can_view_the_help_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('billing-templates.help'));

        $response->assertOk();
        $response->assertSee('$managementGroup', false);
        $response->assertSee('$managementUnit', false);
        $response->assertSee('$billingInterval', false);
        $response->assertSee('$leases', false);
        $response->assertSee('$costsByCategory', false);
        $response->assertSee('$costsByMonth', false);
        $response->assertSee('$headerImage', false);
        $response->assertSee('@foreach', false);
        $response->assertSee('@if', false);
        $response->assertSee(route('billing-templates.help.preview'), false);
    }

    public function test_the_preview_action_renders_the_example_with_fake_data_and_no_database_records(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('billing-templates.help.preview'));

        $response->assertOk();
        $response->assertSee('Musterverwaltung GbR');
        $response->assertSee('Musterstraße 1');
        $response->assertSee('Max Mustermann');
        $response->assertSee('Heizkosten');
        $response->assertSee('840,00', false);
        $response->assertSee('Januar 2026');

        // Rein aus erfundenen, nicht persistierten Daten gerendert – es wurde nichts
        // in der Datenbank angelegt.
        $this->assertSame(0, ManagementGroup::count());
        $this->assertSame(0, Tenant::count());
    }

    public function test_guests_cannot_use_the_preview_action(): void
    {
        $this->get(route('billing-templates.help.preview'))->assertRedirect('/login');
    }
}
