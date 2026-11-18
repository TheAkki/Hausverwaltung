<?php

namespace Tests\Feature;

use App\Models\CostCategory;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers CRUD for Verwaltungsgruppen (management groups), authorized via
 * authorizeResource + ManagementGroupPolicy.
 */
class ManagementGroupControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_index_only_lists_visible_management_groups(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        ManagementGroup::create(['description' => 'Own', 'user_group_id' => $group->id]);

        $outsider = User::factory()->create();
        $outsiderGroup = $outsider->groups()->first();
        ManagementGroup::create(['description' => 'Other', 'user_group_id' => $outsiderGroup->id]);

        $response = $this->actingAs($user)->get(route('management-groups.index'));

        $response->assertOk();
        $this->assertCount(1, $response->viewData('managementGroups'));
    }

    public function test_the_create_form_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('management-groups.create'));

        $response->assertOk();
    }

    public function test_a_management_group_can_be_created_for_a_group_the_user_belongs_to(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();

        $response = $this->actingAs($user)->post(route('management-groups.store'), [
            'description' => 'Neue VG',
            'user_group_id' => $group->id,
        ]);

        $managementGroup = ManagementGroup::first();
        $this->assertNotNull($managementGroup);
        $response->assertRedirect(route('management-groups.show', $managementGroup));
        $this->assertSame('Neue VG', $managementGroup->description);
        $this->assertFalse($managementGroup->kontosystem_enabled);
    }

    public function test_a_management_group_cannot_be_created_for_a_group_the_user_does_not_belong_to(): void
    {
        $user = User::factory()->create();
        $outsider = User::factory()->create();
        $outsiderGroup = $outsider->groups()->first();

        $response = $this->actingAs($user)->post(route('management-groups.store'), [
            'description' => 'Neue VG',
            'user_group_id' => $outsiderGroup->id,
        ]);

        $response->assertForbidden();
        $this->assertSame(0, ManagementGroup::count());
    }

    public function test_the_show_page_generates_rent_payments_and_hits_the_lease_interval_loop(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $rentalUnit = $managementUnit->rentalUnits()->create([
            'type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true,
        ]);
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => now()->startOfYear()->toDateString(),
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);

        $response = $this->actingAs($user)->get(route('management-groups.show', $managementGroup));

        $response->assertOk();
        $this->assertGreaterThan(0, $lease->leaseIntervals->first()->rentPayments()->count());
    }

    public function test_the_show_page_has_a_collapsed_occupancy_plan_hiding_vacant_units(): void
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

        $response = $this->actingAs($user)->get(route('management-groups.show', $managementGroup).'?year=2026');
        $html = $response->getContent();

        $response->assertOk();
        $response->assertSee('Belegungsplan');

        $this->assertMatchesRegularExpression(
            '/<details id="belegungsplan"\s*>\s*<summary[^>]*>\s*Belegungsplan.*?<\/details>/s',
            $html
        );
        preg_match('/<summary[^>]*>\s*Belegungsplan.*?<\/details>/s', $html, $matches);
        $plan = $matches[0];

        $this->assertStringContainsString('Belegte Wohnung', $plan);
        $this->assertStringContainsString('>2<', $plan);
        $this->assertStringNotContainsString('Leerstand', $plan);
    }

    /**
     * The "Abrechnungsschlüssel je Kostenkategorie" section is collapsed by default
     * (matching the Belegungsplan's collapse pattern), and a nicht abrechnungsrelevante
     * Kategorie (e.g. Zinsen, Tilgung) never gets nach Fläche/Personen/… verteilt, so it
     * shows no Abrechnungsschlüssel-Auswahl at all.
     */
    public function test_the_show_pages_cost_category_section_is_collapsed_and_skips_the_dropdown_for_non_billing_relevant_categories(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        CostCategory::create(['name' => 'Heizkosten', 'is_relevant_for_billing' => true]);
        CostCategory::create(['name' => 'Zinsen', 'is_relevant_for_billing' => false]);

        $response = $this->actingAs($user)->get(route('management-groups.show', $managementGroup));
        $html = $response->getContent();

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/<details id="kostenkategorien"\s*>\s*<summary[^>]*>\s*Abrechnungsschlüssel je Kostenkategorie.*?<\/details>/s',
            $html
        );
        preg_match('/<summary[^>]*>\s*Abrechnungsschlüssel je Kostenkategorie.*?<\/details>/s', $html, $matches);
        $section = $matches[0];

        $this->assertStringContainsString('Heizkosten', $section);
        $this->assertStringContainsString('<select name="allocation_key"', $section);
        $this->assertStringContainsString('Zinsen', $section);
        $this->assertStringContainsString('Nicht abrechnungsrelevant', $section);

        // Only Heizkosten's row may contain the dropdown – Zinsen's row must not.
        $zinsenRowStart = strpos($section, 'Zinsen');
        $this->assertStringNotContainsString('<select name="allocation_key"', substr($section, $zinsenRowStart));
    }

    /**
     * "Übersicht aller Buchungen" (Konzept.md "### Betriebskostenabrechnung"): every
     * Cost within a Verwaltungsgruppe's Abrechnungsintervall, grouped by Kategorie – in
     * a collapsed-by-default <details>, one per Abrechnungsintervall.
     */
    public function test_the_show_page_lists_bookings_grouped_by_category_for_each_billing_interval(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $billingInterval = $managementGroup->billingIntervals()->first();
        $category = CostCategory::create(['name' => 'Heizkosten', 'is_relevant_for_billing' => true]);
        $issuer = InvoiceIssuer::create(['name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'costable_type' => $managementGroup->getMorphClass(),
            'costable_id' => $managementGroup->id,
            'invoice_date' => $billingInterval->start_date,
            'amount' => 250,
            'periodicity' => 'once',
        ]);
        $managementGroup->costs()->create([
            'costable_type' => $managementGroup->getMorphClass(), 'costable_id' => $managementGroup->id,
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $category->id,
            'invoice_id' => $invoice->id, 'amount' => 250, 'period_date' => $billingInterval->start_date,
            'booking_date' => $billingInterval->start_date,
        ]);

        $response = $this->actingAs($user)->get(route('management-groups.show', $managementGroup));
        $html = $response->getContent();

        $response->assertOk();
        $this->assertStringContainsString('Übersicht aller Buchungen', $html);
        $this->assertStringContainsString('Heizkosten', $html);
        $this->assertStringContainsString('Stadtwerke', $html);
        // Collapsed by default: no "open" attribute on the <details>.
        $this->assertMatchesRegularExpression(
            '/<details class="mt-2"\s*>\s*<summary[^>]*>\s*Übersicht aller Buchungen/',
            $html
        );
    }

    /**
     * A CostCategory owned by another Account's Benutzergruppe (Nutzerhinweis: "Warum
     * sehe ich in dieser Übersicht Heizung und Heizkosten? Die Kategorie Heizung hat
     * der Account gar nicht.") must not show up on a Verwaltungsgruppe belonging to a
     * different Account – only systemweite (CostCategorySeeder) und eigene Kategorien.
     */
    public function test_the_show_page_does_not_list_another_accounts_cost_category(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);

        $otherAccount = User::factory()->create();
        CostCategory::create([
            'user_group_id' => $otherAccount->groups()->first()->id,
            'name' => 'Fremde Kategorie',
            'is_relevant_for_billing' => true,
        ]);

        $response = $this->actingAs($user)->get(route('management-groups.show', $managementGroup));

        $response->assertOk();
        $response->assertDontSee('Fremde Kategorie');
    }

    public function test_the_show_pages_occupancy_plan_stays_open_across_its_own_year_switcher_with_an_anchor_back_to_it(): void
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

        // Ohne den "plan"-Marker: bleibt zu.
        $collapsed = $this->actingAs($user)->get(route('management-groups.show', $managementGroup).'?year=2025');
        $collapsed->assertOk();
        $this->assertMatchesRegularExpression(
            '/<details id="belegungsplan"\s*>\s*<summary/',
            $collapsed->getContent()
        );
        $this->assertSame(2, substr_count($collapsed->getContent(), 'plan=1'));
        $this->assertSame(2, substr_count($collapsed->getContent(), '#belegungsplan'));

        // Mit dem "plan"-Marker (eigener Jahresumschalter benutzt): bleibt/wird offen.
        $expanded = $this->actingAs($user)->get(route('management-groups.show', $managementGroup).'?year=2024&plan=1');
        $expanded->assertOk();
        $this->assertMatchesRegularExpression(
            '/<details id="belegungsplan"\s*open\s*>\s*<summary/',
            $expanded->getContent()
        );
    }

    public function test_the_edit_form_can_be_rendered_by_a_group_member(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);

        $response = $this->actingAs($user)->get(route('management-groups.edit', $managementGroup));

        $response->assertOk();
    }

    public function test_the_edit_form_is_forbidden_for_an_outsider(): void
    {
        $owner = User::factory()->create();
        $group = $owner->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->get(route('management-groups.edit', $managementGroup));

        $response->assertForbidden();
    }

    public function test_a_management_group_can_be_updated(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);

        $response = $this->actingAs($user)->put(route('management-groups.update', $managementGroup), [
            'description' => 'Geändert',
            'user_group_id' => $group->id,
            'kontosystem_enabled' => '1',
        ]);

        $response->assertRedirect(route('management-groups.show', $managementGroup));
        $managementGroup->refresh();
        $this->assertSame('Geändert', $managementGroup->description);
        $this->assertTrue($managementGroup->kontosystem_enabled);
    }

    public function test_a_management_group_cannot_be_moved_to_a_group_the_user_does_not_belong_to(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);

        $otherUser = User::factory()->create();
        $otherGroup = $otherUser->groups()->first();

        $response = $this->actingAs($user)->put(route('management-groups.update', $managementGroup), [
            'description' => 'Geändert',
            'user_group_id' => $otherGroup->id,
        ]);

        $response->assertForbidden();
        $this->assertSame('MG', $managementGroup->fresh()->description);
    }

    public function test_updating_a_management_group_is_forbidden_for_an_outsider(): void
    {
        $owner = User::factory()->create();
        $group = $owner->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->put(route('management-groups.update', $managementGroup), [
            'description' => 'Hijacked',
            'user_group_id' => $group->id,
        ]);

        $response->assertForbidden();
    }

    public function test_a_management_group_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);

        $response = $this->actingAs($user)->delete(route('management-groups.destroy', $managementGroup));

        $response->assertRedirect(route('management-groups.index'));
        $this->assertNull(ManagementGroup::find($managementGroup->id));
    }

    public function test_deleting_a_management_group_is_forbidden_for_an_outsider(): void
    {
        $owner = User::factory()->create();
        $group = $owner->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->delete(route('management-groups.destroy', $managementGroup));

        $response->assertForbidden();
        $this->assertNotNull(ManagementGroup::find($managementGroup->id));
    }
}
