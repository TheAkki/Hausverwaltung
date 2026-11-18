<?php

namespace Tests\Feature;

use App\Models\Cost;
use App\Models\CostCategory;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\ManagementGroup;
use App\Models\User;
use Database\Seeders\CostCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_new_cost_line_form_preselects_the_invoices_own_scope_and_full_amount(): void
    {
        $this->seed(CostCategorySeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create([
            'description' => 'Test Group',
            'user_group_id' => $group->id,
        ]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt',
        ]);
        $issuer = InvoiceIssuer::create(['name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'costable_type' => $managementUnit->getMorphClass(),
            'costable_id' => $managementUnit->id,
            'amount' => 150,
            'effective_amount' => 140,
            'invoice_date' => '2026-06-15',
            'periodicity' => 'once',
        ]);

        $response = $this->actingAs($user)->get(route('invoices.show', $invoice));

        $response->assertOk();
        $expectedKey = "management_unit:{$managementUnit->id}";
        $response->assertSee("value=\"{$expectedKey}\" selected", false);
        $response->assertSee('value="140.00"', false);
    }

    private function makeInvoiceWithCost(ManagementGroup $managementGroup): Invoice
    {
        $this->seed(CostCategorySeeder::class);

        $issuer = InvoiceIssuer::create(['name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'costable_type' => $managementGroup->getMorphClass(),
            'costable_id' => $managementGroup->id,
            'amount' => 100,
            'invoice_date' => '2026-06-15',
            'periodicity' => 'once',
        ]);
        Cost::create([
            'invoice_id' => $invoice->id,
            'costable_type' => $managementGroup->getMorphClass(),
            'costable_id' => $managementGroup->id,
            'billing_interval_id' => $managementGroup->billingIntervals()->first()->id,
            'cost_category_id' => CostCategory::first()->id,
            'amount' => 100,
            'period_date' => '2026-06-15',
        ]);

        return $invoice;
    }

    public function test_the_konto_column_is_hidden_when_the_kontosystem_is_disabled(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create([
            'description' => 'Test Group', 'user_group_id' => $group->id, 'kontosystem_enabled' => false,
        ]);
        $invoice = $this->makeInvoiceWithCost($managementGroup);

        $response = $this->actingAs($user)->get(route('invoices.show', $invoice));

        $response->assertOk();
        $response->assertDontSee('<th class="py-2">'.__('Konto').'</th>', false);
    }

    public function test_the_konto_column_is_shown_when_the_kontosystem_is_enabled(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create([
            'description' => 'Test Group', 'user_group_id' => $group->id, 'kontosystem_enabled' => true,
        ]);
        $managementGroup->accounts()->create(['name' => 'Girokonto']);
        $invoice = $this->makeInvoiceWithCost($managementGroup);

        $response = $this->actingAs($user)->get(route('invoices.show', $invoice));

        $response->assertOk();
        $response->assertSee(__('Konto'));
        $response->assertSee('Girokonto');
    }

    /**
     * Regression guard: the Kosten-table's "Zuordnung" column used to show a Cost
     * booked against a RentalUnit as a bare "RentalUnit #<id>", instead of a
     * meaningful label like "Mieteinheit: Garage" (Cost::costableLabel()).
     */
    public function test_the_kosten_table_shows_a_rental_units_type_instead_of_its_bare_class_name(): void
    {
        $this->seed(CostCategorySeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'Test Group', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt',
        ]);
        $rentalUnit = $managementUnit->rentalUnits()->create(['type' => 'Garage', 'is_relevant_for_billing' => false]);

        $issuer = InvoiceIssuer::create(['name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'costable_type' => $rentalUnit->getMorphClass(),
            'costable_id' => $rentalUnit->id,
            'amount' => 100,
            'invoice_date' => '2026-06-15',
            'periodicity' => 'once',
        ]);
        Cost::create([
            'invoice_id' => $invoice->id,
            'costable_type' => $rentalUnit->getMorphClass(),
            'costable_id' => $rentalUnit->id,
            'billing_interval_id' => $managementGroup->billingIntervals()->first()->id,
            'cost_category_id' => CostCategory::first()->id,
            'amount' => 100,
            'period_date' => '2026-06-15',
        ]);

        $response = $this->actingAs($user)->get(route('invoices.show', $invoice));

        $response->assertOk();
        $response->assertSee('Mieteinheit: Garage');
        $response->assertDontSee('RentalUnit #'.$rentalUnit->id);
    }
}
