<?php

namespace Tests\Feature;

use App\Models\Landlord;
use App\Models\ManagementGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the Vermieter (landlord): the account-level Vermieter, a Verwaltungsgruppen-
 * specific override, the effective-resolution between them, and the edit UI.
 */
class LandlordTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: ManagementGroup}
     */
    private function makeGroup(): array
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);

        return [$user, $managementGroup];
    }

    public function test_the_effective_landlord_is_the_account_level_one_when_no_override_exists(): void
    {
        [, $managementGroup] = $this->makeGroup();
        $accountLandlord = Landlord::create(['user_group_id' => $managementGroup->user_group_id, 'name' => 'Konto-Vermieter']);

        $this->assertTrue($managementGroup->effectiveLandlord()->is($accountLandlord));
    }

    public function test_a_verwaltungsgruppe_override_takes_precedence_over_the_account_landlord(): void
    {
        [, $managementGroup] = $this->makeGroup();
        Landlord::create(['user_group_id' => $managementGroup->user_group_id, 'name' => 'Konto-Vermieter']);
        $override = Landlord::create(['management_group_id' => $managementGroup->id, 'name' => 'VG-Vermieter']);

        $this->assertTrue($managementGroup->effectiveLandlord()->is($override));
    }

    public function test_the_effective_landlord_is_null_when_none_configured(): void
    {
        [, $managementGroup] = $this->makeGroup();

        $this->assertNull($managementGroup->effectiveLandlord());
    }

    public function test_the_account_level_landlord_can_be_saved_via_the_edit_form(): void
    {
        [$user, $managementGroup] = $this->makeGroup();

        $response = $this->actingAs($user)->put(route('management-groups.landlord.update', $managementGroup), [
            'scope' => 'account',
            'name' => 'Neuer Kontovermieter',
            'salutation' => 'Herr',
            'city' => 'Musterstadt',
        ]);

        $response->assertRedirect(route('management-groups.landlord.edit', $managementGroup));
        $landlord = Landlord::where('user_group_id', $managementGroup->user_group_id)->whereNull('management_group_id')->first();
        $this->assertNotNull($landlord);
        $this->assertSame('Neuer Kontovermieter', $landlord->name);
        $this->assertSame('Herr', $landlord->salutation);
    }

    public function test_an_override_can_be_saved_and_removed_via_the_edit_form(): void
    {
        [$user, $managementGroup] = $this->makeGroup();

        $this->actingAs($user)->put(route('management-groups.landlord.update', $managementGroup), [
            'scope' => 'override',
            'name' => 'Override-Vermieter',
        ])->assertRedirect(route('management-groups.landlord.edit', $managementGroup));

        $this->assertNotNull($managementGroup->fresh()->landlord);

        $this->actingAs($user)->put(route('management-groups.landlord.update', $managementGroup), [
            'scope' => 'override',
            'remove_override' => '1',
        ])->assertRedirect(route('management-groups.landlord.edit', $managementGroup));

        $this->assertNull($managementGroup->fresh()->landlord);
    }

    public function test_the_edit_form_requires_a_name(): void
    {
        [$user, $managementGroup] = $this->makeGroup();

        $response = $this->actingAs($user)->put(route('management-groups.landlord.update', $managementGroup), [
            'scope' => 'account',
            'city' => 'Ohne Name',
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertSame(0, Landlord::count());
    }

    public function test_a_user_cannot_edit_the_landlord_of_a_management_group_they_cannot_access(): void
    {
        [, $managementGroup] = $this->makeGroup();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->get(route('management-groups.landlord.edit', $managementGroup))->assertForbidden();
        $this->actingAs($outsider)->put(route('management-groups.landlord.update', $managementGroup), [
            'scope' => 'account', 'name' => 'X',
        ])->assertForbidden();
    }

    public function test_an_invalid_iban_checksum_is_rejected(): void
    {
        [$user, $managementGroup] = $this->makeGroup();

        $response = $this->actingAs($user)->put(route('management-groups.landlord.update', $managementGroup), [
            'scope' => 'account',
            'name' => 'Vermieter',
            'iban' => 'DE89370400440532013001',
        ]);

        $response->assertSessionHasErrors('iban');
        $this->assertSame(0, Landlord::count());
    }

    public function test_the_edit_page_renders(): void
    {
        [$user, $managementGroup] = $this->makeGroup();

        $this->actingAs($user)->get(route('management-groups.landlord.edit', $managementGroup))->assertOk();
    }

    public function test_a_landlord_belongs_to_its_owning_group_or_management_group(): void
    {
        [, $managementGroup] = $this->makeGroup();

        $accountLandlord = Landlord::create(['user_group_id' => $managementGroup->user_group_id, 'name' => 'Konto']);
        $this->assertSame($managementGroup->user_group_id, $accountLandlord->userGroup->id);

        $override = Landlord::create(['management_group_id' => $managementGroup->id, 'name' => 'Override']);
        $this->assertTrue($override->managementGroup->is($managementGroup));
    }
}
