<?php

namespace Tests\Feature;

use App\Models\CostCategory;
use App\Models\InvoiceIssuer;
use App\Models\User;
use Database\Seeders\CostCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for a cross-account data leak: invoice issuers
 * (Rechnungssteller) belonging to one user's Benutzergruppe must not be visible or
 * editable by a user from a different Benutzergruppe.
 */
class InvoiceIssuerVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function makeIssuerFor(User $user, string $name): InvoiceIssuer
    {
        $group = $user->groups()->first();

        return InvoiceIssuer::create([
            'user_group_id' => $group->id,
            'name' => $name,
            'street' => 'A', 'postal_code' => '1', 'city' => 'B',
        ]);
    }

    public function test_the_issuer_index_only_lists_issuers_of_the_current_users_own_groups(): void
    {
        $owner = User::factory()->create();
        $this->makeIssuerFor($owner, 'Sparkasse Vermieter');

        $otherUser = User::factory()->create();

        $response = $this->actingAs($otherUser)->get(route('invoice-issuers.index'));

        $response->assertOk();
        $response->assertDontSee('Sparkasse Vermieter');
    }

    public function test_a_user_cannot_open_another_users_issuer_by_guessing_the_url(): void
    {
        $owner = User::factory()->create();
        $issuer = $this->makeIssuerFor($owner, 'Sparkasse Vermieter');

        $otherUser = User::factory()->create();

        $this->actingAs($otherUser)->get(route('invoice-issuers.show', $issuer))->assertForbidden();
    }

    public function test_a_user_cannot_edit_or_delete_another_users_issuer(): void
    {
        $owner = User::factory()->create();
        $issuer = $this->makeIssuerFor($owner, 'Sparkasse Vermieter');

        $otherUser = User::factory()->create();

        $this->actingAs($otherUser)->get(route('invoice-issuers.edit', $issuer))->assertForbidden();
        $this->actingAs($otherUser)->put(route('invoice-issuers.update', $issuer), [
            'user_group_id' => $issuer->user_group_id,
            'name' => 'Hijacked',
            'street' => 'A', 'postal_code' => '1', 'city' => 'B',
        ])->assertForbidden();
        $this->actingAs($otherUser)->delete(route('invoice-issuers.destroy', $issuer))->assertForbidden();

        $this->assertSame('Sparkasse Vermieter', $issuer->fresh()->name);
    }

    public function test_a_user_cannot_create_an_invoice_against_another_users_issuer(): void
    {
        $this->seed(CostCategorySeeder::class);

        $owner = User::factory()->create();
        $issuer = $this->makeIssuerFor($owner, 'Sparkasse Vermieter');

        $otherUser = User::factory()->create();
        $otherGroup = $otherUser->groups()->first();
        $managementGroup = \App\Models\ManagementGroup::create(['description' => 'Other Group', 'user_group_id' => $otherGroup->id]);
        $billingInterval = $managementGroup->billingIntervals()->first();
        $category = CostCategory::first();

        $response = $this->actingAs($otherUser)->post('/invoices', [
            'invoice_issuer_id' => $issuer->id,
            'invoice_date' => '2026-06-15',
            'amount' => 150,
            'periodicity' => 'once',
            'costable' => "management_group:{$managementGroup->id}",
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $category->id,
        ]);

        $response->assertForbidden();
        $this->assertSame(0, \App\Models\Invoice::count());
    }
}
