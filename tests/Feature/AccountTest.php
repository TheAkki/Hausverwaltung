<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountTransfer;
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
 * Covers Konto (Account) CRUD scoped to a ManagementGroup, gated by its own
 * kontosystem_enabled flag.
 */
class AccountTest extends TestCase
{
    use RefreshDatabase;

    private function makeManagementGroup(bool $kontosystemEnabled = true): ManagementGroup
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();

        return ManagementGroup::create([
            'description' => 'Test Group',
            'user_group_id' => $group->id,
            'kontosystem_enabled' => $kontosystemEnabled,
        ]);
    }

    public function test_creating_an_account_requires_the_kontosystem_to_be_enabled(): void
    {
        $managementGroup = $this->makeManagementGroup(kontosystemEnabled: false);
        $user = $managementGroup->userGroup->users()->first();

        $response = $this->actingAs($user)->get(route('accounts.create', ['management_group_id' => $managementGroup->id]));

        $response->assertNotFound();
    }

    public function test_the_create_form_preselects_default_for_the_first_account(): void
    {
        $managementGroup = $this->makeManagementGroup();
        $user = $managementGroup->userGroup->users()->first();

        $response = $this->actingAs($user)->get(route('accounts.create', ['management_group_id' => $managementGroup->id]));

        $response->assertOk();
        $this->assertMatchesRegularExpression('/id="is_default"[^>]*checked/', $response->getContent());
    }

    public function test_the_create_form_does_not_preselect_default_when_an_account_already_exists(): void
    {
        $managementGroup = $this->makeManagementGroup();
        $user = $managementGroup->userGroup->users()->first();
        $managementGroup->accounts()->create(['name' => 'Girokonto', 'is_default' => true]);

        $response = $this->actingAs($user)->get(route('accounts.create', ['management_group_id' => $managementGroup->id]));

        $response->assertOk();
        $this->assertDoesNotMatchRegularExpression('/id="is_default"[^>]*checked/', $response->getContent());
    }

    public function test_an_account_can_be_created_and_marked_as_default(): void
    {
        $managementGroup = $this->makeManagementGroup();
        $user = $managementGroup->userGroup->users()->first();

        $response = $this->actingAs($user)->post(route('accounts.store'), [
            'management_group_id' => $managementGroup->id,
            'name' => 'Girokonto',
            'initial_balance' => 500,
            'is_default' => '1',
        ]);

        $response->assertRedirect(route('management-groups.show', $managementGroup));

        $account = Account::where('management_group_id', $managementGroup->id)->first();
        $this->assertNotNull($account);
        $this->assertSame('Girokonto', $account->name);
        $this->assertSame(500.0, (float) $account->initial_balance);
        $this->assertTrue($account->is_default);
    }

    public function test_an_account_can_be_created_with_a_description(): void
    {
        $managementGroup = $this->makeManagementGroup();
        $user = $managementGroup->userGroup->users()->first();

        $this->actingAs($user)->post(route('accounts.store'), [
            'management_group_id' => $managementGroup->id,
            'name' => 'Girokonto',
            'description' => 'Laufendes Konto bei der Sparkasse',
        ]);

        $account = Account::where('management_group_id', $managementGroup->id)->first();
        $this->assertSame('Laufendes Konto bei der Sparkasse', $account->description);
    }

    public function test_an_accounts_description_can_be_updated(): void
    {
        $managementGroup = $this->makeManagementGroup();
        $user = $managementGroup->userGroup->users()->first();
        $account = $managementGroup->accounts()->create(['name' => 'Girokonto']);

        $this->actingAs($user)->put(route('accounts.update', $account), [
            'name' => 'Girokonto',
            'description' => 'Neue Beschreibung',
        ]);

        $this->assertSame('Neue Beschreibung', $account->fresh()->description);
    }

    public function test_marking_a_new_account_as_default_unsets_the_previous_default(): void
    {
        $managementGroup = $this->makeManagementGroup();
        $user = $managementGroup->userGroup->users()->first();

        $first = $managementGroup->accounts()->create(['name' => 'Girokonto', 'is_default' => true]);

        $this->actingAs($user)->post(route('accounts.store'), [
            'management_group_id' => $managementGroup->id,
            'name' => 'Rücklagenkonto',
            'is_default' => '1',
        ]);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue(Account::where('name', 'Rücklagenkonto')->first()->is_default);
    }

    public function test_an_account_can_be_renamed_via_the_edit_form(): void
    {
        $managementGroup = $this->makeManagementGroup();
        $user = $managementGroup->userGroup->users()->first();
        $account = $managementGroup->accounts()->create(['name' => 'Girokonto']);

        $response = $this->actingAs($user)->put(route('accounts.update', $account), [
            'name' => 'Hauptkonto',
            'initial_balance' => 100,
        ]);

        $response->assertRedirect(route('management-groups.show', $managementGroup));
        $this->assertSame('Hauptkonto', $account->fresh()->name);
    }

    public function test_a_user_from_a_different_group_cannot_edit_another_groups_account(): void
    {
        $managementGroup = $this->makeManagementGroup();
        $account = $managementGroup->accounts()->create(['name' => 'Girokonto']);

        $otherUser = User::factory()->create();

        $response = $this->actingAs($otherUser)->put(route('accounts.update', $account), [
            'name' => 'Hijacked',
        ]);

        $response->assertForbidden();
        $this->assertSame('Girokonto', $account->fresh()->name);
    }

    public function test_an_account_can_be_deleted(): void
    {
        $managementGroup = $this->makeManagementGroup();
        $user = $managementGroup->userGroup->users()->first();
        $account = $managementGroup->accounts()->create(['name' => 'Girokonto']);

        $response = $this->actingAs($user)->delete(route('accounts.destroy', $account));

        $response->assertRedirect(route('management-groups.show', $managementGroup));
        $this->assertNull(Account::find($account->id));
    }

    public function test_an_account_can_have_costs_rent_payments_and_transfers_attached(): void
    {
        $managementGroup = $this->makeManagementGroup();
        $account = $managementGroup->accounts()->create(['name' => 'Girokonto']);
        $otherAccount = $managementGroup->accounts()->create(['name' => 'Rücklage']);

        $issuer = InvoiceIssuer::create([
            'name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B',
        ]);
        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id, 'amount' => 100,
            'invoice_date' => '2026-01-01', 'periodicity' => 'once',
        ]);
        $cost = $managementGroup->costs()->create([
            'invoice_id' => $invoice->id, 'cost_category_id' => CostCategory::create(['name' => 'Sonstiges', 'is_relevant_for_billing' => true])->id,
            'billing_interval_id' => $managementGroup->billingIntervals()->first()->id,
            'amount' => 100, 'period_date' => '2026-01-01',
        ]);
        $account->costs()->attach($cost->id, ['amount' => 100]);
        $this->assertTrue($account->costs->contains($cost->id));

        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $managementUnit = $managementGroup->managementUnits()->create(['street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $rentalUnit = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'is_relevant_for_billing' => true]);
        $lease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);
        $rentPayment = $lease->leaseIntervals->first()->rentPayments()->create(['period_date' => '2026-01-01', 'amount' => 500]);
        $account->rentPayments()->attach($rentPayment->id, ['amount' => 500]);
        $this->assertTrue($account->rentPayments->contains($rentPayment->id));

        $transferOut = AccountTransfer::create([
            'from_account_id' => $account->id, 'to_account_id' => $otherAccount->id,
            'amount' => 50, 'transfer_date' => '2026-01-15',
        ]);
        $this->assertTrue($account->fresh()->transfersOut->contains($transferOut->id));
        $this->assertTrue($otherAccount->fresh()->transfersIn->contains($transferOut->id));
        $this->assertSame($account->id, $transferOut->fromAccount->id);
        $this->assertSame($otherAccount->id, $transferOut->toAccount->id);
    }
}
