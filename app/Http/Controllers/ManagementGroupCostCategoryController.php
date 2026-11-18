<?php

namespace App\Http\Controllers;

use App\Models\CostCategory;
use App\Models\ManagementGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Manages the allocation key configured for a cost category within a management group.
 */
class ManagementGroupCostCategoryController extends Controller
{
    /**
     * Set the allocation key for a cost category within a management group.
     */
    public function update(Request $request, ManagementGroup $managementGroup, CostCategory $costCategory): RedirectResponse
    {
        $this->authorize('update', $managementGroup);
        // A CostCategory belonging to another Account's Benutzergruppe (route-model-bound,
        // so guessable by id) must not be assignable here – only systemweite Kategorien or
        // ones owned by one of the current user's own Gruppen may be.
        $this->authorize('view', $costCategory);

        $validated = $request->validate([
            'allocation_key' => ['required', 'in:person_count,area,individual,rental_unit'],
        ]);

        $managementGroup->costCategories()->syncWithoutDetaching([
            $costCategory->id => ['allocation_key' => $validated['allocation_key']],
        ]);

        return redirect()->route('management-groups.show', $managementGroup);
    }
}
