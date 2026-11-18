<?php

namespace Tests\Feature;

use App\Models\CostCategory;
use App\Models\ManagementGroup;
use App\Models\User;
use Database\Seeders\CostCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers setting a per-ManagementGroup allocation key (Abrechnungsschlüssel) for a
 * cost category.
 */
class ManagementGroupCostCategoryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_allocation_key_can_be_assigned(): void
    {
        $this->seed(CostCategorySeeder::class);
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $category = CostCategory::where('name', 'Heizkosten')->first();

        $response = $this->actingAs($user)->patch(
            route('management-groups.cost-categories.update', [$managementGroup, $category]),
            ['allocation_key' => 'person_count']
        );

        $response->assertRedirect(route('management-groups.show', $managementGroup));
        $pivot = $managementGroup->costCategories()->find($category->id)->pivot;
        $this->assertSame('person_count', $pivot->allocation_key);
    }

    public function test_the_rental_unit_allocation_key_can_be_assigned(): void
    {
        $this->seed(CostCategorySeeder::class);
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $category = CostCategory::where('name', 'Heizkosten')->first();

        $this->actingAs($user)->patch(
            route('management-groups.cost-categories.update', [$managementGroup, $category]),
            ['allocation_key' => 'rental_unit']
        )->assertRedirect(route('management-groups.show', $managementGroup));

        $this->assertSame('rental_unit', $managementGroup->costCategories()->find($category->id)->pivot->allocation_key);
    }

    public function test_an_invalid_allocation_key_is_rejected(): void
    {
        $this->seed(CostCategorySeeder::class);
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $category = CostCategory::where('name', 'Heizkosten')->first();

        $response = $this->actingAs($user)->patch(
            route('management-groups.cost-categories.update', [$managementGroup, $category]),
            ['allocation_key' => 'not_a_valid_key']
        );

        $response->assertSessionHasErrors('allocation_key');
        $this->assertNull($managementGroup->costCategories()->find($category->id));
    }

    public function test_a_user_outside_the_group_cannot_assign_an_allocation_key(): void
    {
        $this->seed(CostCategorySeeder::class);
        $owner = User::factory()->create();
        $group = $owner->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $category = CostCategory::where('name', 'Heizkosten')->first();

        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->patch(
            route('management-groups.cost-categories.update', [$managementGroup, $category]),
            ['allocation_key' => 'area']
        );

        $response->assertForbidden();
    }

    /**
     * A CostCategory owned by a DIFFERENT Account's Benutzergruppe (route-model-bound,
     * so guessable by id) must not be assignable to this ManagementGroup, even by a
     * user who does own the ManagementGroup itself.
     */
    public function test_a_cost_category_belonging_to_another_accounts_group_cannot_be_assigned(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);

        $otherAccount = User::factory()->create();
        $foreignCategory = CostCategory::create([
            'user_group_id' => $otherAccount->groups()->first()->id,
            'name' => 'Fremde Kategorie',
            'is_relevant_for_billing' => true,
        ]);

        $response = $this->actingAs($user)->patch(
            route('management-groups.cost-categories.update', [$managementGroup, $foreignCategory]),
            ['allocation_key' => 'area']
        );

        $response->assertForbidden();
        $this->assertNull($managementGroup->costCategories()->find($foreignCategory->id));
    }
}
