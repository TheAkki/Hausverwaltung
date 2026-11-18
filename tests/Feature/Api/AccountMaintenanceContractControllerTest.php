<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\AccountTransfer;
use App\Models\MaintenanceContract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\CreatesApiFixtures;
use Tests\TestCase;

// KI-generiert
class AccountMaintenanceContractControllerTest extends TestCase
{
    use CreatesApiFixtures, RefreshDatabase;

    public function test_accounts_can_be_created_updated_and_deleted_with_a_single_default(): void
    {
        $managementGroup = $this->makeManagementGroup($this->apiUser(), kontosystem: true);

        $giro = $this->postJson(route('api.accounts.store'), [
            'management_group_id' => $managementGroup->id, 'name' => 'Giro', 'initial_balance' => 100, 'is_default' => true,
        ])->assertCreated()->json('id');
        $reserve = $this->postJson(route('api.accounts.store'), [
            'management_group_id' => $managementGroup->id, 'name' => 'Rücklage', 'is_default' => true,
        ])->assertCreated()->json('id');
        $this->assertEquals(0, Account::find($reserve)->initial_balance);

        $this->assertFalse(Account::find($giro)->is_default);

        $this->putJson(route('api.accounts.update', $giro), ['name' => 'Girokonto', 'is_default' => true])->assertOk();
        $this->assertFalse(Account::find($reserve)->is_default);

        $this->deleteJson(route('api.accounts.destroy', $reserve))->assertNoContent();
        $this->assertNull(Account::find($reserve));
    }

    public function test_accounts_need_an_enabled_kontosystem(): void
    {
        $managementGroup = $this->makeManagementGroup($this->apiUser());

        $this->postJson(route('api.accounts.store'), ['management_group_id' => $managementGroup->id, 'name' => 'Giro'])->assertNotFound();
    }

    public function test_a_transfer_moves_money_between_accounts_of_the_same_group(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user, kontosystem: true);
        $from = $managementGroup->accounts()->create(['name' => 'A']);
        $to = $managementGroup->accounts()->create(['name' => 'B']);
        $foreign = $this->makeManagementGroup($user, kontosystem: true)->accounts()->create(['name' => 'C']);
        $payload = ['management_group_id' => $managementGroup->id, 'from_account_id' => $from->id, 'amount' => 50, 'transfer_date' => '2026-03-01'];

        $this->postJson(route('api.account-transfers.store'), [...$payload, 'to_account_id' => $to->id])->assertCreated();
        $this->postJson(route('api.account-transfers.store'), [...$payload, 'to_account_id' => $foreign->id])
            ->assertUnprocessable()->assertJsonValidationErrors('to_account_id');
        $this->postJson(route('api.account-transfers.store'), [...$payload, 'to_account_id' => $from->id])
            ->assertUnprocessable()->assertJsonValidationErrors('to_account_id');

        $this->assertSame(1, AccountTransfer::count());
    }

    public function test_foreign_accounts_are_inaccessible(): void
    {
        $managementGroup = $this->makeManagementGroup(User::factory()->create(), kontosystem: true);
        $account = $managementGroup->accounts()->create(['name' => 'A']);
        $this->apiUser();

        $this->postJson(route('api.accounts.store'), ['management_group_id' => $managementGroup->id, 'name' => 'X'])->assertNotFound();
        $this->putJson(route('api.accounts.update', $account), ['name' => 'X'])->assertForbidden();
        $this->deleteJson(route('api.accounts.destroy', $account))->assertForbidden();
    }

    public function test_maintenance_contracts_can_be_created_updated_and_deleted(): void
    {
        $managementGroup = $this->makeManagementGroup($this->apiUser());
        $payload = ['name' => 'Heizung', 'type' => 'Wartung', 'provider' => 'Firma', 'start_date' => '2026-01-01'];

        $id = $this->postJson(route('api.maintenance-contracts.store'), [...$payload, 'management_group_id' => $managementGroup->id])
            ->assertCreated()->json('id');
        $this->putJson(route('api.maintenance-contracts.update', $id), [...$payload, 'end_date' => '2025-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->putJson(route('api.maintenance-contracts.update', $id), [...$payload, 'provider' => 'Andere'])
            ->assertOk()->assertJsonPath('provider', 'Andere');
        $this->deleteJson(route('api.maintenance-contracts.destroy', $id))->assertNoContent();
        $this->assertNull(MaintenanceContract::find($id));
    }

    public function test_foreign_maintenance_contracts_are_inaccessible(): void
    {
        $managementGroup = $this->makeManagementGroup(User::factory()->create());
        $contract = $managementGroup->maintenanceContracts()->create([
            'name' => 'Heizung', 'type' => 'Wartung', 'provider' => 'Firma', 'start_date' => '2026-01-01',
        ]);
        $this->apiUser();

        $this->postJson(route('api.maintenance-contracts.store'), [
            'management_group_id' => $managementGroup->id, 'name' => 'X', 'type' => 'X', 'provider' => 'X', 'start_date' => '2026-01-01',
        ])->assertNotFound();
        $this->putJson(route('api.maintenance-contracts.update', $contract), [
            'name' => 'X', 'type' => 'X', 'provider' => 'X', 'start_date' => '2026-01-01',
        ])->assertForbidden();
        $this->deleteJson(route('api.maintenance-contracts.destroy', $contract))->assertForbidden();
    }
}
