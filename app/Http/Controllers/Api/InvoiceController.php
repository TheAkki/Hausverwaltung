<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CostCategory;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\ManagementUnit;
use App\Models\RentalUnit;
use App\Rules\DateAfterField;
use App\Rules\SameSignAs;
use App\Services\GroupOwnership\GroupOwnership;
use App\Services\InvoiceReceipt;
use App\Services\InvoiceSeries\CostableManagementGroup;
use App\Services\InvoiceSeries\CostSegment;
use App\Services\InvoiceSeries\ForecastRefresher;
use App\Services\InvoiceSeries\InvalidSeriesException;
use App\Services\InvoiceSeries\InvoiceBookingRecorder;
use App\Services\InvoiceSeries\InvoiceForecastWriter;
use App\Services\InvoiceSeries\InvoiceSeriesService;
use App\Services\InvoiceSeries\SeriesInputResolver;
use App\Support\FileStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * REST-API-CRUD für Rechnungen (Konzept.md "## API"), gespiegelt von
 * InvoiceController. Eine Rechnung mit negativem Betrag ist eine Einnahme
 * (Rückzahlung/Gutschrift, z. B. Stromrückzahlung), eine mit positivem Betrag eine
 * Ausgabe – beide laufen über dieselben Endpunkte.
 */
class InvoiceController extends Controller
{
    public const TYPE_INCOME = 'income';

    public const TYPE_EXPENSE = 'expense';

    private const PER_PAGE = 20;

    /**
     * Mit "generate_costs: false" legt store() keine Kostenzeilen automatisch an –
     * für Importe, die die Kostenzeilen anschließend selbst einzeln anlegen.
     */
    public const GENERATE_COSTS = 'generate_costs';

    /**
     * Mit "allocations" (Aufteilung auf Kategorien) oder "segments" (Betragsintervalle)
     * legt store() die Kostenzeilen mit derselben Logik an wie der Seeder
     * (InvoiceForecastWriter); sonst wie das Webformular (eine Kategorie).
     */
    public const SERIES_FIELDS = ['allocations', 'segments'];

    private const RECURRING_PERIODICITIES = ['weekly', 'biweekly', 'monthly', 'quarterly', 'yearly'];

    public function __construct()
    {
        $this->authorizeResource(Invoice::class, 'invoice');
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::in([self::TYPE_INCOME, self::TYPE_EXPENSE])],
            'search' => ['nullable', 'string'],
            'invoice_number' => ['nullable', 'string'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'invoice_date' => ['nullable', 'date'],
            'invoice_issuer_id' => ['nullable', 'integer'],
            'costable' => ['nullable', 'string', 'regex:/^[a-z_]+:\d+$/'],
        ]);

        $query = Invoice::visibleTo($request->user())
            ->with('invoiceIssuer', 'costable', 'costs')
            ->latest('invoice_date');

        if (! $request->boolean('show_completed')) {
            $query->where(fn ($q) => $q->whereDoesntHave('costs')->orWhereHas('costs', fn ($q2) => $q2->whereNull('booking_date')));
        }

        $this->applyFilters($query, $filters);

        return response()->json($query->paginate(self::PER_PAGE)->withQueryString());
    }

    /**
     * "Nach Rechnungen suchen" (Konzept.md "## API"): Freitext in Rechnungssteller
     * (Name, Vorname, Firma) und Beschreibung, Rechnungsdatum-Zeitraum und
     * Rechnungsnummer – alle angegebenen Kriterien UND-verknüpft.
     *
     * @param  Builder<Invoice>  $query
     * @param  array<string, string|null>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $type = $filters['type'] ?? null;
        if ($type !== null) {
            $query->where('amount', $type === self::TYPE_INCOME ? '<' : '>=', 0);
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn ($q) => $q->where('description', 'like', $term)
                ->orWhereHas('invoiceIssuer', fn ($iq) => $iq->where('name', 'like', $term)
                    ->orWhere('first_name', 'like', $term)
                    ->orWhere('company_name', 'like', $term)));
        }

        if (! empty($filters['invoice_number'])) {
            $query->where('invoice_number', 'like', '%'.$filters['invoice_number'].'%');
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('invoice_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('invoice_date', '<=', $filters['date_to']);
        }

        $this->applyExactFilters($query, $filters);
    }

    /**
     * Exakte Filter, mit denen ein Import eine schon vorhandene Rechnung wiederfindet:
     * Rechnungsdatum, Rechnungssteller und Zuordnung ("management_group:5").
     *
     * @param  Builder<Invoice>  $query
     * @param  array<string, string|null>  $filters
     */
    private function applyExactFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['invoice_date'])) {
            $query->whereDate('invoice_date', $filters['invoice_date']);
        }

        if (! empty($filters['invoice_issuer_id'])) {
            $query->where('invoice_issuer_id', $filters['invoice_issuer_id']);
        }

        if (! empty($filters['costable'])) {
            [$type, $id] = explode(':', $filters['costable']);
            $query->where('costable_type', $type)->where('costable_id', $id);
        }
    }

    public function store(Request $request, InvoiceSeriesService $series, InvoiceForecastWriter $writer, ForecastRefresher $refresher): JsonResponse
    {
        if ($request->hasAny(self::SERIES_FIELDS)) {
            return $this->storeSeries($request, $writer, $refresher);
        }

        $validated = $this->validateInvoice($request);

        abort_unless(
            InvoiceIssuer::visibleTo($request->user())->whereKey($validated['invoice_issuer_id'])->exists(),
            403
        );

        [$costableType, $costableId] = explode(':', $validated['costable']);
        $costable = $this->resolveCostable($costableType, $costableId);
        $this->authorize('view', $costable);

        $generateCosts = $request->boolean(self::GENERATE_COSTS, true);
        $costCategory = $generateCosts ? CostCategory::findOrFail($validated['cost_category_id']) : null;

        if ($costCategory !== null) {
            $this->authorize('view', $costCategory);
            GroupOwnership::assertCategoryFits($costCategory, CostableManagementGroup::of($costable));
        }

        GroupOwnership::assertIssuerFits(InvoiceIssuer::findOrFail($validated['invoice_issuer_id']), CostableManagementGroup::of($costable));

        $invoice = Invoice::create([
            ...collect($validated)->except(['file', self::GENERATE_COSTS])->all(),
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

        if ($costCategory !== null) {
            $this->rejectInvalidSeries(fn () => $series->write($invoice, $costable, $costCategory));
        }

        return response()->json($invoice, 201);
    }

    /**
     * Rechnung mit Aufteilung bzw. Betragsintervallen: legt die Rechnung an und
     * schreibt ihre Kostenzeilen (Prognose) über den InvoiceForecastWriter.
     */
    private function storeSeries(Request $request, InvoiceForecastWriter $writer, ForecastRefresher $refresher): JsonResponse
    {
        $validated = $request->validate($this->seriesRules($request));

        abort_unless(
            InvoiceIssuer::visibleTo($request->user())->whereKey($validated['invoice_issuer_id'])->exists(),
            403
        );

        [$costableType, $costableId] = explode(':', $validated['costable']);
        $costable = $this->resolveCostable($costableType, $costableId);
        $this->authorize('view', $costable);

        $resolver = new SeriesInputResolver(CostableManagementGroup::of($costable), $request->user(), ! $request->boolean('disable_amount_cap'));
        $segments = isset($validated['segments']) ? $resolver->segments($validated['segments'], $validated['allocations'] ?? [], 'segments') : [];
        GroupOwnership::assertIssuerFits(InvoiceIssuer::findOrFail($validated['invoice_issuer_id']), CostableManagementGroup::of($costable));

        $invoice = Invoice::create([
            ...collect($validated)->except(['file', 'allocations', 'segments', 'billing_interval_id'])->all(),
            'costable_type' => $costable->getMorphClass(),
            'costable_id' => $costable->getKey(),
            'amount' => $segments !== [] ? $segments[0]->amount : $validated['amount'],
            'disable_amount_cap' => $request->boolean('disable_amount_cap'),
        ]);
        $this->attachUpload($request, $invoice);

        $this->writeSeries($invoice, $costable, $validated, $resolver, $segments, $writer, $refresher);

        return response()->json($invoice->fresh()->load('costs'), 201);
    }

    /**
     * Weitere Termine einer bestehenden Rechnung schreiben, z. B. nach einer
     * Vertragsverlängerung: dieselben Regeln wie beim Anlegen mit Aufteilung bzw.
     * Betragsintervallen. Termine, für die es schon eine Kostenzeile gibt, bleiben
     * unverändert; Beginn und Ende der Wiederholung werden auf die neuen Werte gesetzt.
     */
    public function extendSeries(Request $request, Invoice $invoice, InvoiceForecastWriter $writer, ForecastRefresher $refresher): JsonResponse
    {
        $this->authorize('update', $invoice);
        abort_if($invoice->periodicity === Invoice::PERIODICITY_MANUAL, 422, 'Händische Rechnungen haben keine Terminfolge.');

        $validated = $request->validate(collect($this->seriesRules($request))->only([
            'recurrence_start', 'recurrence_end', 'billing_interval_id', 'allocations', 'allocations.*.cost_category_id',
            'allocations.*.amount', 'segments', 'segments.*.start_date', 'segments.*.end_date', 'segments.*.amount',
            'segments.*.note', 'segments.*.allocations', 'segments.*.allocations.*.cost_category_id',
            'segments.*.allocations.*.amount',
        ])->all());

        $resolver = new SeriesInputResolver(CostableManagementGroup::of($invoice->costable), $request->user(), ! $invoice->disable_amount_cap);
        $segments = isset($validated['segments']) ? $resolver->segments($validated['segments'], $validated['allocations'] ?? [], 'segments') : [];
        $invoice->fill(collect($validated)->only(['recurrence_start', 'recurrence_end'])->all());

        $this->writeSeries($invoice, $invoice->costable, $validated, $resolver, $segments, $writer, $refresher);

        return response()->json($invoice->fresh()->load('costs'));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  list<CostSegment>  $segments
     */
    private function writeSeries(Invoice $invoice, Model $costable, array $validated, SeriesInputResolver $resolver, array $segments, InvoiceForecastWriter $writer, ForecastRefresher $refresher): void
    {
        $pin = $resolver->interval($validated['billing_interval_id'] ?? null, 'billing_interval_id');

        // Betragsintervalle sind exakt bekannt – die Prognose passt nur Rechnungen ohne sie an.
        if ($segments !== []) {
            $this->rejectInvalidSeries(fn () => $writer->writeSegments($invoice, $costable, $segments, $pin));

            return;
        }

        $anchor = InvoiceBookingRecorder::anchorOf($invoice);
        $allocations = $resolver->allocations($validated['allocations'], $invoice->effectiveAmount(), 'allocations');
        $this->rejectInvalidSeries(fn () => $writer->writeAllocations($invoice, $costable, $anchor, $invoice->recurrence_end?->toDateString(), $allocations, $pin));
        $refresher->refreshInvoice($invoice);
    }

    private function rejectInvalidSeries(callable $write): void
    {
        try {
            $write();
        } catch (InvalidSeriesException $exception) {
            throw ValidationException::withMessages(['recurrence_end' => $exception->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function seriesRules(Request $request): array
    {
        $hasSegments = $request->has('segments');

        return [
            'invoice_issuer_id' => ['required', 'exists:invoice_issuers,id'],
            'invoice_issuer_account_id' => ['nullable', 'exists:invoice_issuer_accounts,id'],
            'invoice_number' => ['nullable', 'string', 'max:255'],
            'invoice_date' => ['required', 'date'],
            'booking_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
            'file' => ['nullable', ...InvoiceReceipt::rules()],
            'effective_amount' => ['nullable', 'numeric', new SameSignAs($request->input('amount'))],
            'periodicity' => ['required', Rule::in($hasSegments ? self::RECURRING_PERIODICITIES : [Invoice::PERIODICITY_ONCE, ...self::RECURRING_PERIODICITIES])],
            // Mit Betragsintervallen ergeben sich Betrag, Beginn und Ende aus den Intervallen.
            'amount' => [Rule::requiredIf(! $hasSegments), Rule::prohibitedIf($hasSegments), 'nullable', 'numeric'],
            'recurrence_start' => [Rule::prohibitedIf($hasSegments), 'nullable', 'date'],
            'recurrence_end' => [Rule::prohibitedIf($hasSegments), 'nullable', 'date', new DateAfterField($request->input('recurrence_start'), 'Das Ende der Wiederholung muss am oder nach dem Beginn liegen.', orEqual: true)],
            'costable' => ['required', 'string'],
            'billing_interval_id' => ['nullable', 'integer'],
            'disable_amount_cap' => ['boolean'],
            'allocations' => [Rule::requiredIf(! $hasSegments), 'array', 'min:1'],
            'allocations.*.cost_category_id' => ['required', 'integer'],
            'allocations.*.amount' => ['nullable', 'numeric'],
            'segments' => ['array', 'min:1'],
            'segments.*.start_date' => ['required', 'date'],
            'segments.*.end_date' => ['nullable', 'date'],
            'segments.*.amount' => ['required', 'numeric'],
            'segments.*.note' => ['nullable', 'string'],
            'segments.*.allocations' => ['nullable', 'array'],
            'segments.*.allocations.*.cost_category_id' => ['required', 'integer'],
            'segments.*.allocations.*.amount' => ['nullable', 'numeric'],
        ];
    }

    public function show(Invoice $invoice): JsonResponse
    {
        $invoice->load('invoiceIssuer', 'costable', 'occurrences', 'costs.costCategory', 'costs.costable', 'costs.billingInterval', 'costs.accounts');

        return response()->json($invoice);
    }

    public function update(Request $request, Invoice $invoice): JsonResponse
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

        // Ein Kundenaccount gehört zu genau einem Rechnungssteller (ReferenceIntegrity).
        if ((int) $validated['invoice_issuer_id'] !== $invoice->invoice_issuer_id) {
            $validated['invoice_issuer_account_id'] = null;
        }

        $invoice->update(collect($validated)->except('file')->all());
        $this->attachUpload($request, $invoice);

        return response()->json($invoice);
    }

    public function destroy(Invoice $invoice): JsonResponse
    {
        app(InvoiceReceipt::class)->remove($invoice);

        $invoice->delete();

        return response()->json(status: 204);
    }

    /**
     * Beleg (Datei) an eine Rechnung anhängen bzw. einen vorhandenen ersetzen –
     * eigener POST-Endpunkt, da PHP multipart/form-data bei PUT nicht auswertet.
     */
    public function storeFile(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('update', $invoice);

        $request->validate(['file' => ['required', ...InvoiceReceipt::rules()]]);

        $this->attachUpload($request, $invoice);

        return response()->json($invoice);
    }

    public function showFile(Invoice $invoice): StreamedResponse
    {
        $this->authorize('view', $invoice);

        return FileStorage::response($invoice->file_path, $invoice->file_original_name);
    }

    public function destroyFile(Invoice $invoice): JsonResponse
    {
        $this->authorize('update', $invoice);

        app(InvoiceReceipt::class)->remove($invoice);
        $invoice->update(['file_path' => null, 'file_original_name' => null]);

        return response()->json(status: 204);
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
     * @return array<string, mixed>
     */
    private function validateInvoice(Request $request): array
    {
        $reference = $request->input('periodicity') === Invoice::PERIODICITY_MANUAL
            ? $request->input('occurrences.0.amount')
            : $request->input('amount');

        $recurrenceRequired = $request->boolean(self::GENERATE_COSTS, true)
            && in_array($request->input('periodicity'), self::RECURRING_PERIODICITIES, true);

        return $request->validate([
            'invoice_issuer_id' => ['required', 'exists:invoice_issuers,id'],
            'invoice_issuer_account_id' => ['nullable', 'exists:invoice_issuer_accounts,id'],
            'invoice_number' => ['nullable', 'string', 'max:255'],
            'invoice_date' => ['required', 'date'],
            'booking_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
            'file' => ['nullable', ...InvoiceReceipt::rules()],
            'effective_amount' => ['nullable', 'numeric', new SameSignAs($reference)],
            'periodicity' => ['required', 'in:once,weekly,biweekly,monthly,quarterly,yearly,manual'],
            // Ohne automatische Kostenzeilen (Import) wird der Wiederholungszeitraum
            // nicht gebraucht – wie bei geseedeten Rechnungen darf er dann fehlen.
            'recurrence_start' => [Rule::requiredIf($recurrenceRequired), 'nullable', 'date'],
            'recurrence_end' => [Rule::requiredIf($recurrenceRequired), 'nullable', 'date', new DateAfterField($request->input('recurrence_start'), 'Das Ende der Wiederholung muss am oder nach dem Beginn liegen.', orEqual: true)],
            'occurrences' => ['required_if:periodicity,manual', 'array'],
            'occurrences.*.occurrence_date' => ['required_with:occurrences', 'date'],
            'occurrences.*.amount' => ['required_with:occurrences', 'numeric', new SameSignAs($reference)],
            'amount' => ['required_unless:periodicity,manual', 'nullable', 'numeric'],
            'costable' => ['required', 'string'],
            self::GENERATE_COSTS => ['boolean'],
            'billing_interval_id' => ['required_unless:'.self::GENERATE_COSTS.',false,0', 'nullable', 'exists:billing_intervals,id'],
            'cost_category_id' => ['required_unless:'.self::GENERATE_COSTS.',false,0', 'nullable', 'exists:cost_categories,id'],
        ]);
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
