<?php

namespace Tests\Feature;

use App\Models\CostCategory;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\ManagementGroup;
use App\Models\ManagementUnit;
use App\Models\User;
use Database\Seeders\CostCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_manually_created_invoice_is_scoped_to_the_chosen_verwaltungseinheit(): void
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
        $issuer = InvoiceIssuer::create(['user_group_id' => $group->id, 'name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $category = CostCategory::first();
        $billingInterval = $managementGroup->billingIntervals()->first();

        $response = $this->actingAs($user)->post('/invoices', [
            'invoice_issuer_id' => $issuer->id,
            'invoice_date' => '2026-06-15',
            'amount' => 150,
            'periodicity' => 'once',
            'costable' => "management_unit:{$managementUnit->id}",
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $category->id,
        ]);

        $invoice = Invoice::first();
        $this->assertNotNull($invoice);
        $response->assertRedirect(route('invoices.show', $invoice));

        $this->assertTrue($invoice->costable->is($managementUnit));
    }
}
