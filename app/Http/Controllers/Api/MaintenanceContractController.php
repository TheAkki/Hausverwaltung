<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MaintenanceContract;
use App\Models\ManagementGroup;
use App\Rules\DateAfterField;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REST-API für Wartungsverträge einer Verwaltungsgruppe, gespiegelt von
 * MaintenanceContractController.
 */
class MaintenanceContractController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'management_group_id' => ['required', 'exists:management_groups,id'],
            ...$this->rules($request),
        ]);

        $managementGroup = ManagementGroup::visibleTo($request->user())->findOrFail($validated['management_group_id']);
        $this->authorize('update', $managementGroup);

        $contract = $managementGroup->maintenanceContracts()->create(collect($validated)->except('management_group_id')->all());

        return response()->json($contract, 201);
    }

    public function update(Request $request, MaintenanceContract $maintenanceContract): JsonResponse
    {
        $this->authorize('update', $maintenanceContract->managementGroup);

        $maintenanceContract->update($request->validate($this->rules($request)));

        return response()->json($maintenanceContract);
    }

    public function destroy(MaintenanceContract $maintenanceContract): JsonResponse
    {
        $this->authorize('update', $maintenanceContract->managementGroup);

        $maintenanceContract->delete();

        return response()->json(status: 204);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(Request $request): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:255'],
            'provider' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', new DateAfterField($request->input('start_date'), 'Das Enddatum muss nach dem Startdatum liegen.')],
        ];
    }
}
