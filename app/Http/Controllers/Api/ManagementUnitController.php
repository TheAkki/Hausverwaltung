<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ManagementGroup;
use App\Models\ManagementUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REST-API-CRUD für Verwaltungseinheiten (Konzept.md "## API"), gespiegelt von
 * ManagementUnitController. Die dort im Web-"show()" zusätzlich berechnete
 * Finanzübersicht liefert stattdessen der Berichte-Endpunkt (siehe ReportController).
 */
class ManagementUnitController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(ManagementUnit::class, 'managementUnit');
    }

    public function index(Request $request): JsonResponse
    {
        $managementUnits = ManagementUnit::whereHas(
            'managementGroup',
            fn ($q) => $q->visibleTo($request->user())
        )->with('managementGroup')->get();

        return response()->json($managementUnits);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'management_group_id' => ['required', 'exists:management_groups,id'],
            'street' => ['required', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:20'],
            'city' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $managementGroup = ManagementGroup::visibleTo($request->user())->findOrFail($validated['management_group_id']);

        $managementUnit = $managementGroup->managementUnits()->create($validated);

        return response()->json($managementUnit, 201);
    }

    public function show(ManagementUnit $managementUnit): JsonResponse
    {
        $managementUnit->load('rentalUnits.leases.tenants');

        return response()->json($managementUnit);
    }

    public function update(Request $request, ManagementUnit $managementUnit): JsonResponse
    {
        $validated = $request->validate([
            'street' => ['required', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:20'],
            'city' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $managementUnit->update($validated);

        return response()->json($managementUnit);
    }

    public function destroy(ManagementUnit $managementUnit): JsonResponse
    {
        $managementUnit->delete();

        return response()->json(status: 204);
    }
}
