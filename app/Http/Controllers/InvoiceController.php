<?php

namespace App\Http\Controllers;

use App\Models\CostCategory;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\InvoiceIssuerAccount;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\ManagementUnit;
use App\Models\RentalUnit;
use App\Rules\DateAfterField;
use App\Rules\SameSignAs;
use App\Services\InvoiceReceipt;
use App\Services\InvoiceSeries\InvalidSeriesException;
use App\Services\InvoiceSeries\InvoiceSeriesService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * CRUD for Invoices, including validation of recurring/manual periodicity and
 * generation of allocated Costs against the chosen costable entity.
 */
class InvoiceController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Invoice::class, 'invoice');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): View
    {
        $showCompleted = $request->boolean('show_completed');

        $query = Invoice::visibleTo($request->user())
            ->with('invoiceIssuer', 'costable', 'costs')
            ->latest('invoice_date');

        // "Erledigt" (Invoice::isFullyBooked()) ausgeblendet, sofern nicht "show_completed"
        // gesetzt ist (analog zu LeaseController::index()s "show_expired") – in SQL statt in
        // PHP gefiltert, damit paginate() weiterhin auf der echten Gesamtzahl arbeitet: eine
        // Rechnung zählt hier als nicht-erledigt, wenn sie entweder gar keine Kosten hat oder
        // mindestens eine ihrer Kosten noch kein booking_date hat.
        if (! $showCompleted) {
            $query->where(fn ($q) => $q->whereDoesntHave('costs')->orWhereHas('costs', fn ($q2) => $q2->whereNull('booking_date')));
        }

        $this->applySearch($query, $request);

        $invoices = $query->paginate(20)->withQueryString();

        $user = $request->user();

        return view('invoices.index', [
            'invoices' => $invoices,
            'showCompleted' => $showCompleted,
            'invoiceIssuers' => InvoiceIssuer::visibleTo($user)->orderBy('name')->get(),
            'costCategories' => CostCategory::visibleTo($user)->orderBy('name')->get(),
            'accountsByIssuer' => InvoiceIssuerAccount::whereIn('invoice_issuer_id', InvoiceIssuer::visibleTo($user)->pluck('id'))
                ->orderBy('customer_number')->get()->groupBy('invoice_issuer_id'),
        ]);
    }

    /**
     * "Rechnungen finden" (Konzept.md "#### Rechnungen findem"): filtert die Übersicht
     * nach Rechnungssteller (und dessen Accounts), Rechnungsdatum-Zeitraum,
     * Betragsbereich, Kategorie, Beschreibung, Buchungsdatum-Zeitraum, ob ein Beleg
     * (Datei) vorhanden ist, sowie der Rechnungsnummer. Alle ausgefüllten Kriterien
     * werden je nach "match" (per AskUserQuestion geklärt: ein globaler Schalter statt
     * eines vollen UND/ODER-Ausdrucksbaums) entweder alle mit UND oder alle mit ODER
     * verknüpft.
     */
    private function applySearch(Builder $query, Request $request): void
    {
        $filters = [];

        if ($request->filled('issuer_account_id')) {
            $accountId = $request->integer('issuer_account_id');
            $filters[] = fn ($q) => $q->where('invoice_issuer_account_id', $accountId);
        } elseif ($request->filled('issuer_id')) {
            $issuerId = $request->integer('issuer_id');
            $filters[] = fn ($q) => $q->where('invoice_issuer_id', $issuerId);
        }

        if ($request->filled('date_from')) {
            $filters[] = fn ($q) => $q->whereDate('invoice_date', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $filters[] = fn ($q) => $q->whereDate('invoice_date', '<=', $request->date('date_to'));
        }

        if ($request->filled('amount_min')) {
            $filters[] = fn ($q) => $q->where('amount', '>=', (float) $request->input('amount_min'));
        }
        if ($request->filled('amount_max')) {
            $filters[] = fn ($q) => $q->where('amount', '<=', (float) $request->input('amount_max'));
        }

        if ($request->filled('cost_category_id')) {
            $categoryId = $request->integer('cost_category_id');
            $filters[] = fn ($q) => $q->whereHas('costs', fn ($cq) => $cq->where('cost_category_id', $categoryId));
        }

        if ($request->filled('description')) {
            $description = $request->input('description');
            $filters[] = fn ($q) => $q->where('description', 'like', '%'.$description.'%');
        }

        if ($request->filled('booking_date_from') || $request->filled('booking_date_to')) {
            $from = $request->filled('booking_date_from') ? $request->date('booking_date_from') : null;
            $to = $request->filled('booking_date_to') ? $request->date('booking_date_to') : null;
            // Sowohl das eigene booking_date der Rechnung (relevant bei einmaligen
            // Rechnungen ohne je erzeugte Kostenzeile) als auch das jeder ihrer
            // Kostenzeilen (relevant bei wiederkehrenden Rechnungen) zählt.
            $filters[] = function ($q) use ($from, $to) {
                $withRange = function ($q2) use ($from, $to) {
                    $q2->whereNotNull('booking_date');
                    if ($from) {
                        $q2->whereDate('booking_date', '>=', $from);
                    }
                    if ($to) {
                        $q2->whereDate('booking_date', '<=', $to);
                    }

                    return $q2;
                };

                $q->where($withRange)->orWhereHas('costs', $withRange);
            };
        }

        if ($request->filled('has_file')) {
            $hasFile = $request->input('has_file') === 'yes';
            $filters[] = fn ($q) => $hasFile ? $q->whereNotNull('file_path') : $q->whereNull('file_path');
        }

        if ($request->filled('invoice_number')) {
            $invoiceNumber = $request->input('invoice_number');
            $filters[] = fn ($q) => $q->where('invoice_number', 'like', '%'.$invoiceNumber.'%');
        }

        if ($filters === []) {
            return;
        }

        $matchAll = $request->input('match', 'all') !== 'any';

        $query->where(function ($q) use ($filters, $matchAll) {
            foreach ($filters as $filter) {
                $matchAll ? $q->where($filter) : $q->orWhere($filter);
            }
        });
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request): View
    {
        $selectedCostable = null;
        $backTo = route('invoices.index');

        if ($request->filled('lease_id')) {
            $lease = Lease::findOrFail($request->query('lease_id'));
            $this->authorize('view', $lease);
            $selectedCostable = "{$lease->getMorphClass()}:{$lease->id}";
            $backTo = route('leases.show', $lease).'#rechnungen';
        }

        return view('invoices.create', [
            ...$this->formData($request),
            'selectedCostable' => $selectedCostable,
            'backTo' => $backTo,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, InvoiceSeriesService $series): RedirectResponse
    {
        $validated = $this->validateInvoice($request);

        abort_unless(
            InvoiceIssuer::visibleTo($request->user())->whereKey($validated['invoice_issuer_id'])->exists(),
            403
        );

        [$costableType, $costableId] = explode(':', $validated['costable']);
        $costable = $this->resolveCostable($costableType, $costableId);
        $this->authorize('view', $costable);

        $costCategory = CostCategory::findOrFail($validated['cost_category_id']);
        $this->authorize('view', $costCategory);

        $invoice = Invoice::create([
            ...$validated,
            'costable_type' => $costable->getMorphClass(),
            'costable_id' => $costable->getKey(),
            'amount' => $validated['periodicity'] === Invoice::PERIODICITY_MANUAL
                ? collect($validated['occurrences'])->sum('amount')
                : $validated['amount'],
            'disable_amount_cap' => $request->boolean('disable_amount_cap'),
        ]);
        $this->attachUpload($request, $invoice);

        if ($validated['periodicity'] === Invoice::PERIODICITY_MANUAL) {
            foreach ($validated['occurrences'] as $occurrence) {
                $invoice->occurrences()->create($occurrence);
            }
            $invoice->load('occurrences');
        }

        $series->write($invoice, $costable, $costCategory);

        return $costable instanceof Lease
            ? redirect()->route('leases.show', $costable)->withFragment('rechnungen')
            : redirect()->route('invoices.show', $invoice);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Invoice $invoice): View
    {
        $invoice->load('invoiceIssuer', 'costable', 'occurrences', 'costs.costCategory', 'costs.costable', 'costs.billingInterval', 'costs.accounts');

        return view('invoices.show', [...$this->formData($request), 'invoice' => $invoice]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, Invoice $invoice): View
    {
        $invoice->load('occurrences', 'costable', 'costs.costCategory', 'costs.costable', 'costs.billingInterval', 'costs.accounts');

        return view('invoices.edit', [...$this->formData($request), 'invoice' => $invoice]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Invoice $invoice): RedirectResponse
    {
        $validated = $request->validate([
            'invoice_issuer_id' => ['required', 'exists:invoice_issuers,id'],
            'amount' => ['required', 'numeric'],
            'effective_amount' => ['nullable', 'numeric', new SameSignAs($request->input('amount'))],
            'invoice_number' => ['nullable', 'string', 'max:255'],
            'invoice_date' => ['required', 'date'],
            'booking_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
            'file' => ['nullable', ...InvoiceReceipt::rules()],
            'recurrence_start' => ['nullable', 'date'],
            'recurrence_end' => ['nullable', 'date', new DateAfterField($request->input('recurrence_start'), 'Das Ende der Wiederholung muss am oder nach dem Beginn liegen.', orEqual: true)],
        ]);

        abort_unless(
            InvoiceIssuer::visibleTo($request->user())->whereKey($validated['invoice_issuer_id'])->exists(),
            403
        );

        $validated['disable_amount_cap'] = $request->boolean('disable_amount_cap');

        $invoice->update(collect($validated)->except('file')->all());
        $this->attachUpload($request, $invoice);

        return $invoice->costable instanceof Lease
            ? redirect()->route('leases.show', $invoice->costable)->withFragment('rechnungen')
            : redirect()->route('invoices.show', $invoice);
    }

    /**
     * Retroactively generate the missing Cost rows for a recurring invoice that was
     * assigned its Zuordnung (costable) after creation, so it never went through the
     * automatic per-occurrence generation that happens at invoice creation time.
     */
    public function completeSeries(Request $request, Invoice $invoice, InvoiceSeriesService $series): RedirectResponse
    {
        abort_if($invoice->costable === null, 422, 'Die Rechnung hat keine Zuordnung.');
        abort_unless(in_array($invoice->periodicity, [
            Invoice::PERIODICITY_WEEKLY, Invoice::PERIODICITY_BIWEEKLY, Invoice::PERIODICITY_MONTHLY,
            Invoice::PERIODICITY_QUARTERLY, Invoice::PERIODICITY_YEARLY,
        ], true), 422, 'Serie kann nur bei wiederkehrenden Rechnungen vervollständigt werden.');

        $this->authorize('view', $invoice->costable);

        $validated = $request->validate([
            'cost_category_id' => ['required', 'exists:cost_categories,id'],
            'until' => ['nullable', 'date'],
        ]);

        $costCategory = CostCategory::findOrFail($validated['cost_category_id']);
        $this->authorize('view', $costCategory);

        try {
            $series->complete($invoice, $costCategory, isset($validated['until']) ? Carbon::parse($validated['until'])->toDateString() : null);
        } catch (InvalidSeriesException $exception) {
            throw ValidationException::withMessages(['until' => $exception->getMessage()]);
        }

        return redirect()->route('invoices.show', $invoice);
    }

    /**
     * Hochgeladenen Beleg (Feld "file") an die Rechnung hängen; ersetzt einen vorhandenen.
     */
    private function attachUpload(Request $request, Invoice $invoice): void
    {
        if ($request->hasFile('file')) {
            app(InvoiceReceipt::class)->attach($invoice, $request->file('file'), $request->file('file')->getClientOriginalName());
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Invoice $invoice): RedirectResponse
    {
        $costable = $invoice->costable;

        app(InvoiceReceipt::class)->remove($invoice);

        $invoice->delete();

        return $costable instanceof Lease
            ? redirect()->route('leases.show', $costable)->withFragment('rechnungen')
            : redirect()->route('invoices.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Request $request): array
    {
        $user = $request->user();

        $managementGroups = ManagementGroup::visibleTo($user)->with('managementUnits.rentalUnits.leases.tenants')->get();

        $allCostCategories = CostCategory::visibleTo($user)->orderBy('name')->get()->map(fn ($c) => ['id' => $c->id, 'label' => $c->name])->values();

        $costables = [];
        $billingIntervalsByCostable = [];
        $kontenByCostable = [];
        $costCategoriesByCostable = [];

        foreach ($managementGroups as $managementGroup) {
            $key = "management_group:{$managementGroup->id}";
            $costables[$key] = $managementGroup->description ?: "Verwaltungsgruppe #{$managementGroup->id}";
            $billingIntervalsByCostable[$key] = $managementGroup->billingIntervals()->orderBy('start_date')->get()
                ->map(fn ($bi) => ['id' => $bi->id, 'label' => $bi->start_date->format('d.m.Y').' – '.$bi->end_date->format('d.m.Y')]);
            $kontenByCostable[$key] = $managementGroup->kontosystemEnabled()
                ? $managementGroup->accounts()->orderBy('name')->get()->map(fn ($a) => ['id' => $a->id, 'label' => $a->name])
                : [];
            $costCategoriesByCostable[$key] = $allCostCategories;

            // Kosten, die einem Mietvertrag direkt zugewiesen werden, zählen in der
            // Betriebskostenabrechnung nur, wenn ihre Kategorie für diese Verwaltungsgruppe
            // die Abrechnungsart "Individuell" hat (siehe BillingCostAllocationService: alle
            // anderen Abrechnungsarten werden auf Mietvertragsebene ignoriert). Andere
            // Kategorien würden sonst zwar gespeichert, aber lautlos nie in die Abrechnung
            // einfließen – deshalb hier von vornherein nur "Individuell"-Kategorien anbieten
            // (siehe auch CostCategoryAllowsLeaseCostable, das dies serverseitig erzwingt).
            $individualCostCategories = $managementGroup->costCategories()
                ->wherePivot('allocation_key', 'individual')
                ->orderBy('name')
                ->get()
                ->map(fn ($c) => ['id' => $c->id, 'label' => $c->name])
                ->values();

            foreach ($managementGroup->managementUnits as $managementUnit) {
                $key = "management_unit:{$managementUnit->id}";
                $costables[$key] = '– '.$managementUnit->street.', '.$managementUnit->city;
                $billingIntervalsByCostable[$key] = $billingIntervalsByCostable["management_group:{$managementGroup->id}"];
                $kontenByCostable[$key] = $kontenByCostable["management_group:{$managementGroup->id}"];
                $costCategoriesByCostable[$key] = $allCostCategories;

                foreach ($managementUnit->rentalUnits as $rentalUnit) {
                    $key = "rental_unit:{$rentalUnit->id}";
                    $costables[$key] = '–– '.$rentalUnit->label().' ('.$managementUnit->street.')';
                    $billingIntervalsByCostable[$key] = $billingIntervalsByCostable["management_group:{$managementGroup->id}"];
                    $kontenByCostable[$key] = $kontenByCostable["management_group:{$managementGroup->id}"];
                    $costCategoriesByCostable[$key] = $allCostCategories;

                    // Nur für Kategorien mit Abrechnungsart "Individuell" nutzbar (siehe
                    // CostCategoryAllowsLeaseCostable) und geht nie in die Kontensystem-
                    // Bilanzierung ein, daher hier immer ein leeres Konten-Angebot.
                    foreach ($rentalUnit->leases as $lease) {
                        $key = "lease:{$lease->id}";
                        $costables[$key] = '––– '.$lease->tenantNames().' ('.$rentalUnit->label().')';
                        $billingIntervalsByCostable[$key] = $billingIntervalsByCostable["management_group:{$managementGroup->id}"];
                        $kontenByCostable[$key] = [];
                        $costCategoriesByCostable[$key] = $individualCostCategories;
                    }
                }
            }
        }

        $accountsByIssuer = InvoiceIssuerAccount::orderBy('customer_number')->get()
            ->groupBy('invoice_issuer_id')
            ->map(fn ($accounts) => $accounts->map(fn ($a) => [
                'id' => $a->id,
                'label' => $a->description ? "{$a->customer_number} ({$a->description})" : $a->customer_number,
            ])->values());

        return [
            'invoiceIssuers' => InvoiceIssuer::visibleTo($user)->orderBy('name')->get(),
            'costCategories' => CostCategory::visibleTo($user)->orderBy('name')->get(),
            'costables' => $costables,
            'billingIntervalsByCostable' => $billingIntervalsByCostable,
            'accountsByIssuer' => $accountsByIssuer,
            'kontenByCostable' => $kontenByCostable,
            'costCategoriesByCostable' => $costCategoriesByCostable,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateInvoice(Request $request): array
    {
        $signReference = $request->input('periodicity') === Invoice::PERIODICITY_MANUAL
            ? $request->input('occurrences.0.amount')
            : $request->input('amount');

        $validated = $request->validate([
            'invoice_issuer_id' => ['required', 'exists:invoice_issuers,id'],
            'invoice_issuer_account_id' => ['nullable', 'exists:invoice_issuer_accounts,id'],
            'invoice_number' => ['nullable', 'string', 'max:255'],
            'invoice_date' => ['required', 'date'],
            'booking_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
            'file' => ['nullable', ...InvoiceReceipt::rules()],
            'effective_amount' => ['nullable', 'numeric', new SameSignAs($signReference)],
            'periodicity' => ['required', 'in:once,weekly,biweekly,monthly,quarterly,yearly,manual'],
            'recurrence_start' => ['required_if:periodicity,weekly,biweekly,monthly,quarterly,yearly', 'nullable', 'date'],
            'recurrence_end' => ['required_if:periodicity,weekly,biweekly,monthly,quarterly,yearly', 'nullable', 'date', new DateAfterField($request->input('recurrence_start'), 'Das Ende der Wiederholung muss am oder nach dem Beginn liegen.', orEqual: true)],
            'occurrences' => ['required_if:periodicity,manual', 'array'],
            'occurrences.*.occurrence_date' => ['required_with:occurrences', 'date'],
            'occurrences.*.amount' => ['required_with:occurrences', 'numeric', new SameSignAs($signReference)],
            'amount' => ['required_unless:periodicity,manual', 'nullable', 'numeric'],
            'costable' => ['required', 'string'],
            'billing_interval_id' => ['required', 'exists:billing_intervals,id'],
            'cost_category_id' => ['required', 'exists:cost_categories,id'],
        ]);

        if ($validated['periodicity'] === Invoice::PERIODICITY_MANUAL && empty($validated['occurrences'])) {
            throw ValidationException::withMessages([
                'occurrences' => 'Bei händischer Periodizität muss mindestens ein Termin angegeben werden.',
            ]);
        }

        return $validated;
    }

    private function resolveCostable(string $type, int|string $id): Model
    {
        $class = Relation::getMorphedModel($type);

        abort_if($class === null, 404);

        return match ($class) {
            ManagementGroup::class => ManagementGroup::findOrFail($id),
            ManagementUnit::class => ManagementUnit::findOrFail($id),
            RentalUnit::class => RentalUnit::findOrFail($id),
            Lease::class => Lease::findOrFail($id),
            default => abort(404),
        };
    }
}
