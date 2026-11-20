<?php

namespace Tests\Feature;

use App\Models\Cost;
use App\Models\CostCategory;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\RentalUnit;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers "Kostenzuweisung": Kosten mit einer Kategorie der Abrechnungsart
 * "individual" können direkt einem Mietvertrag (statt Verwaltungsgruppe/-einheit/
 * Mieteinheit) zugewiesen werden, gehen aber nie in die Kontensystem-Bilanzierung ein.
 */
class LeaseCostAssignmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: ManagementGroup, 2: RentalUnit, 3: Lease, 4: Invoice}
     */
    private function makeLeaseSetup(bool $kontosystemEnabled = false): array
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create([
            'description' => 'Kostenzuweisung Group', 'user_group_id' => $group->id,
            'kontosystem_enabled' => $kontosystemEnabled,
        ]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt',
        ]);
        $rentalUnit = $managementUnit->rentalUnits()->create([
            'type' => 'Wohnung', 'size' => 60, 'is_relevant_for_billing' => true,
        ]);

        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);

        $issuer = InvoiceIssuer::create(['name' => 'Handwerker', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'costable_type' => $rentalUnit->getMorphClass(),
            'costable_id' => $rentalUnit->id,
            'invoice_date' => '2026-03-01',
            'amount' => 300,
            'periodicity' => 'once',
        ]);

        return [$user, $managementGroup, $rentalUnit, $lease, $invoice];
    }

    public function test_a_cost_can_be_assigned_to_a_lease_when_the_category_has_the_individual_allocation_key(): void
    {
        [$user, $managementGroup, , $lease, $invoice] = $this->makeLeaseSetup();
        $billingInterval = $managementGroup->billingIntervals()->first();
        $category = CostCategory::create(['name' => 'Individuelle Kategorie', 'is_relevant_for_billing' => true]);
        $managementGroup->costCategories()->attach($category->id, ['allocation_key' => 'individual']);

        $response = $this->actingAs($user)->post('/costs', [
            'invoice_id' => $invoice->id,
            'costable_type' => 'lease',
            'costable_id' => $lease->id,
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $category->id,
            'amount' => 100,
        ]);

        $response->assertRedirect(route('invoices.show', $invoice));

        $cost = Cost::where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($cost);
        $this->assertTrue($cost->costable->is($lease));
    }

    public function test_a_cost_cannot_be_assigned_to_a_lease_when_the_category_is_not_individual(): void
    {
        [$user, $managementGroup, , $lease, $invoice] = $this->makeLeaseSetup();
        $billingInterval = $managementGroup->billingIntervals()->first();
        $category = CostCategory::create(['name' => 'Flächen-Kategorie', 'is_relevant_for_billing' => true]);
        $managementGroup->costCategories()->attach($category->id, ['allocation_key' => 'area']);

        $response = $this->actingAs($user)->post('/costs', [
            'invoice_id' => $invoice->id,
            'costable_type' => 'lease',
            'costable_id' => $lease->id,
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $category->id,
            'amount' => 100,
        ]);

        $response->assertSessionHasErrors('cost_category_id');
        $this->assertSame(0, Cost::where('invoice_id', $invoice->id)->count());
    }

    public function test_a_cost_cannot_be_assigned_to_a_lease_when_the_category_has_no_allocation_key_set_at_all(): void
    {
        [$user, $managementGroup, , $lease, $invoice] = $this->makeLeaseSetup();
        $billingInterval = $managementGroup->billingIntervals()->first();
        // Kategorie ganz ohne management_group_cost_category-Zeile: fällt effektiv auf
        // die Default-Abrechnungsart ("area") zurück, nicht auf "individual".
        $category = CostCategory::create(['name' => 'Ohne Zuordnung', 'is_relevant_for_billing' => true]);

        $response = $this->actingAs($user)->post('/costs', [
            'invoice_id' => $invoice->id,
            'costable_type' => 'lease',
            'costable_id' => $lease->id,
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $category->id,
            'amount' => 100,
        ]);

        $response->assertSessionHasErrors('cost_category_id');
    }

    public function test_a_lease_assigned_cost_cannot_be_booked_to_an_account_even_with_kontosystem_enabled(): void
    {
        [$user, $managementGroup, , $lease, $invoice] = $this->makeLeaseSetup(kontosystemEnabled: true);
        $billingInterval = $managementGroup->billingIntervals()->first();
        $account = $managementGroup->accounts()->create(['name' => 'Girokonto']);
        $category = CostCategory::create(['name' => 'Individuelle Kategorie', 'is_relevant_for_billing' => true]);
        $managementGroup->costCategories()->attach($category->id, ['allocation_key' => 'individual']);

        $response = $this->actingAs($user)->post('/costs', [
            'invoice_id' => $invoice->id,
            'costable_type' => 'lease',
            'costable_id' => $lease->id,
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $category->id,
            'amount' => 100,
            'account_id' => $account->id,
        ]);

        $response->assertSessionHasErrors('account_id');
        $this->assertSame(0, Cost::where('invoice_id', $invoice->id)->count());
    }

    public function test_a_lease_assigned_cost_does_not_require_an_account_even_with_kontosystem_enabled(): void
    {
        [$user, $managementGroup, , $lease, $invoice] = $this->makeLeaseSetup(kontosystemEnabled: true);
        $billingInterval = $managementGroup->billingIntervals()->first();
        $category = CostCategory::create(['name' => 'Individuelle Kategorie', 'is_relevant_for_billing' => true]);
        $managementGroup->costCategories()->attach($category->id, ['allocation_key' => 'individual']);

        $response = $this->actingAs($user)->post('/costs', [
            'invoice_id' => $invoice->id,
            'costable_type' => 'lease',
            'costable_id' => $lease->id,
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $category->id,
            'amount' => 100,
            'booking_date' => '2026-03-05',
        ]);

        $response->assertRedirect(route('invoices.show', $invoice));
        $this->assertSame(1, Cost::where('invoice_id', $invoice->id)->count());
    }

    public function test_updating_a_lease_assigned_cost_to_a_non_individual_category_is_rejected(): void
    {
        [$user, $managementGroup, , $lease, $invoice] = $this->makeLeaseSetup();
        $billingInterval = $managementGroup->billingIntervals()->first();
        $individualCategory = CostCategory::create(['name' => 'Individuell', 'is_relevant_for_billing' => true]);
        $managementGroup->costCategories()->attach($individualCategory->id, ['allocation_key' => 'individual']);
        $areaCategory = CostCategory::create(['name' => 'Flächenbasiert', 'is_relevant_for_billing' => true]);
        $managementGroup->costCategories()->attach($areaCategory->id, ['allocation_key' => 'area']);

        $cost = $invoice->costs()->create([
            'costable_type' => $lease->getMorphClass(),
            'costable_id' => $lease->id,
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $individualCategory->id,
            'amount' => 100,
            'period_date' => '2026-03-01',
        ]);

        $response = $this->actingAs($user)->put(route('costs.update', $cost), [
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $areaCategory->id,
            'amount' => 100,
        ]);

        $response->assertSessionHasErrors('cost_category_id');
        $this->assertSame($individualCategory->id, $cost->fresh()->cost_category_id);
    }

    public function test_the_invoice_show_page_offers_the_units_leases_as_a_costable_option(): void
    {
        [$user, , $rentalUnit, $lease, $invoice] = $this->makeLeaseSetup();

        $response = $this->actingAs($user)->get(route('invoices.show', $invoice));

        $response->assertOk();
        $response->assertSee('lease:'.$lease->id, false);
        $response->assertSee('Max Mustermann', false);
    }

    /**
     * Splitting a Rechnung across several cost categories directly on a Mietvertrag
     * (e.g. Heizkosten + Wasserversorgung submetered per Wohnung) must only offer
     * categories whose Abrechnungsart is "Individuell" for that Verwaltungsgruppe –
     * anything else is silently excluded from the Betriebskostenabrechnung anyway
     * (see BillingCostAllocationService), so offering it would look like it worked
     * while quietly having no effect.
     */
    public function test_the_lease_costables_category_dropdown_only_offers_individual_categories(): void
    {
        [$user, $managementGroup, , $lease, $invoice] = $this->makeLeaseSetup();
        $individualCategory = CostCategory::create(['name' => 'Heizkosten (individuell)', 'is_relevant_for_billing' => true]);
        $managementGroup->costCategories()->attach($individualCategory->id, ['allocation_key' => 'individual']);
        $areaCategory = CostCategory::create(['name' => 'Flächenkosten', 'is_relevant_for_billing' => true]);
        $managementGroup->costCategories()->attach($areaCategory->id, ['allocation_key' => 'area']);

        $response = $this->actingAs($user)->get(route('invoices.show', $invoice));

        $response->assertOk();
        $payload = "\"lease:{$lease->id}\":[{\"id\":{$individualCategory->id},\"label\":\"Heizkosten (individuell)\"}]";
        $response->assertSee($payload, false);
        $response->assertDontSee("\"lease:{$lease->id}\":[{\"id\":{$individualCategory->id},\"label\":\"Heizkosten (individuell)\"},{\"id\":{$areaCategory->id}", false);
    }

    /**
     * The actual "split a Mietvertrags-Rechnung into two categories" flow end-to-end:
     * both cost rows use "Individuell" categories, so both succeed.
     */
    public function test_a_lease_invoice_can_be_split_across_two_individual_categories(): void
    {
        [$user, $managementGroup, , $lease, $invoice] = $this->makeLeaseSetup();
        $billingInterval = $managementGroup->billingIntervals()->first();
        $heating = CostCategory::create(['name' => 'Heizkosten', 'is_relevant_for_billing' => true]);
        $managementGroup->costCategories()->attach($heating->id, ['allocation_key' => 'individual']);
        $water = CostCategory::create(['name' => 'Wasserversorgung', 'is_relevant_for_billing' => true]);
        $managementGroup->costCategories()->attach($water->id, ['allocation_key' => 'individual']);

        $first = $this->actingAs($user)->post('/costs', [
            'invoice_id' => $invoice->id,
            'costable_type' => 'lease',
            'costable_id' => $lease->id,
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $heating->id,
            'amount' => 200,
        ]);
        $second = $this->actingAs($user)->post('/costs', [
            'invoice_id' => $invoice->id,
            'costable_type' => 'lease',
            'costable_id' => $lease->id,
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $water->id,
            'amount' => 100,
        ]);

        $first->assertRedirect(route('invoices.show', $invoice));
        $second->assertRedirect(route('invoices.show', $invoice));
        $this->assertSame(2, Cost::where('invoice_id', $invoice->id)->count());
        $this->assertEquals(300, Cost::where('invoice_id', $invoice->id)->sum('amount'));
    }
}
