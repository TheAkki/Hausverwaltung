<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ManagementGroup;
use App\Services\GroupOwnership\ManagementGroupTransfer;
use App\Services\InvoiceSeries\ForecastRefresher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * REST-API-CRUD für Verwaltungsgruppen (Konzept.md "## API"), gespiegelt von
 * ManagementGroupController. Die dort im Web-"show()" zusätzlich berechnete
 * Finanzübersicht liefert stattdessen der Berichte-Endpunkt (siehe ReportController).
 */
class ManagementGroupController extends Controller
{
    /**
     * Feld der Antwort von update(): welche Stammdaten beim Wechsel der Benutzergruppe
     * mitverschoben, kopiert oder in der neuen Gruppe wiederverwendet wurden.
     */
    public const TRANSFER_FIELD = 'transfer';

    public function __construct()
    {
        $this->authorizeResource(ManagementGroup::class, 'managementGroup');
    }

    public function index(Request $request): JsonResponse
    {
        $managementGroups = ManagementGroup::visibleTo($request->user())
            ->withCount('managementUnits')
            ->get();

        return response()->json($managementGroups);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);

        abort_unless($request->user()->groups->contains($validated['user_group_id']), 403);

        $validated['kontosystem_enabled'] = $request->boolean('kontosystem_enabled');
        $validated['variable_cost_forecast_mode'] ??= ManagementGroup::VARIABLE_FORECAST_LAST_BOOKING;

        $managementGroup = ManagementGroup::create($validated);

        return response()->json($managementGroup, 201);
    }

    public function show(ManagementGroup $managementGroup): JsonResponse
    {
        $managementGroup->load('managementUnits.rentalUnits', 'costCategories', 'maintenanceContracts', 'accounts.transfersOut', 'billingIntervals', 'landlord');

        return response()->json($managementGroup);
    }

    public function update(Request $request, ManagementGroup $managementGroup, ForecastRefresher $forecastRefresher, ManagementGroupTransfer $transfer): JsonResponse
    {
        $validated = $this->validated($request, withBillingTemplate: true);

        abort_unless($request->user()->groups->contains($validated['user_group_id']), 403);

        $validated['kontosystem_enabled'] = $request->boolean('kontosystem_enabled');
        $validated['variable_cost_forecast_mode'] ??= $managementGroup->variable_cost_forecast_mode;

        $forecastModeChanged = $validated['variable_cost_forecast_mode'] !== $managementGroup->variable_cost_forecast_mode;

        // Ein Wechsel der Benutzergruppe nimmt die Stammdaten mit (ManagementGroupTransfer).
        $templateBefore = $managementGroup->default_billing_template_id;
        $report = $transfer->move($managementGroup, (int) $validated['user_group_id']);
        $validated = $transfer->keepMovedTemplate($validated, $templateBefore, $managementGroup);
        $managementGroup->update(collect($validated)->except('user_group_id')->all());

        if ($forecastModeChanged) {
            $forecastRefresher->refreshManagementGroup($managementGroup);
        }

        return response()->json([...$managementGroup->toArray(), self::TRANSFER_FIELD => $report->toArray()]);
    }

    public function destroy(ManagementGroup $managementGroup): JsonResponse
    {
        $managementGroup->delete();

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $withBillingTemplate = false): array
    {
        return $request->validate([
            'description' => ['nullable', 'string'],
            'billing_start' => ['nullable', 'date'],
            'user_group_id' => ['required', 'exists:groups,id'],
            'variable_cost_forecast_mode' => ['nullable', Rule::in(ManagementGroup::VARIABLE_FORECAST_MODES)],
            ...($withBillingTemplate ? [
                'default_billing_template_id' => ['nullable', 'exists:billing_templates,id'],
            ] : []),
        ]);
    }
}
