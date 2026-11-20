<?php

namespace App\Http\Controllers;

use App\Models\CostCategory;
use App\Services\CostCategoryCreator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Manages Kostenkategorien (cost categories): lists them and toggles their billing
 * relevance. The systemweiten Kategorien (CostCategorySeeder, user_group_id null) sind
 * für jeden sichtbar, aber nur eigene (dem Account des Nutzers zugeordnete) Kategorien
 * lassen sich bearbeiten – siehe CostCategoryPolicy.
 */
class CostCategoryController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(CostCategory::class, 'costCategory');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): View
    {
        $costCategories = CostCategory::visibleTo($request->user())->orderBy('name')->get();

        return view('cost-categories.index', compact('costCategories'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request): View
    {
        $userGroups = $request->user()->groups;
        // Every category the user can see is offered as a possible Überkategorie – not
        // just Hauptkategorien – since the Schachteltiefe is unbounded (Konzept.md
        // "### Kosten"): a subcategory may itself be nested under further.
        $parentOptions = CostCategory::visibleTo($request->user())->orderBy('name')->get();

        return view('cost-categories.create', compact('userGroups', 'parentOptions'));
    }

    /**
     * Store a newly created resource in storage. An optional "parent_id" turns this
     * into a subcategory: its full name becomes "<Überkategorie>::<name>" (unbounded
     * nesting), with is_relevant_for_billing/is_tax_relevant then derived from its
     * Hauptkategorie by CostCategoryObserver – whatever is submitted for those two
     * fields is ignored in that case.
     */
    public function store(Request $request, CostCategoryCreator $creator): RedirectResponse
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

        $parent = null;
        if ($validated['parent_id'] ?? null) {
            $parent = CostCategory::findOrFail($validated['parent_id']);
            $this->authorize('view', $parent);
        }

        $creator->create($validated, $request->boolean('is_relevant_for_billing'), $request->boolean('is_tax_relevant'), $parent);

        return redirect()->route('cost-categories.index');
    }

    /**
     * Update the specified resource in storage. For a subcategory, the submitted
     * is_relevant_for_billing/is_tax_relevant are silently overridden by
     * CostCategoryObserver (they always mirror its Hauptkategorie) – the index view
     * disables those toggles for subcategory rows so this never surprises a user.
     */
    public function update(Request $request, CostCategory $costCategory): RedirectResponse
    {
        $request->validate([
            'is_relevant_for_billing' => ['boolean'],
            'is_tax_relevant' => ['boolean'],
        ]);

        $costCategory->update([
            'is_relevant_for_billing' => $request->boolean('is_relevant_for_billing'),
            'is_tax_relevant' => $request->boolean('is_tax_relevant'),
        ]);

        return redirect()->route('cost-categories.index');
    }
}
