<?php

namespace Tests\Unit;

use App\Models\CostCategory;
use App\Models\ManagementGroup;
use App\Models\User;
use Database\Seeders\CostCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CostCategorySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_all_expected_categories(): void
    {
        $this->seed(CostCategorySeeder::class);

        $this->assertSame(
            count(CostCategorySeeder::CATEGORIES) + count(CostCategorySeeder::NON_BILLABLE_CATEGORIES),
            CostCategory::count()
        );

        foreach (CostCategorySeeder::CATEGORIES as $name) {
            $this->assertDatabaseHas('cost_categories', ['name' => $name]);
        }

        foreach (CostCategorySeeder::NON_BILLABLE_CATEGORIES as $name) {
            $this->assertDatabaseHas('cost_categories', ['name' => $name]);
        }
    }

    public function test_billing_relevance_flags_are_set_correctly(): void
    {
        $this->seed(CostCategorySeeder::class);

        $this->assertSame(
            count(CostCategorySeeder::NON_BILLABLE_CATEGORIES),
            CostCategory::where('is_relevant_for_billing', false)->count()
        );

        foreach (CostCategorySeeder::NON_BILLABLE_CATEGORIES as $name) {
            $this->assertDatabaseHas('cost_categories', ['name' => $name, 'is_relevant_for_billing' => false]);
        }

        foreach (CostCategorySeeder::CATEGORIES as $name) {
            $this->assertDatabaseHas('cost_categories', ['name' => $name, 'is_relevant_for_billing' => true]);
        }
    }

    // KI-generiert
    public function test_only_the_non_tax_relevant_categories_are_created_without_tax_relevance(): void
    {
        $this->seed(CostCategorySeeder::class);

        $this->assertSame(
            CostCategorySeeder::NON_TAX_RELEVANT_CATEGORIES,
            CostCategory::where('is_tax_relevant', false)->pluck('name')->all()
        );
        $this->assertDatabaseHas('cost_categories', ['name' => 'Tilgung', 'is_tax_relevant' => false]);
        $this->assertDatabaseHas('cost_categories', ['name' => 'Zinsen', 'is_tax_relevant' => true]);
    }

    // KI-generiert
    public function test_a_rerun_keeps_a_manually_changed_tax_flag(): void
    {
        $this->seed(CostCategorySeeder::class);
        CostCategory::where('name', 'Tilgung')->update(['is_tax_relevant' => true]);

        $this->seed(CostCategorySeeder::class);

        $this->assertDatabaseHas('cost_categories', ['name' => 'Tilgung', 'is_tax_relevant' => true]);
    }

    public function test_it_is_idempotent_when_run_twice(): void
    {
        $this->seed(CostCategorySeeder::class);
        $this->seed(CostCategorySeeder::class);

        $this->assertSame(
            count(CostCategorySeeder::CATEGORIES) + count(CostCategorySeeder::NON_BILLABLE_CATEGORIES),
            CostCategory::count()
        );
    }

    public function test_a_cost_category_can_be_linked_to_management_groups_with_an_allocation_key(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $category = CostCategory::create(['name' => 'Wasserversorgung', 'is_relevant_for_billing' => true]);

        $category->managementGroups()->attach($managementGroup->id, ['allocation_key' => 'person_count']);

        $this->assertSame(
            'person_count',
            $category->managementGroups()->first()->pivot->allocation_key
        );
    }
}
