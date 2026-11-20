<?php

namespace App\Models\Pivots;

use App\Models\CostCategory;
use App\Models\ManagementGroup;
use App\Services\GroupOwnership\GroupOwnership;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Abrechnungsschlüssel einer Kategorie in einer Verwaltungsgruppe. Eine eigene
 * Kategorie gehört zur Benutzergruppe der Verwaltungsgruppe (siehe GroupOwnership).
 */
class ManagementGroupCostCategory extends Pivot
{
    protected $table = 'management_group_cost_category';

    public $incrementing = true;

    protected static function booted(): void
    {
        static::saving(function (self $pivot): void {
            if (GroupOwnership::isSuspended() || ! $pivot->isDirty(['management_group_id', 'cost_category_id'])) {
                return;
            }

            $category = CostCategory::find($pivot->cost_category_id);
            $managementGroup = ManagementGroup::find($pivot->management_group_id);

            if ($category !== null && $managementGroup !== null) {
                GroupOwnership::assertCategoryFits($category, $managementGroup);
            }
        });
    }
}
