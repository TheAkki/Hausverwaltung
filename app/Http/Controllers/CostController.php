<?php

namespace App\Http\Controllers;

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
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRUD for Costs allocated against an Invoice, validating that the sum of a costable's
 * costs for a given occurrence (period_date) never exceeds the invoice's effective
 * amount. A recurring invoice legitimately has this same amount booked once per
 * period, so the cap intentionally does not sum across different periods.
 */
class CostController extends Controller
{
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, ForecastRefresher $refresher): RedirectResponse
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
            'account_id' => [
                'nullable', 'exists:accounts,id',
                Rule::prohibitedIf($costableIsLease),
                Rule::requiredIf($kontosystemEnabled && $request->filled('booking_date')),
            ],
        ], [
            'account_id.required' => 'Bei aktivem Kontensystem muss ein Konto ausgewählt werden, um eine Kostenzeile zu buchen.',
            'account_id.prohibited' => 'Einem Mietvertrag zugewiesene Kosten gehen nicht in die Kontensystem-Bilanzierung ein und können keinem Konto zugeordnet werden.',
        ]);

        // Booked on the Buchungsdatum (booking date) when set, since the Ausgabenübersicht
        // groups costs by that year and an invoice can be dated in one year but booked/
        // recognized in the next.
        $cost = $invoice->costs()->create([
            ...collect($validated)->except('account_id')->all(),
            'period_date' => $periodDate,
        ]);

        if (! empty($validated['account_id'])) {
            $cost->accounts()->attach($validated['account_id'], ['amount' => $cost->amount]);
        }

        $this->refreshVariableForecast($refresher, $invoice, $cost->costCategory, $cost->id);

        return redirect()->route('invoices.show', $invoice);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Cost $cost, ForecastRefresher $refresher): RedirectResponse
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
            // The inline table's Buchungsdatum field always carries a value (the real
            // booking_date, or a placeholder so the calendar opens to the right month) even
            // when the user is just editing an unrelated field (Kategorie/Betrag) on a still
            // open row — so "filled('booking_date')" can't signal booking intent here. The
            // "Buchen" button instead marks intent explicitly via this field, which is only
            // present in the submitted data when that specific button triggered the submit.
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

        $this->refreshVariableForecast($refresher, $invoice, $cost->costCategory, $cost->id);

        return redirect()->route('invoices.show', $invoice);
    }

    /**
     * "Rechnung splitten" (Konzept.md "### Rechnung": "Es muss möglich sein eine
     * Rechnung zu splitten. Das Splitten muss auch auf unterschiedliche
     * Abrechnungsintervalle sein."): spaltet einen Teilbetrag der gegebenen Kostenzeile
     * in eine neue, ansonsten identische Kostenzeile ab (gleiche Zuordnung, Kategorie,
     * Periode, Buchungsdatum), die einem ANDEREN Abrechnungsintervall zugewiesen wird.
     * Bewusst additiv statt über CostSumWithinInvoiceAmount validiert: die ursprüngliche
     * Kostenzeile wird um exakt denselben Betrag reduziert, um den die neue Zeile
     * anwächst – die Summe (und damit der Rechnungsbetrag-Deckel) bleibt dadurch
     * unverändert, ganz ohne dass beide Zeilen ihrerseits einzeln gegen den Deckel
     * geprüft werden müssten (was bei unterschiedlichen Abrechnungsintervallen bisher
     * nicht funktionierte, siehe Nutzerfeedback: "dann funktioniert nicht die Summe der
     * Rechnung auf den Gesamtbetrag zu beschränken").
     */
    public function split(Request $request, Cost $cost): RedirectResponse
    {
        $this->authorize('update', $cost->invoice);

        // Strictly between 0 and the row's amount – mirrored for a negative row
        // (Einnahme/Rückzahlung), whose split part must be negative as well.
        $bounds = $cost->amount < 0
            ? ['gt:'.$cost->amount, 'lt:0']
            : ['gt:0', 'lt:'.$cost->amount];
        $boundsMessage = 'Der abgespaltene Betrag muss zwischen 0 und dem bisherigen Betrag der Kostenzeile liegen (sonst bliebe für die ursprüngliche Zeile nichts übrig).';

        $validated = $request->validate([
            'billing_interval_id' => ['required', 'exists:billing_intervals,id', Rule::notIn([$cost->billing_interval_id])],
            'amount' => ['required', 'numeric', ...$bounds],
        ], [
            'billing_interval_id.not_in' => 'Das Ziel-Abrechnungsintervall muss sich vom bisherigen unterscheiden – sonst wäre es keine Aufteilung.',
            'amount.gt' => $boundsMessage,
            'amount.lt' => $boundsMessage,
        ]);

        // Ein bereits verknüpftes Konto (Kontensystem) betrifft immer noch dasselbe reale
        // Bankereignis, nur eben auf zwei Kostenzeilen aufgeteilt – geht daher mit auf die
        // neue Zeile über, mit dem jeweils eigenen Teilbetrag als Pivot-"amount" (sonst
        // spiegelt sich die Aufteilung nicht in der Kontensystem-Bilanzierung wider und die
        // alte Zeile bliebe dort weiter mit ihrem vollen Ursprungsbetrag verbucht).
        $accountId = $cost->accounts()->value('accounts.id');

        $cost->update(['amount' => round($cost->amount - $validated['amount'], 2)]);

        if ($accountId !== null) {
            $cost->accounts()->sync([$accountId => ['amount' => $cost->amount]]);
        }

        $split = Cost::create([
            'invoice_id' => $cost->invoice_id,
            'costable_type' => $cost->costable_type,
            'costable_id' => $cost->costable_id,
            'billing_interval_id' => $validated['billing_interval_id'],
            'cost_category_id' => $cost->cost_category_id,
            'amount' => $validated['amount'],
            'period_date' => $cost->period_date,
            'booking_date' => $cost->booking_date,
            'note' => $cost->note,
        ]);

        if ($accountId !== null) {
            $split->accounts()->attach($accountId, ['amount' => $split->amount]);
        }

        return redirect()->route('invoices.show', $cost->invoice);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Cost $cost, ForecastRefresher $refresher): RedirectResponse
    {
        $invoice = $cost->invoice;
        $this->authorize('update', $invoice);
        $costCategory = $cost->costCategory;

        $cost->delete();

        // A deleted booking is also "new evidence" for the forecast (its history just
        // shrank), not only a newly added/edited one.
        $this->refreshVariableForecast($refresher, $invoice, $costCategory);

        return redirect()->route('invoices.show', $invoice);
    }

    /**
     * Refreshes a variable Rechnung's other still-open forecast rows for this
     * Kostenkategorie right when a real booking for it is saved, changed, or removed,
     * instead of requiring the Nutzer to notice and manually re-trigger "Serie
     * vervollständigen" (Nutzerfeedback: "Die händische Aktualisierung ist
     * fehleranfällig.") – exactly what completeSeries() already computes, just
     * triggered automatically the moment new evidence (this Kostenzeile) actually
     * changes that computation. A no-op for a non-recurring ("once"/"manual") invoice,
     * which has no forecast series at all.
     *
     * $excludeCostId (the row just saved by store()/update(), null for destroy() since
     * it no longer exists) is passed straight through to completeSeries() so this
     * refresh never immediately reprocesses and overwrites the value the Nutzer just
     * entered on that exact row.
     */
    private function refreshVariableForecast(ForecastRefresher $refresher, Invoice $invoice, CostCategory $costCategory, ?int $excludeCostId = null): void
    {
        $refresher->refresh($invoice, $costCategory, $excludeCostId);
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
