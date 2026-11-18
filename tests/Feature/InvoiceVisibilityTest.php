<?php

namespace Tests\Feature;

use App\Models\CostCategory;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\ManagementGroup;
use App\Models\User;
use Database\Seeders\CostCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for a cross-account data leak: invoices (and their cost lines)
 * belonging to one user's Verwaltungsgruppe must not be visible or editable by a user
 * from a different Benutzergruppe.
 */
class InvoiceVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvoiceFor(User $user, string $description): Invoice
    {
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => $description, 'user_group_id' => $group->id]);
        $issuer = InvoiceIssuer::create(['user_group_id' => $group->id, 'name' => $description.' Issuer', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        return Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'costable_type' => $managementGroup->getMorphClass(),
            'costable_id' => $managementGroup->id,
            'invoice_date' => '2026-03-01',
            'amount' => 100,
            'periodicity' => 'once',
        ]);
    }

    public function test_the_invoice_index_only_lists_invoices_of_the_current_users_own_groups(): void
    {
        $owner = User::factory()->create();
        $this->makeInvoiceFor($owner, 'Vermieter');

        $otherUser = User::factory()->create();

        $response = $this->actingAs($otherUser)->get(route('invoices.index'));

        $response->assertOk();
        $response->assertDontSee('Vermieter Issuer');
    }

    public function test_a_user_cannot_open_another_users_invoice_by_guessing_the_url(): void
    {
        $owner = User::factory()->create();
        $invoice = $this->makeInvoiceFor($owner, 'Vermieter');

        $otherUser = User::factory()->create();

        $response = $this->actingAs($otherUser)->get(route('invoices.show', $invoice));

        $response->assertForbidden();
    }

    public function test_a_user_cannot_edit_or_delete_another_users_invoice(): void
    {
        $owner = User::factory()->create();
        $invoice = $this->makeInvoiceFor($owner, 'Vermieter');

        $otherUser = User::factory()->create();

        $this->actingAs($otherUser)->get(route('invoices.edit', $invoice))->assertForbidden();
        $this->actingAs($otherUser)->put(route('invoices.update', $invoice), [
            'invoice_issuer_id' => $invoice->invoice_issuer_id,
            'invoice_date' => '2026-03-01',
            'amount' => 999,
        ])->assertForbidden();
        $this->actingAs($otherUser)->delete(route('invoices.destroy', $invoice))->assertForbidden();

        $this->assertSame(100.0, (float) $invoice->fresh()->amount);
    }

    public function test_a_user_cannot_add_a_cost_line_to_another_users_invoice(): void
    {
        $this->seed(CostCategorySeeder::class);

        $owner = User::factory()->create();
        $invoice = $this->makeInvoiceFor($owner, 'Vermieter');
        $billingInterval = $invoice->costable->billingIntervals()->first();
        $category = CostCategory::first();

        $otherUser = User::factory()->create();

        $response = $this->actingAs($otherUser)->post('/costs', [
            'invoice_id' => $invoice->id,
            'costable_type' => 'management_group',
            'costable_id' => $invoice->costable_id,
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $category->id,
            'amount' => 100,
        ]);

        $response->assertForbidden();
        $this->assertSame(0, $invoice->costs()->count());
    }

    public function test_a_user_cannot_edit_or_delete_a_cost_line_of_another_users_invoice(): void
    {
        $this->seed(CostCategorySeeder::class);

        $owner = User::factory()->create();
        $invoice = $this->makeInvoiceFor($owner, 'Vermieter');
        $billingInterval = $invoice->costable->billingIntervals()->first();
        $category = CostCategory::first();

        $cost = $invoice->costs()->create([
            'costable_type' => 'management_group',
            'costable_id' => $invoice->costable_id,
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $category->id,
            'amount' => 100,
            'period_date' => '2026-03-01',
        ]);

        $otherUser = User::factory()->create();

        $this->actingAs($otherUser)->put(route('costs.update', $cost), [
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $category->id,
            'amount' => 500,
        ])->assertForbidden();
        $this->actingAs($otherUser)->delete(route('costs.destroy', $cost))->assertForbidden();

        $this->assertSame(100.0, (float) $cost->fresh()->amount);
    }
}
