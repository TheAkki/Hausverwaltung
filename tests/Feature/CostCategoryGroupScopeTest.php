<?php

// KI-generiert

namespace Tests\Feature;

use App\Models\CostCategory;
use App\Models\Group;
use App\Models\User;
use Database\Seeders\CostCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Kategorienamen sind je Gruppe (samt Systemkategorien) eindeutig; Überkategorie,
 * Hauptkategorie und das Weitergeben der Haken bleiben innerhalb der Gruppe.
 */
class CostCategoryGroupScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $personal;

    private int $shared;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CostCategorySeeder::class);
        $this->user = User::factory()->create();
        $this->personal = $this->user->groups()->first()->id;
        $group = Group::create(['name' => 'Gemeinsam', 'owner_id' => $this->user->id]);
        $group->users()->attach($this->user->id, ['role' => 'admin']);
        $this->shared = $group->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function input(int $groupId, string $name, ?int $parentId = null): array
    {
        return ['user_group_id' => $groupId, 'name' => $name, 'parent_id' => $parentId, 'is_relevant_for_billing' => true, 'is_tax_relevant' => true];
    }

    public function test_the_same_name_may_exist_once_per_group(): void
    {
        $this->actingAs($this->user)->post(route('cost-categories.store'), $this->input($this->personal, 'Lohnkosten'))->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post(route('cost-categories.store'), $this->input($this->shared, 'Lohnkosten'))->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post(route('cost-categories.store'), $this->input($this->shared, 'Lohnkosten'))
            ->assertSessionHasErrors(['name' => 'Eine Kostenkategorie mit diesem Namen existiert bereits.']);

        Sanctum::actingAs($this->user);
        $this->postJson('/api/cost-categories', $this->input($this->personal, 'Lohnkosten'))->assertJsonValidationErrors('name');
        $this->postJson('/api/cost-categories', $this->input($this->personal, 'Heizkosten'))->assertJsonValidationErrors('name');

        $this->assertSame(2, CostCategory::where('name', 'Lohnkosten')->count());
    }

    public function test_a_name_of_another_users_group_neither_blocks_nor_leaks(): void
    {
        $stranger = User::factory()->create();
        CostCategory::create(['user_group_id' => $stranger->groups()->first()->id, 'name' => 'Geheim', 'is_relevant_for_billing' => false, 'is_tax_relevant' => false]);

        $this->actingAs($this->user)->post(route('cost-categories.store'), $this->input($this->personal, 'Geheim'))->assertSessionHasNoErrors();

        Sanctum::actingAs($this->user);
        $this->postJson('/api/cost-categories', $this->input($this->shared, 'Geheim'))->assertCreated();
    }

    public function test_a_parent_must_be_in_the_same_group_or_a_system_category(): void
    {
        $parent = CostCategory::create(['user_group_id' => $this->personal, 'name' => 'Lohnkosten', 'is_relevant_for_billing' => false, 'is_tax_relevant' => true]);
        $system = CostCategory::whereNull('user_group_id')->where('name', 'Hauswart')->sole();

        $this->actingAs($this->user)->post(route('cost-categories.store'), $this->input($this->shared, 'Minijob', $parent->id))
            ->assertSessionHasErrors(['parent_id' => 'Die Überkategorie gehört zu einer anderen Gruppe.']);

        Sanctum::actingAs($this->user);
        $this->postJson('/api/cost-categories', $this->input($this->shared, 'Minijob', $parent->id))->assertJsonValidationErrors('parent_id');
        $this->postJson('/api/cost-categories', $this->input($this->shared, 'Winterdienst', $system->id))
            ->assertCreated()->assertJsonPath('name', 'Hauswart::Winterdienst');
        $this->postJson('/api/cost-categories', $this->input($this->personal, 'Minijob', $parent->id))
            ->assertCreated()->assertJsonPath('name', 'Lohnkosten::Minijob')->assertJsonPath('is_relevant_for_billing', false);
    }

    public function test_main_category_and_flag_cascade_stay_within_the_group(): void
    {
        $flags = ['is_relevant_for_billing' => false, 'is_tax_relevant' => true];
        $personalMain = CostCategory::create(['user_group_id' => $this->personal, 'name' => 'Lohnkosten', ...$flags]);
        $sharedMain = CostCategory::create(['user_group_id' => $this->shared, 'name' => 'Lohnkosten', 'is_relevant_for_billing' => true, 'is_tax_relevant' => false]);
        $personalSub = CostCategory::create(['user_group_id' => $this->personal, 'name' => 'Lohnkosten::Minijob', 'is_relevant_for_billing' => true, 'is_tax_relevant' => true]);
        $sharedSub = CostCategory::create(['user_group_id' => $this->shared, 'name' => 'Lohnkosten::Minijob', ...$flags]);

        $this->assertTrue($personalSub->mainCategory()->is($personalMain));
        $this->assertTrue($sharedSub->mainCategory()->is($sharedMain));
        $this->assertFalse($personalSub->is_relevant_for_billing);
        $this->assertTrue($sharedSub->is_relevant_for_billing);

        $this->actingAs($this->user)->patch(route('cost-categories.update', $personalMain), ['is_relevant_for_billing' => true, 'is_tax_relevant' => false]);

        $this->assertTrue($personalSub->fresh()->is_relevant_for_billing);
        $this->assertFalse($personalSub->fresh()->is_tax_relevant);
        $this->assertTrue($sharedSub->fresh()->is_relevant_for_billing);
        $this->assertFalse($sharedSub->fresh()->is_tax_relevant);
    }

    public function test_a_subcategory_of_a_system_category_follows_it_in_every_group(): void
    {
        $system = CostCategory::whereNull('user_group_id')->where('name', 'Hauswart')->sole();
        $sub = CostCategory::create(['user_group_id' => $this->shared, 'name' => 'Hauswart::Winterdienst', 'is_relevant_for_billing' => false, 'is_tax_relevant' => false]);

        $this->assertTrue($sub->mainCategory()->is($system));
        $system->update(['is_relevant_for_billing' => ! $system->is_relevant_for_billing]);

        $this->assertSame($system->is_relevant_for_billing, $sub->fresh()->is_relevant_for_billing);
    }
}
