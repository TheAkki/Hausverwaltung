<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CostCategory;
use App\Models\ManagementGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * REST-API für Kostenkategorien, gespiegelt von CostCategoryController und
 * ManagementGroupCostCategoryController (Abrechnungsschlüssel je
 * Verwaltungsgruppe).
 *
 * Abweichend vom Web prüft store() die Eindeutigkeit des Namens nur unter den für
 * den Benutzer sichtbaren Kategorien (systemweit + eigene Gruppen) – wie der
 * Seeder und der Backup-Import, die gleichnamige Kategorien verschiedener Accounts
 * zulassen.
 */
class CostCategoryController extends Controller
{
    public const ALLOCATION_KEYS = ['person_count', 'area', 'individual', 'rental_unit'];

    public function __construct()
    {
        $this->authorizeResource(CostCategory::class, 'costCategory', ['except' => ['allocationKey']]);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(CostCategory::visibleTo($request->user())->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_group_id' => ['required', 'integer', Rule::in($request->user()->groups->pluck('id'))],
            'parent_id' => ['nullable', 'integer', 'exists:cost_categories,id'],
            'name' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                if (str_contains($value, CostCategory::SEPARATOR)) {
                    $fail(__('Der Name darf ":separator" nicht enthalten – das ist das Trennzeichen für Unterkategorien.', ['separator' => CostCategory::SEPARATOR]));
                }
            }],
            'is_relevant_for_billing' => ['boolean'],
            'is_tax_relevant' => ['boolean'],
        ]);

        $fullName = $this->fullName($validated);

        if (CostCategory::visibleTo($request->user())->where('name', $fullName)->exists()) {
            throw ValidationException::withMessages([
                'name' => __('Eine Kostenkategorie mit diesem Namen existiert bereits.'),
            ]);
        }

        $category = CostCategory::create([
            'user_group_id' => $validated['user_group_id'],
            'name' => $fullName,
            'is_relevant_for_billing' => $request->boolean('is_relevant_for_billing'),
            'is_tax_relevant' => $request->boolean('is_tax_relevant'),
        ]);

        return response()->json($category, 201);
    }

    public function show(CostCategory $costCategory): JsonResponse
    {
        return response()->json($costCategory);
    }

    public function update(Request $request, CostCategory $costCategory): JsonResponse
    {
        $request->validate([
            'is_relevant_for_billing' => ['boolean'],
            'is_tax_relevant' => ['boolean'],
        ]);

        $costCategory->update([
            'is_relevant_for_billing' => $request->boolean('is_relevant_for_billing'),
            'is_tax_relevant' => $request->boolean('is_tax_relevant'),
        ]);

        return response()->json($costCategory);
    }

    /**
     * Abrechnungsschlüssel einer Kategorie innerhalb einer Verwaltungsgruppe setzen.
     */
    public function allocationKey(Request $request, ManagementGroup $managementGroup, CostCategory $costCategory): JsonResponse
    {
        $this->authorize('update', $managementGroup);
        $this->authorize('view', $costCategory);

        $validated = $request->validate([
            'allocation_key' => ['required', Rule::in(self::ALLOCATION_KEYS)],
        ]);

        $managementGroup->costCategories()->syncWithoutDetaching([
            $costCategory->id => ['allocation_key' => $validated['allocation_key']],
        ]);

        return response()->json($managementGroup->costCategories()->get());
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function fullName(array $validated): string
    {
        if (empty($validated['parent_id'])) {
            return $validated['name'];
        }

        $parent = CostCategory::findOrFail($validated['parent_id']);
        $this->authorize('view', $parent);

        return $parent->name.CostCategory::SEPARATOR.$validated['name'];
    }
}
