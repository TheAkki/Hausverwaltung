<?php

namespace Tests\Feature;

use App\Models\AccountTransfer;
use App\Models\ManagementGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers booking-neutral transfers of money between two Konten of the same
 * ManagementGroup.
 */
class AccountTransferTest extends TestCase
{
    use RefreshDatabase;

    private function makeManagementGroup(): ManagementGroup
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();

        return ManagementGroup::create([
            'description' => 'Test Group',
            'user_group_id' => $group->id,
            'kontosystem_enabled' => true,
        ]);
    }

    public function test_a_transfer_between_two_konten_of_the_same_group_is_recorded(): void
    {
        $managementGroup = $this->makeManagementGroup();
        $user = $managementGroup->userGroup->users()->first();
        $from = $managementGroup->accounts()->create(['name' => 'Girokonto']);
        $to = $managementGroup->accounts()->create(['name' => 'Rücklagenkonto']);

        $response = $this->actingAs($user)->post(route('account-transfers.store'), [
            'management_group_id' => $managementGroup->id,
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => 250.50,
            'transfer_date' => '2026-03-01',
            'comment' => 'Rücklage aufstocken',
        ]);

        $response->assertRedirect(route('management-groups.show', $managementGroup));

        $transfer = AccountTransfer::first();
        $this->assertNotNull($transfer);
        $this->assertSame($from->id, $transfer->from_account_id);
        $this->assertSame($to->id, $transfer->to_account_id);
        $this->assertSame(250.5, (float) $transfer->amount);
    }

    public function test_transferring_to_the_same_account_is_rejected(): void
    {
        $managementGroup = $this->makeManagementGroup();
        $user = $managementGroup->userGroup->users()->first();
        $account = $managementGroup->accounts()->create(['name' => 'Girokonto']);

        $response = $this->actingAs($user)->post(route('account-transfers.store'), [
            'management_group_id' => $managementGroup->id,
            'from_account_id' => $account->id,
            'to_account_id' => $account->id,
            'amount' => 100,
            'transfer_date' => '2026-03-01',
        ]);

        $response->assertSessionHasErrors('to_account_id');
    }

    public function test_transferring_between_konten_of_different_groups_is_rejected(): void
    {
        $managementGroupA = $this->makeManagementGroup();
        $managementGroupB = $this->makeManagementGroup();
        $user = $managementGroupA->userGroup->users()->first();

        $from = $managementGroupA->accounts()->create(['name' => 'Girokonto A']);
        $to = $managementGroupB->accounts()->create(['name' => 'Girokonto B']);

        $response = $this->actingAs($user)->post(route('account-transfers.store'), [
            'management_group_id' => $managementGroupA->id,
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => 100,
            'transfer_date' => '2026-03-01',
        ]);

        $response->assertSessionHasErrors('to_account_id');
        $this->assertSame(0, AccountTransfer::count());
    }
}
