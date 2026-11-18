<?php

namespace Tests\Feature\Api;

use App\Models\CostCategory;
use App\Models\Landlord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\CreatesApiFixtures;
use Tests\TestCase;

// KI-generiert
class CostCategoryLandlordControllerTest extends TestCase
{
    use CreatesApiFixtures, RefreshDatabase;

    public function test_categories_and_subcategories_can_be_created_listed_shown_and_updated(): void
    {
        $user = $this->apiUser();
        $groupId = $this->groupOf($user)->id;

        $parent = $this->postJson(route('api.cost-categories.store'), [
            'user_group_id' => $groupId, 'name' => 'Lohnkosten', 'is_relevant_for_billing' => true, 'is_tax_relevant' => true,
        ])->assertCreated()->json();
        $this->postJson(route('api.cost-categories.store'), [
            'user_group_id' => $groupId, 'name' => 'Versicherung', 'parent_id' => $parent['id'],
        ])->assertCreated()->assertJsonPath('name', 'Lohnkosten'.CostCategory::SEPARATOR.'Versicherung');

        $this->getJson(route('api.cost-categories.index'))->assertOk()->assertJsonCount(2);
        $this->getJson(route('api.cost-categories.show', $parent['id']))->assertOk()->assertJsonPath('name', 'Lohnkosten');
        $this->putJson(route('api.cost-categories.update', $parent['id']), ['is_relevant_for_billing' => false])
            ->assertOk()->assertJsonPath('is_relevant_for_billing', false);
    }

    public function test_names_must_be_unique_among_visible_categories_only(): void
    {
        $other = User::factory()->create();
        CostCategory::create(['user_group_id' => $this->groupOf($other)->id, 'name' => 'Heizung']);
        $user = $this->apiUser();
        $groupId = $this->groupOf($user)->id;

        // Eine gleichnamige Kategorie eines anderen Accounts blockiert nicht …
        $this->postJson(route('api.cost-categories.store'), ['user_group_id' => $groupId, 'name' => 'Heizung'])->assertCreated();
        // … eine eigene schon, ebenso ein Name mit Trennzeichen.
        $this->postJson(route('api.cost-categories.store'), ['user_group_id' => $groupId, 'name' => 'Heizung'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson(route('api.cost-categories.store'), ['user_group_id' => $groupId, 'name' => 'A'.CostCategory::SEPARATOR.'B'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_foreign_parents_groups_and_categories_are_rejected(): void
    {
        $other = User::factory()->create();
        $foreign = CostCategory::create(['user_group_id' => $this->groupOf($other)->id, 'name' => 'Fremd']);
        $user = $this->apiUser();

        $this->postJson(route('api.cost-categories.store'), ['user_group_id' => $this->groupOf($other)->id, 'name' => 'X'])
            ->assertUnprocessable()->assertJsonValidationErrors('user_group_id');
        $this->postJson(route('api.cost-categories.store'), ['user_group_id' => $this->groupOf($user)->id, 'name' => 'X', 'parent_id' => $foreign->id])
            ->assertForbidden();
        $this->putJson(route('api.cost-categories.update', $foreign), ['is_tax_relevant' => true])->assertForbidden();
    }

    public function test_the_allocation_key_can_be_set_per_management_group(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);
        $category = CostCategory::create(['name' => 'Wasser']);

        $this->putJson(route('api.management-groups.cost-categories.update', [$managementGroup, $category]), ['allocation_key' => 'area'])
            ->assertOk()->assertJsonPath('0.pivot.allocation_key', 'area');
        $this->putJson(route('api.management-groups.cost-categories.update', [$managementGroup, $category]), ['allocation_key' => 'x'])
            ->assertUnprocessable();

        $foreign = CostCategory::create(['user_group_id' => $this->groupOf(User::factory()->create())->id, 'name' => 'Fremd']);
        $this->putJson(route('api.management-groups.cost-categories.update', [$managementGroup, $foreign]), ['allocation_key' => 'area'])
            ->assertForbidden();
    }

    public function test_the_landlord_can_be_set_for_the_account_and_overridden_per_management_group(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);

        $this->putJson(route('api.landlords.update', $managementGroup), ['scope' => 'account', 'name' => 'Konto-Vermieter'])
            ->assertOk()->assertJsonPath('user_group_id', $managementGroup->user_group_id);
        $this->putJson(route('api.landlords.update', $managementGroup), ['name' => 'Override'])
            ->assertOk()->assertJsonPath('management_group_id', $managementGroup->id);

        $this->getJson(route('api.landlords.show', $managementGroup))->assertOk()
            ->assertJsonPath('account.name', 'Konto-Vermieter')->assertJsonPath('override.name', 'Override');

        $this->putJson(route('api.landlords.update', $managementGroup), ['remove_override' => true])->assertNoContent();
        $this->assertNull($managementGroup->fresh()->landlord);
        $this->putJson(route('api.landlords.update', $managementGroup), [])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_the_account_landlord_can_be_set_directly_on_a_group(): void
    {
        $user = $this->apiUser();
        $group = $this->groupOf($user);

        $this->putJson(route('api.groups.landlord.update', $group), ['name' => 'Vermieter ohne MG'])->assertOk();
        $this->putJson(route('api.groups.landlord.update', $group), ['name' => 'Aktualisiert'])->assertOk();

        $this->assertSame(['Aktualisiert'], Landlord::where('user_group_id', $group->id)->pluck('name')->all());
        $this->putJson(route('api.groups.landlord.update', $this->groupOf(User::factory()->create())), ['name' => 'X'])->assertForbidden();
    }

    public function test_landlords_of_foreign_management_groups_are_inaccessible(): void
    {
        $managementGroup = $this->makeManagementGroup(User::factory()->create());
        $this->apiUser();

        $this->getJson(route('api.landlords.show', $managementGroup))->assertForbidden();
        $this->putJson(route('api.landlords.update', $managementGroup), ['name' => 'X'])->assertForbidden();
    }
}
