<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cost;
use App\Models\CostCategory;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\ManagementUnit;
use App\Models\RentalUnit;
use App\Rules\CostCategoryAllowsLeaseCostable;
use App\Rules\CostSumWithinInvoiceAmount;
use App\Services\InvoiceSeries\ForecastRefresher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * REST-API-CRUD für Kostenzeilen (Konzept.md "## API"), gespiegelt von
 * CostController. "split()" (Rechnung splitten) ist bewusst nicht Teil des
 * API-Minimums und daher hier nicht mit abgedeckt.
 */
class CostController extends Controller
{
    /**
     * Mit "refresh_forecast: false" ergänzt store()/update() bei wiederkehrenden
     * Rechnungen keine Prognose-Kostenzeilen – für Importe, die alle Zeilen selbst
     * anlegen.
     */
    public const REFRESH_FORECAST = 'refresh_forecast';

    public function store(Request $request, ForecastRefresher $refresher): JsonResponse
    {
        $invoice = Invoice::findOrFail($request->input('invoice_id'));
        $this->authorize('update', $invoice);

        $periodDate = $request->filled('period_date')
            ? $request->input('period_date')
            : ($invoice->booking_date ?? $invoice->invoice_date);

        $existingSum = (float) $invoice->costs()->whereDate('period_date', $periodDate)->sum('amount');

        $costableIsLease = $request->input('costable_type') === 'lease';
        $managementGroup = $this->managementGroupForCostable(
            $request->input('costable_type'), $request->input('costable_id')
        );
        $kontosystemEnabled = ! $costableIsLease && ($managementGroup?->kontosystemEnabled() ?? false);

        $validated = $request->validate([
            'costable_type' => ['required', 'in:management_group,management_unit,rental_unit,lease'],
            'costable_id' => ['required', 'integer'],
            'billing_interval_id' => ['required', 'exists:billing_intervals,id'],
            'cost_category_id' => [
                'required', 'exists:cost_categories,id',
                new CostCategoryAllowsLeaseCostable($costableIsLease, $managementGroup),
            ],
            'amount' => [
                'required',
                'numeric',
                ...($invoice->disable_amount_cap ? [] : [new CostSumWithinInvoiceAmount($existingSum, $invoice->effectiveAmount())]),
            ],
            'period_date' => ['nullable', 'date'],
            'booking_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string'],
            'account_id' => [
                'nullable', 'exists:accounts,id',
                Rule::prohibitedIf($costableIsLease),
                Rule::requiredIf($kontosystemEnabled && $request->filled('booking_date')),
            ],
        ], [
            'account_id.required' => 'Bei aktivem Kontensystem muss ein Konto ausgewählt werden, um eine Kostenzeile zu buchen.',
            'account_id.prohibited' => 'Einem Mietvertrag zugewiesene Kosten gehen nicht in die Kontensystem-Bilanzierung ein und können keinem Konto zugeordnet werden.',
        ]);

        // Nur auf eine Zuordnung, die der Benutzer sehen darf – sonst ließen sich
        // Kosten in die Verwaltungsgruppe einer fremden Gruppe buchen.
        $this->authorize('view', $this->costable($validated['costable_type'], $validated['costable_id']));

        $cost = $invoice->costs()->create([
            ...collect($validated)->except('account_id')->all(),
            'period_date' => $periodDate,
        ]);

        if (! empty($validated['account_id'])) {
            $cost->accounts()->attach($validated['account_id'], ['amount' => $cost->amount]);
        }

        if ($request->boolean(self::REFRESH_FORECAST, true)) {
            $this->refreshVariableForecast($refresher, $invoice, $cost->costCategory, $cost->id);
        }

        return response()->json($cost, 201);
    }

    public function update(Request $request, Cost $cost, ForecastRefresher $refresher): JsonResponse
    {
        $this->authorize('update', $cost->invoice);

        $periodDate = $request->filled('period_date') ? $request->input('period_date') : $cost->period_date;

        $existingSum = (float) $cost->invoice->costs()
            ->whereKeyNot($cost->id)
            ->whereDate('period_date', $periodDate)
            ->sum('amount');

        $costableIsLease = $cost->costable instanceof Lease;
        $kontosystemEnabled = ! $costableIsLease && ($cost->managementGroup()?->kontosystemEnabled() ?? false);

        $validated = $request->validate([
            'billing_interval_id' => ['required', 'exists:billing_intervals,id'],
            'cost_category_id' => [
                'required', 'exists:cost_categories,id',
                new CostCategoryAllowsLeaseCostable($costableIsLease, $cost->managementGroup()),
            ],
            'amount' => [
                'required',
                'numeric',
                ...($cost->invoice->disable_amount_cap ? [] : [new CostSumWithinInvoiceAmount($existingSum, $cost->invoice->effectiveAmount())]),
            ],
            'period_date' => ['nullable', 'date'],
            'booking_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string'],
            'account_id' => [
                'nullable', 'exists:accounts,id',
                Rule::prohibitedIf($costableIsLease),
                Rule::requiredIf($kontosystemEnabled && $request->filled('confirm_booking')),
            ],
        ], [
            'account_id.required' => 'Bei aktivem Kontensystem muss ein Konto ausgewählt werden, um eine Kostenzeile zu buchen.',
            'account_id.prohibited' => 'Einem Mietvertrag zugewiesene Kosten gehen nicht in die Kontensystem-Bilanzierung ein und können keinem Konto zugeordnet werden.',
        ]);

        $invoice = $cost->invoice;

        $cost->update(collect($validated)->except('account_id')->all());

        if (! empty($validated['account_id'])) {
            $cost->accounts()->sync([$validated['account_id'] => ['amount' => $cost->amount]]);
        } else {
            $cost->accounts()->sync([]);
        }

        if ($request->boolean(self::REFRESH_FORECAST, true)) {
            $this->refreshVariableForecast($refresher, $invoice, $cost->costCategory, $cost->id);
        }

        return response()->json($cost);
    }

    public function destroy(Cost $cost, ForecastRefresher $refresher): JsonResponse
    {
        $invoice = $cost->invoice;
        $this->authorize('update', $invoice);
        $costCategory = $cost->costCategory;

        $cost->delete();

        $this->refreshVariableForecast($refresher, $invoice, $costCategory);

        return response()->json(status: 204);
    }

    private function refreshVariableForecast(ForecastRefresher $refresher, Invoice $invoice, CostCategory $costCategory, ?int $excludeCostId = null): void
    {
        $refresher->refresh($invoice, $costCategory, $excludeCostId);
    }

    private function costable(string $type, int|string $id): Model
    {
        $class = Relation::getMorphedModel($type);

        abort_if($class === null, 404);

        return $class::findOrFail($id);
    }

    private function managementGroupForCostable(?string $type, mixed $id): ?ManagementGroup
    {
        if ($type === null || $id === null) {
            return null;
        }

        $class = Relation::getMorphedModel($type);
        $costable = $class !== null ? $class::find($id) : null;

        return match (true) {
            $costable instanceof ManagementGroup => $costable,
            $costable instanceof ManagementUnit => $costable->managementGroup,
            $costable instanceof RentalUnit => $costable->managementUnit->managementGroup,
            $costable instanceof Lease => $costable->rentalUnit->managementUnit->managementGroup,
            default => null,
        };
    }
}
