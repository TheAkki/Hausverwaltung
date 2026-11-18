<?php

namespace Tests\Unit;

use App\Models\CostCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers Konzept.md "### Kosten": "Zu den genannten Kategorien ist es möglich auch
 * Unterkategorien anzulegen. Diese Subkategorie wird dann durch ein "::" von der
 * Überkategorie getrennt. Die Schachteltiefe ist prinzipiell unbegrenzt. Ob eine
 * Unterkategorie abrechnungsrelevant bzw. steuerrelevant ist bestimmt die
 * Hauptkategorie." – the name-parsing helpers on CostCategory plus the
 * CostCategoryObserver that enforces the inheritance rule.
 */
class CostCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_plain_category_is_not_a_subcategory(): void
    {
        $category = CostCategory::create(['name' => 'Hauswart', 'is_relevant_for_billing' => true]);

        $this->assertFalse($category->isSubcategory());
        $this->assertSame(0, $category->depth());
        $this->assertSame('Hauswart', $category->mainCategoryName());
        $this->assertNull($category->parentName());
        $this->assertSame('Hauswart', $category->displayName());
    }

    public function test_name_parsing_helpers_on_a_deeply_nested_subcategory(): void
    {
        $category = CostCategory::create(['name' => 'Hauswart::Winterdienst::2025', 'is_relevant_for_billing' => true]);

        $this->assertTrue($category->isSubcategory());
        $this->assertSame(2, $category->depth());
        $this->assertSame('Hauswart', $category->mainCategoryName());
        $this->assertSame('Hauswart::Winterdienst', $category->parentName());
        $this->assertSame('2025', $category->displayName());
    }

    public function test_creating_a_subcategory_inherits_the_hauptkategorie_flags_regardless_of_submitted_values(): void
    {
        CostCategory::create(['name' => 'Hauswart', 'is_relevant_for_billing' => true, 'is_tax_relevant' => false]);

        $sub = CostCategory::create([
            'name' => 'Hauswart::Winterdienst',
            // Deliberately the opposite of the Hauptkategorie – must be overridden.
            'is_relevant_for_billing' => false,
            'is_tax_relevant' => true,
        ]);

        $this->assertTrue($sub->is_relevant_for_billing);
        $this->assertFalse($sub->is_tax_relevant);
    }

    public function test_updating_a_hauptkategorie_cascades_to_subcategories_at_any_depth(): void
    {
        $main = CostCategory::create(['name' => 'Hauswart', 'is_relevant_for_billing' => true, 'is_tax_relevant' => true]);
        $sub = CostCategory::create(['name' => 'Hauswart::Winterdienst', 'is_relevant_for_billing' => true, 'is_tax_relevant' => true]);
        $subSub = CostCategory::create(['name' => 'Hauswart::Winterdienst::2025', 'is_relevant_for_billing' => true, 'is_tax_relevant' => true]);

        $main->update(['is_relevant_for_billing' => false, 'is_tax_relevant' => false]);

        $this->assertFalse($sub->fresh()->is_relevant_for_billing);
        $this->assertFalse($sub->fresh()->is_tax_relevant);
        $this->assertFalse($subSub->fresh()->is_relevant_for_billing);
        $this->assertFalse($subSub->fresh()->is_tax_relevant);
    }

    /**
     * A category named e.g. "Hauswart2" must not be swept up by the cascade for
     * "Hauswart" – the LIKE pattern is anchored with the SEPARATOR, not a bare prefix.
     */
    public function test_updating_a_hauptkategorie_does_not_affect_an_unrelated_category_with_a_similar_name(): void
    {
        $main = CostCategory::create(['name' => 'Hauswart', 'is_relevant_for_billing' => true]);
        $unrelated = CostCategory::create(['name' => 'Hauswart2', 'is_relevant_for_billing' => true]);

        $main->update(['is_relevant_for_billing' => false]);

        $this->assertTrue($unrelated->fresh()->is_relevant_for_billing);
    }

    public function test_main_category_resolves_the_root_row_for_a_nested_subcategory(): void
    {
        $main = CostCategory::create(['name' => 'Hauswart', 'is_relevant_for_billing' => true]);
        $subSub = CostCategory::create(['name' => 'Hauswart::Winterdienst::2025', 'is_relevant_for_billing' => true]);

        $this->assertSame($main->id, $subSub->mainCategory()->id);
    }

    public function test_main_category_returns_itself_for_a_hauptkategorie(): void
    {
        $main = CostCategory::create(['name' => 'Hauswart', 'is_relevant_for_billing' => true]);

        $this->assertSame($main->id, $main->mainCategory()->id);
    }
}
