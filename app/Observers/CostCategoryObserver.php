<?php

namespace App\Observers;

use App\Models\CostCategory;

/**
 * Enforces Konzept.md "### Kosten": "Ob eine Unterkategorie abrechnungsrelevant bzw.
 * steuerrelevant ist bestimmt die Hauptkategorie." A subcategory (name containing
 * CostCategory::SEPARATOR) never stores an independent is_relevant_for_billing/
 * is_tax_relevant – it always mirrors its Hauptkategorie's (the outermost/root
 * segment's), regardless of what a caller tries to set on the subcategory itself. When
 * the Hauptkategorie's own flags change, every subcategory nested under it (at any
 * depth) is updated to match.
 */
class CostCategoryObserver
{
    /**
     * Before a subcategory is created/updated, overwrite its own flags with its
     * Hauptkategorie's. If the Hauptkategorie doesn't exist (yet) – shouldn't happen via
     * the UI, which only offers existing categories as Überkategorie – the submitted
     * values are left as-is rather than silently discarded.
     */
    public function saving(CostCategory $costCategory): void
    {
        if (! $costCategory->isSubcategory()) {
            return;
        }

        $main = $costCategory->mainCategory();

        if ($main === null) {
            return;
        }

        $costCategory->is_relevant_for_billing = $main->is_relevant_for_billing;
        $costCategory->is_tax_relevant = $main->is_tax_relevant;
    }

    /**
     * After a Hauptkategorie's own flags change, cascade the new values to every
     * subcategory nested under it (any depth) in one query – a plain query builder
     * update(), so it deliberately bypasses saving() above (nothing left to derive,
     * the values already come straight from the Hauptkategorie that defines them).
     */
    public function updated(CostCategory $costCategory): void
    {
        if ($costCategory->isSubcategory()) {
            return;
        }

        if (! $costCategory->wasChanged(['is_relevant_for_billing', 'is_tax_relevant'])) {
            return;
        }

        CostCategory::where('name', 'like', $costCategory->name.CostCategory::SEPARATOR.'%')
            ->update([
                'is_relevant_for_billing' => $costCategory->is_relevant_for_billing,
                'is_tax_relevant' => $costCategory->is_tax_relevant,
            ]);
    }
}
