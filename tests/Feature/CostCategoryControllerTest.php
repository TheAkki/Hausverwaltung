<?php

namespace Tests\Feature;

use App\Models\CostCategory;
use App\Models\User;
use Database\Seeders\CostCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers listing Kostenkategorien and toggling their is_relevant_for_billing flag. Die
 * systemweiten Kategorien aus CostCategorySeeder (user_group_id null) sind für jeden
 * sichtbar, aber von niemandem bearbeitbar – nur eine dem eigenen Account zugeordnete
 * Kategorie lässt sich toggeln (CostCategoryPolicy).
 */
class CostCategoryControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeOwnCategory(User $user, array $attributes = []): CostCategory
    {
        return CostCategory::create([
            'user_group_id' => $user->groups()->first()->id,
            'name' => 'Eigene Kategorie',
            'is_relevant_for_billing' => true,
            'is_tax_relevant' => true,
            ...$attributes,
        ]);
    }

    public function test_the_index_lists_systemweite_and_own_cost_categories(): void
    {
        $this->seed(CostCategorySeeder::class);
        $user = User::factory()->create();
        $this->makeOwnCategory($user, ['name' => 'Meine Kategorie']);

        $response = $this->actingAs($user)->get(route('cost-categories.index'));

        $response->assertOk();
        $response->assertSee('Heizkosten');
        $response->assertSee('Zinsen');
        $response->assertSee('Meine Kategorie');
    }

    public function test_the_index_does_not_list_another_accounts_cost_category(): void
    {
        $owner = User::factory()->create();
        $this->makeOwnCategory($owner, ['name' => 'Fremde Kategorie']);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('cost-categories.index'));

        $response->assertOk();
        $response->assertDontSee('Fremde Kategorie');
    }

    public function test_the_billing_relevance_flag_can_be_toggled_off_on_an_own_category(): void
    {
        $user = User::factory()->create();
        $category = $this->makeOwnCategory($user, ['is_relevant_for_billing' => true]);

        $response = $this->actingAs($user)->patch(route('cost-categories.update', $category), []);

        $response->assertRedirect(route('cost-categories.index'));
        $this->assertFalse($category->fresh()->is_relevant_for_billing);
    }

    public function test_the_billing_relevance_flag_can_be_toggled_on_on_an_own_category(): void
    {
        $user = User::factory()->create();
        $category = $this->makeOwnCategory($user, ['is_relevant_for_billing' => false]);

        $response = $this->actingAs($user)->patch(route('cost-categories.update', $category), [
            'is_relevant_for_billing' => '1',
        ]);

        $response->assertRedirect(route('cost-categories.index'));
        $this->assertTrue($category->fresh()->is_relevant_for_billing);
    }

    public function test_the_tax_relevance_flag_can_be_toggled_on_an_own_category(): void
    {
        $user = User::factory()->create();
        $category = $this->makeOwnCategory($user, ['is_tax_relevant' => false]);

        $response = $this->actingAs($user)->patch(route('cost-categories.update', $category), [
            'is_relevant_for_billing' => '1',
            'is_tax_relevant' => '1',
        ]);

        $response->assertRedirect(route('cost-categories.index'));
        $this->assertTrue($category->fresh()->is_tax_relevant);
    }

    /**
     * Die systemweiten Kategorien aus CostCategorySeeder (user_group_id null) sind zwar
     * für jeden sichtbar, aber von niemandem direkt bearbeitbar – sonst könnte ein
     * einzelner Account die globalen Einstellungen für alle anderen Accounts verändern.
     */
    public function test_a_systemweite_cost_category_cannot_be_updated(): void
    {
        $this->seed(CostCategorySeeder::class);
        $user = User::factory()->create();
        $category = CostCategory::where('name', 'Heizkosten')->first();
        $this->assertTrue($category->is_relevant_for_billing);

        $response = $this->actingAs($user)->patch(route('cost-categories.update', $category), []);

        $response->assertForbidden();
        $this->assertTrue($category->fresh()->is_relevant_for_billing);
    }

    public function test_a_user_cannot_update_another_accounts_cost_category(): void
    {
        $owner = User::factory()->create();
        $category = $this->makeOwnCategory($owner, ['is_relevant_for_billing' => true]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patch(route('cost-categories.update', $category), []);

        $response->assertForbidden();
        $this->assertTrue($category->fresh()->is_relevant_for_billing);
    }

    public function test_a_cost_category_is_tax_relevant_by_default(): void
    {
        $this->seed(CostCategorySeeder::class);

        $this->assertTrue(CostCategory::where('name', 'Heizkosten')->first()->is_tax_relevant);
    }

    public function test_the_create_form_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('cost-categories.create'));

        $response->assertOk();
        $response->assertSee('Neue Kostenkategorie');
    }

    public function test_a_cost_category_can_be_created(): void
    {
        $user = User::factory()->create();
        $userGroup = $user->groups()->first();

        $response = $this->actingAs($user)->post(route('cost-categories.store'), [
            'user_group_id' => $userGroup->id,
            'name' => 'Gartenpflege',
            'is_relevant_for_billing' => '1',
            'is_tax_relevant' => '1',
        ]);

        $response->assertRedirect(route('cost-categories.index'));
        $category = CostCategory::where('name', 'Gartenpflege')->first();
        $this->assertNotNull($category);
        $this->assertSame($userGroup->id, $category->user_group_id);
        $this->assertTrue($category->is_relevant_for_billing);
        $this->assertTrue($category->is_tax_relevant);
    }

    /**
     * Both flags default to false when their checkbox is left unchecked (absent from
     * the request), matching update()'s existing behaviour.
     */
    public function test_a_cost_category_can_be_created_with_both_flags_off(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('cost-categories.store'), [
            'user_group_id' => $user->groups()->first()->id,
            'name' => 'Sonstiges',
        ]);

        $category = CostCategory::where('name', 'Sonstiges')->first();
        $this->assertNotNull($category);
        $this->assertFalse($category->is_relevant_for_billing);
        $this->assertFalse($category->is_tax_relevant);
    }

    public function test_a_cost_category_cannot_be_assigned_to_a_group_the_user_is_not_a_member_of(): void
    {
        $user = User::factory()->create();
        $outsider = User::factory()->create();
        $outsidersGroup = $outsider->groups()->first();

        $response = $this->actingAs($user)->post(route('cost-categories.store'), [
            'user_group_id' => $outsidersGroup->id,
            'name' => 'Fremdzuweisung',
        ]);

        $response->assertSessionHasErrors('user_group_id');
        $this->assertNull(CostCategory::where('name', 'Fremdzuweisung')->first());
    }

    /**
     * TestDataSeeder/BackupService/CostCategorySeeder all look Kostenkategorien up by
     * name (e.g. firstOrCreate/where('name', ...)) – a duplicate name would silently
     * make those lookups ambiguous, so creating one is rejected.
     */
    public function test_creating_a_cost_category_with_a_duplicate_name_is_rejected(): void
    {
        $this->seed(CostCategorySeeder::class);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('cost-categories.store'), [
            'user_group_id' => $user->groups()->first()->id,
            'name' => 'Heizkosten',
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertSame(1, CostCategory::where('name', 'Heizkosten')->count());
    }

    /**
     * Regression guard: the index page renders two independent auto-submitting
     * toggle forms per row (Abrechnungsrelevant/Steuerrelevant). Each form must pin
     * the OTHER flag's current value as a hidden field, or flipping one toggle would
     * silently reset the other back to false (since CostCategoryController::update()
     * treats an absent checkbox as false).
     */
    public function test_the_index_page_pins_each_toggles_other_flag_to_its_current_value(): void
    {
        $user = User::factory()->create();
        $category = $this->makeOwnCategory($user, [
            'name' => 'Regression-Test-Kategorie',
            'is_relevant_for_billing' => true,
            'is_tax_relevant' => false,
        ]);

        $html = $this->actingAs($user)->get(route('cost-categories.index'))->assertOk()->getContent();

        $rowStart = strpos($html, 'Regression-Test-Kategorie');
        $this->assertNotFalse($rowStart);
        $firstFormEnd = strpos($html, '</form>', $rowStart);
        $firstForm = substr($html, $rowStart, $firstFormEnd - $rowStart);
        $secondFormEnd = strpos($html, '</form>', $firstFormEnd + 7);
        $secondForm = substr($html, $firstFormEnd + 7, $secondFormEnd - ($firstFormEnd + 7));

        // Abrechnungsrelevant-Form: pins is_tax_relevant to its current value (false) –
        // no "value=1" for it must appear anywhere in this form.
        $this->assertStringContainsString('name="is_tax_relevant" value="0"', $firstForm);
        $this->assertStringNotContainsString('name="is_tax_relevant" value="1"', $firstForm);

        // Steuerrelevant-Form: pins is_relevant_for_billing to its current value (true) –
        // no "value=0" for it must appear anywhere in this form.
        $this->assertStringContainsString('name="is_relevant_for_billing" value="1"', $secondForm);
        $this->assertStringNotContainsString('name="is_relevant_for_billing" value="0"', $secondForm);
    }

    /**
     * Konzept.md "### Kosten": subcategories are created by choosing an existing
     * category as "Überkategorie" – the full name becomes "<Überkategorie>::<name>",
     * with is_relevant_for_billing/is_tax_relevant inherited from the Hauptkategorie
     * (CostCategoryObserver) regardless of what's submitted for those two fields.
     */
    public function test_a_subcategory_can_be_created_via_parent_id_and_inherits_the_hauptkategorie_flags(): void
    {
        $this->seed(CostCategorySeeder::class);
        $user = User::factory()->create();
        $main = CostCategory::where('name', 'Hauswart')->first();

        $response = $this->actingAs($user)->post(route('cost-categories.store'), [
            'user_group_id' => $user->groups()->first()->id,
            'parent_id' => $main->id,
            'name' => 'Winterdienst',
            // Deliberately the opposite of "Hauswart" – must be ignored.
            'is_relevant_for_billing' => '0',
            'is_tax_relevant' => '0',
        ]);

        $response->assertRedirect(route('cost-categories.index'));
        $category = CostCategory::where('name', 'Hauswart::Winterdienst')->first();
        $this->assertNotNull($category);
        $this->assertSame($main->is_relevant_for_billing, $category->is_relevant_for_billing);
        $this->assertSame($main->is_tax_relevant, $category->is_tax_relevant);
    }

    public function test_a_subcategory_can_be_nested_under_another_subcategory(): void
    {
        $this->seed(CostCategorySeeder::class);
        $user = User::factory()->create();
        $userGroupId = $user->groups()->first()->id;
        $sub = CostCategory::create([
            'user_group_id' => $userGroupId,
            'name' => 'Hauswart::Winterdienst',
            'is_relevant_for_billing' => true,
        ]);

        $this->actingAs($user)->post(route('cost-categories.store'), [
            'user_group_id' => $userGroupId,
            'parent_id' => $sub->id,
            'name' => '2025',
        ]);

        $this->assertNotNull(CostCategory::where('name', 'Hauswart::Winterdienst::2025')->first());
    }

    public function test_a_category_name_may_not_contain_the_subcategory_separator_itself(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('cost-categories.store'), [
            'user_group_id' => $user->groups()->first()->id,
            'name' => 'Hauswart::Winterdienst',
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertNull(CostCategory::where('name', 'Hauswart::Winterdienst')->first());
    }

    public function test_creating_a_subcategory_with_a_duplicate_full_name_is_rejected(): void
    {
        $this->seed(CostCategorySeeder::class);
        $user = User::factory()->create();
        $userGroupId = $user->groups()->first()->id;
        $main = CostCategory::where('name', 'Hauswart')->first();
        CostCategory::create(['user_group_id' => $userGroupId, 'name' => 'Hauswart::Winterdienst', 'is_relevant_for_billing' => true]);

        $response = $this->actingAs($user)->post(route('cost-categories.store'), [
            'user_group_id' => $userGroupId,
            'parent_id' => $main->id,
            'name' => 'Winterdienst',
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertSame(1, CostCategory::where('name', 'Hauswart::Winterdienst')->count());
    }

    public function test_the_index_marks_a_subcategorys_relevance_toggles_as_inherited_and_disabled(): void
    {
        $this->seed(CostCategorySeeder::class);
        $user = User::factory()->create();
        CostCategory::create([
            'user_group_id' => $user->groups()->first()->id,
            'name' => 'Hauswart::Winterdienst',
            'is_relevant_for_billing' => true,
        ]);

        $html = $this->actingAs($user)->get(route('cost-categories.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Winterdienst', $html);
        $rowStart = strpos($html, 'Winterdienst');
        $rowEnd = strpos($html, '</tr>', $rowStart);
        $row = substr($html, $rowStart, $rowEnd - $rowStart);
        $this->assertStringContainsString('disabled', $row);
        $this->assertStringNotContainsString('<form', $row);
    }
}
