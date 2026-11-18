<?php

namespace Tests\Feature;

use App\Models\CostCategory;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\ManagementUnit;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers CRUD for Verwaltungseinheiten (management units), scoped via
 * authorizeResource + ManagementUnitPolicy.
 */
class ManagementUnitControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeManagementGroup(User $user): ManagementGroup
    {
        $group = $user->groups()->first();

        return ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
    }

    public function test_the_index_only_lists_units_of_visible_management_groups(): void
    {
        $user = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($user);
        $managementGroup->managementUnits()->create(['street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $outsider = User::factory()->create();
        $outsiderGroup = $this->makeManagementGroup($outsider);
        $outsiderGroup->managementUnits()->create(['street' => 'C', 'postal_code' => '2', 'city' => 'D']);

        $response = $this->actingAs($user)->get(route('management-units.index'));

        $response->assertOk();
        $this->assertCount(1, $response->viewData('managementUnits'));
    }

    public function test_the_create_form_can_be_rendered_for_a_visible_management_group(): void
    {
        $user = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($user);

        $response = $this->actingAs($user)->get(route('management-units.create', ['management_group_id' => $managementGroup->id]));

        $response->assertOk();
    }

    public function test_the_create_form_requires_view_access_to_the_management_group(): void
    {
        $owner = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($owner);
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->get(route('management-units.create', ['management_group_id' => $managementGroup->id]));

        $response->assertNotFound();
    }

    public function test_a_management_unit_can_be_created(): void
    {
        $user = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($user);

        $response = $this->actingAs($user)->post(route('management-units.store'), [
            'management_group_id' => $managementGroup->id,
            'street' => 'Teststr 1',
            'postal_code' => '11111',
            'city' => 'Teststadt',
            'description' => 'Vorderhaus',
        ]);

        $managementUnit = ManagementUnit::first();
        $this->assertNotNull($managementUnit);
        $response->assertRedirect(route('management-units.show', $managementUnit));
        $this->assertSame('Vorderhaus', $managementUnit->description);
    }

    public function test_a_management_unit_cannot_be_created_for_a_management_group_of_another_user(): void
    {
        $user = User::factory()->create();
        $outsider = User::factory()->create();
        $outsiderGroup = $this->makeManagementGroup($outsider);

        $response = $this->actingAs($user)->post(route('management-units.store'), [
            'management_group_id' => $outsiderGroup->id,
            'street' => 'Teststr 1',
            'postal_code' => '11111',
            'city' => 'Teststadt',
        ]);

        $response->assertNotFound();
        $this->assertSame(0, ManagementUnit::count());
    }

    public function test_the_show_page_supports_the_tenant_split_income_view(): void
    {
        $user = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($user);
        $managementUnit = $managementGroup->managementUnits()->create(['street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $rentalUnit = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true]);
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => now()->startOfYear()->toDateString(),
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);

        $response = $this->actingAs($user)->get(route('management-units.show', $managementUnit).'?income_view=tenant_split');

        $response->assertOk();
        $this->assertSame('tenant_split', $response->viewData('incomeView'));
    }

    /**
     * "Übersicht aller Buchungen" (Konzept.md "### Betriebskostenabrechnung"): every
     * Cost within a Verwaltungseinheit's Abrechnungsintervall, grouped by Kategorie –
     * in a collapsed-by-default <details>, nested under each Abrechnungsintervall.
     */
    public function test_the_show_page_lists_bookings_grouped_by_category_for_each_billing_interval(): void
    {
        $user = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($user);
        $managementUnit = $managementGroup->managementUnits()->create(['street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $billingInterval = $managementGroup->billingIntervals()->first();
        $category = CostCategory::create(['name' => 'Heizkosten', 'is_relevant_for_billing' => true]);
        $issuer = InvoiceIssuer::create(['name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'costable_type' => $managementUnit->getMorphClass(),
            'costable_id' => $managementUnit->id,
            'invoice_date' => $billingInterval->start_date,
            'amount' => 250,
            'periodicity' => 'once',
        ]);
        $managementUnit->costs()->create([
            'costable_type' => $managementUnit->getMorphClass(), 'costable_id' => $managementUnit->id,
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $category->id,
            'invoice_id' => $invoice->id, 'amount' => 250, 'period_date' => $billingInterval->start_date,
            'booking_date' => $billingInterval->start_date,
        ]);

        $response = $this->actingAs($user)->get(route('management-units.show', $managementUnit));
        $html = $response->getContent();

        $response->assertOk();
        $this->assertStringContainsString('Übersicht aller Buchungen', $html);
        $this->assertStringContainsString('Heizkosten', $html);
        $this->assertStringContainsString('Stadtwerke', $html);
    }

    /**
     * "Leerstand" (Konzept.md "##### Leerstände"): eine zeitweise komplett
     * leerstehende, abrechnungsrelevante Mieteinheit erscheint mit ihrem
     * Leerstandszeitraum und einem "Neue Forderung"-Link auf der Verwaltungseinheiten-
     * Seite; die eigentliche Kostenberechnung (Vermieteranteil) findet direkt in der
     * echten Betriebskostenabrechnung statt (siehe BillingStatementSmokeTest).
     */
    public function test_the_show_page_lists_the_vacancy_period_of_a_partially_vacant_unit(): void
    {
        $user = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($user);
        $managementUnit = $managementGroup->managementUnits()->create(['street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $rentalUnit = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'size' => 40, 'is_relevant_for_billing' => true]);
        $billingInterval = $managementGroup->billingIntervals()->first();
        $billingInterval->update(['start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        // Erst ab 1.7.2026 vermietet -> Januar bis Juni 2026 (181 Tage) Leerstand.
        $lease = Lease::create(['rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-07-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 400]);
        $lease->tenants()->attach(Tenant::create(['first_name' => 'Erika', 'last_name' => 'Musterfrau', 'street' => 'A', 'postal_code' => '1', 'city' => 'B'])->id);

        $response = $this->actingAs($user)->get(route('management-units.show', $managementUnit));
        $html = $response->getContent();

        $response->assertOk();
        $this->assertStringContainsString('Leerstand', $html);
        $this->assertStringContainsString('01.01.2026', $html);
        $this->assertStringContainsString('30.06.2026', $html);
        $this->assertStringContainsString('181 Tage', $html);
        $this->assertStringContainsString(route('settlement-claims.create', ['rental_unit_id' => $rentalUnit->id]), $html);
    }

    public function test_an_invalid_income_view_falls_back_to_tenant(): void
    {
        $user = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($user);
        $managementUnit = $managementGroup->managementUnits()->create(['street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $response = $this->actingAs($user)->get(route('management-units.show', $managementUnit).'?income_view=nonsense');

        $response->assertOk();
        $this->assertSame('tenant', $response->viewData('incomeView'));
    }

    public function test_the_edit_form_can_be_rendered(): void
    {
        $user = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($user);
        $managementUnit = $managementGroup->managementUnits()->create(['street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $response = $this->actingAs($user)->get(route('management-units.edit', $managementUnit));

        $response->assertOk();
    }

    public function test_the_edit_form_is_forbidden_for_an_outsider(): void
    {
        $owner = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($owner);
        $managementUnit = $managementGroup->managementUnits()->create(['street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->get(route('management-units.edit', $managementUnit));

        $response->assertForbidden();
    }

    public function test_a_management_unit_can_be_updated(): void
    {
        $user = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($user);
        $managementUnit = $managementGroup->managementUnits()->create(['street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $response = $this->actingAs($user)->put(route('management-units.update', $managementUnit), [
            'street' => 'Neue Str 2',
            'postal_code' => '22222',
            'city' => 'Neustadt',
        ]);

        $response->assertRedirect(route('management-units.show', $managementUnit));
        $this->assertSame('Neue Str 2', $managementUnit->fresh()->street);
    }

    public function test_updating_a_management_unit_is_forbidden_for_an_outsider(): void
    {
        $owner = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($owner);
        $managementUnit = $managementGroup->managementUnits()->create(['street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->put(route('management-units.update', $managementUnit), [
            'street' => 'Hijacked',
            'postal_code' => '1',
            'city' => 'B',
        ]);

        $response->assertForbidden();
        $this->assertSame('A', $managementUnit->fresh()->street);
    }

    public function test_a_management_unit_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($user);
        $managementUnit = $managementGroup->managementUnits()->create(['street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $response = $this->actingAs($user)->delete(route('management-units.destroy', $managementUnit));

        $response->assertRedirect(route('management-groups.show', $managementGroup));
        $this->assertNull(ManagementUnit::find($managementUnit->id));
    }

    public function test_deleting_a_management_unit_is_forbidden_for_an_outsider(): void
    {
        $owner = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($owner);
        $managementUnit = $managementGroup->managementUnits()->create(['street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->delete(route('management-units.destroy', $managementUnit));

        $response->assertForbidden();
        $this->assertNotNull(ManagementUnit::find($managementUnit->id));
    }
}
