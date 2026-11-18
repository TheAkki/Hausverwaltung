<?php

namespace Tests\Feature;

use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagementUnitShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_occupancy_plan_is_present_but_collapsed_by_default_and_shows_occupant_counts(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $occupied = $managementUnit->rentalUnits()->create([
            'type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true, 'description' => 'Belegte Wohnung',
        ]);
        $managementUnit->rentalUnits()->create([
            'type' => 'Wohnung', 'size' => 40, 'is_relevant_for_billing' => true, 'description' => 'Leerstand',
        ]);
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create([
            'rental_unit_id' => $occupied->id, 'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);
        $lease->currentLeaseInterval()->update(['occupant_count' => 2]);

        $response = $this->actingAs($user)->get(route('management-units.show', $managementUnit).'?year=2026');
        $html = $response->getContent();

        $response->assertOk();
        $response->assertSee('Belegungsplan');

        // Collapsed by default: the Belegungsplan's own <details> has no "open" attribute.
        $this->assertMatchesRegularExpression(
            '/<details id="belegungsplan" class="mt-4"\s*>\s*<summary[^>]*>\s*Belegungsplan.*?<\/details>/s',
            $html
        );
        preg_match('/<summary[^>]*>\s*Belegungsplan.*?<\/details>/s', $html, $matches);
        $plan = $matches[0];

        $this->assertStringContainsString('Belegte Wohnung', $plan);
        $this->assertStringContainsString('>2<', $plan);
        // Der ganzjährig leerstehende Mieteinheit wird ausgeblendet (Jahressumme 0), auch
        // wenn ihre Beschreibung an anderer Stelle der Seite (Mieteinheiten-Tabelle) steht.
        $this->assertStringNotContainsString('Leerstand', $plan);
    }

    public function test_the_occupancy_matrix_is_present_but_collapsed_by_default(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $managementUnit->rentalUnits()->create([
            'type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true,
        ]);

        $response = $this->actingAs($user)->get(route('management-units.show', $managementUnit));

        $response->assertOk();
        $response->assertSee('Belegungsmatrix');

        // A collapsed <details> element has no "open" attribute.
        $this->assertMatchesRegularExpression(
            '/<details id="belegungsmatrix" class="mt-4"\s*>\s*<summary/',
            $response->getContent()
        );
    }

    public function test_the_occupancy_matrix_is_expanded_once_its_own_year_switcher_is_used(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $managementUnit->rentalUnits()->create([
            'type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true,
        ]);

        $response = $this->actingAs($user)->get(route('management-units.show', $managementUnit).'?year=2025&matrix=1');

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/<details id="belegungsmatrix" class="mt-4"\s*open\s*>\s*<summary/',
            $response->getContent()
        );
    }

    /**
     * Regression test: the page has two independent year switchers (Belegungsmatrix and
     * Mieteinnahmen/Ausgaben), both changing the same "year" query param. The matrix used
     * to auto-expand whenever *either* one changed the year, undoing a user's explicit
     * collapse just by browsing years in the unrelated income/expense tables below.
     */
    public function test_the_occupancy_matrix_stays_collapsed_when_only_the_unrelated_income_year_switcher_is_used(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $managementUnit->rentalUnits()->create([
            'type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true,
        ]);

        $response = $this->actingAs($user)->get(route('management-units.show', $managementUnit).'?year=2025');

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/<details id="belegungsmatrix" class="mt-4"\s*>\s*<summary/',
            $response->getContent()
        );
    }

    public function test_the_matrix_year_switcher_links_carry_the_matrix_marker_but_the_income_switcher_links_do_not(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $managementUnit->rentalUnits()->create([
            'type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true,
        ]);

        $response = $this->actingAs($user)->get(route('management-units.show', $managementUnit));

        $response->assertOk();
        $response->assertSee('matrix=1', false);
        // The link count with the marker must match exactly the matrix's own two links
        // (prev/next year) – the income/expense switcher's links must not carry it.
        $this->assertSame(2, substr_count($response->getContent(), 'matrix=1'));
    }

    public function test_the_occupancy_plan_is_expanded_once_its_own_year_switcher_is_used(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $managementUnit->rentalUnits()->create([
            'type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true,
        ]);

        $response = $this->actingAs($user)->get(route('management-units.show', $managementUnit).'?year=2025&plan=1');

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/<details id="belegungsplan" class="mt-4"\s*open\s*>\s*<summary/',
            $response->getContent()
        );
    }

    public function test_the_occupancy_plan_stays_collapsed_when_only_the_unrelated_income_year_switcher_is_used(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $managementUnit->rentalUnits()->create([
            'type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true,
        ]);

        $response = $this->actingAs($user)->get(route('management-units.show', $managementUnit).'?year=2025');

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/<details id="belegungsplan" class="mt-4"\s*>\s*<summary/',
            $response->getContent()
        );
    }

    public function test_the_plan_year_switcher_links_carry_the_plan_marker_but_the_income_switcher_links_do_not(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $managementUnit->rentalUnits()->create([
            'type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true,
        ]);

        $response = $this->actingAs($user)->get(route('management-units.show', $managementUnit));

        $response->assertOk();
        $this->assertSame(2, substr_count($response->getContent(), 'plan=1'));
    }

    /**
     * Following a year-switcher link must not look like the whole page reloaded: each
     * switcher's links carry a #anchor pointing back at its own <details> block, so the
     * browser scrolls straight back to it instead of landing at the top of the page.
     */
    public function test_the_matrix_and_plan_year_switcher_links_carry_an_anchor_back_to_their_own_section(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $managementUnit->rentalUnits()->create([
            'type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true,
        ]);

        $response = $this->actingAs($user)->get(route('management-units.show', $managementUnit));
        $html = $response->getContent();

        $response->assertOk();
        $this->assertSame(2, substr_count($html, '#belegungsmatrix'));
        $this->assertSame(2, substr_count($html, '#belegungsplan'));
    }

    /**
     * The Mieteinheiten table's "Größe" column shows 2 decimal places (sizes can be
     * fractional, e.g. "50,25 m²") and gets a "Gesamtgröße" footer row summing every
     * Mieteinheit of this Verwaltungseinheit.
     */
    public function test_the_rental_units_table_shows_sizes_with_two_decimals_and_a_total(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $managementUnit->rentalUnits()->create([
            'type' => 'Wohnung', 'size' => 50.25, 'is_relevant_for_billing' => true,
        ]);
        $managementUnit->rentalUnits()->create([
            'type' => 'Garage', 'size' => 15, 'is_relevant_for_billing' => false,
        ]);

        $response = $this->actingAs($user)->get(route('management-units.show', $managementUnit));

        $response->assertOk();
        $response->assertSee('50,25 m²');
        $response->assertSee('15,00 m²');
        $response->assertSee('Gesamtgröße');
        $response->assertSee('65,25 m²');
    }
}
