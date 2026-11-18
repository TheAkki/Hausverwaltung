<?php

namespace App\Http\Controllers;

use App\Models\ManagementUnit;
use App\Models\RentalUnit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CRUD for Mieteinheiten (rental units) within a ManagementUnit, with resource
 * authorization via authorizeResource.
 */
class RentalUnitController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(RentalUnit::class, 'rentalUnit');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): View
    {
        $rentalUnits = RentalUnit::whereHas(
            'managementUnit.managementGroup',
            fn ($q) => $q->visibleTo($request->user())
        )->with('managementUnit')->get();

        return view('rental-units.index', compact('rentalUnits'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request): View
    {
        $managementUnit = ManagementUnit::findOrFail($request->query('management_unit_id'));
        $this->authorize('view', $managementUnit);

        return view('rental-units.create', compact('managementUnit'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'management_unit_id' => ['required', 'exists:management_units,id'],
            'size' => ['nullable', 'numeric', 'min:0'],
            'type' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'floor' => ['nullable', 'integer'],
            'is_relevant_for_billing' => ['boolean'],
        ]);

        $managementUnit = ManagementUnit::findOrFail($validated['management_unit_id']);
        $this->authorize('view', $managementUnit);

        $validated['is_relevant_for_billing'] = $request->boolean('is_relevant_for_billing');

        $rentalUnit = $managementUnit->rentalUnits()->create($validated);

        return redirect()->route('rental-units.show', $rentalUnit);
    }

    /**
     * Display the specified resource.
     */
    public function show(RentalUnit $rentalUnit): View
    {
        $rentalUnit->load('leases.tenants');

        return view('rental-units.show', compact('rentalUnit'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(RentalUnit $rentalUnit): View
    {
        return view('rental-units.edit', compact('rentalUnit'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, RentalUnit $rentalUnit): RedirectResponse
    {
        $validated = $request->validate([
            'size' => ['nullable', 'numeric', 'min:0'],
            'type' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'floor' => ['nullable', 'integer'],
            'is_relevant_for_billing' => ['boolean'],
        ]);

        $validated['is_relevant_for_billing'] = $request->boolean('is_relevant_for_billing');

        $rentalUnit->update($validated);

        return redirect()->route('rental-units.show', $rentalUnit);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(RentalUnit $rentalUnit): RedirectResponse
    {
        $managementUnit = $rentalUnit->managementUnit;

        $rentalUnit->delete();

        return redirect()->route('management-units.show', $managementUnit);
    }
}
