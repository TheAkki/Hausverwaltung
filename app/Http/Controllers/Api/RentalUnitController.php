<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ManagementUnit;
use App\Models\RentalUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REST-API-CRUD für Mieteinheiten (Konzept.md "## API"), gespiegelt von
 * RentalUnitController.
 */
class RentalUnitController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(RentalUnit::class, 'rentalUnit');
    }

    public function index(Request $request): JsonResponse
    {
        $rentalUnits = RentalUnit::whereHas(
            'managementUnit.managementGroup',
            fn ($q) => $q->visibleTo($request->user())
        )->with('managementUnit')->get();

        return response()->json($rentalUnits);
    }

    public function store(Request $request): JsonResponse
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

        return response()->json($rentalUnit, 201);
    }

    public function show(RentalUnit $rentalUnit): JsonResponse
    {
        $rentalUnit->load('leases.tenants');

        return response()->json($rentalUnit);
    }

    public function update(Request $request, RentalUnit $rentalUnit): JsonResponse
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

        return response()->json($rentalUnit);
    }

    public function destroy(RentalUnit $rentalUnit): JsonResponse
    {
        $rentalUnit->delete();

        return response()->json(status: 204);
    }
}
