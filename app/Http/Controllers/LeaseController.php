<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\ManagementUnit;
use App\Models\RentalUnit;
use App\Models\Tenant;
use App\Rules\DateAfterField;
use App\Rules\NotOverlappingLease;
use App\Rules\PreContractRequiresFollowingLease;
use App\Rules\ValidLeaseCoupling;
use App\Services\RentPaymentGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * CRUD for Mietverträge (leases), with resource authorization via authorizeResource
 * and overlap validation against other leases of the same rental unit.
 */
class LeaseController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Lease::class, 'lease');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): View
    {
        $showExpired = $request->boolean('show_expired');

        $leases = Lease::whereHas(
            'rentalUnit.managementUnit.managementGroup',
            fn ($q) => $q->visibleTo($request->user())
        )->with('tenants', 'rentalUnit.managementUnit')->get();

        if (! $showExpired) {
            $leases = $leases->reject->isExpired()->values();
        }

        return view('leases.index', compact('leases', 'showExpired'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * The dialog adapts to how specific the calling context is: opened from a
     * Mieteinheit the rental unit is fixed; opened from a Verwaltungseinheit only
     * that unit's Mieteinheiten are selectable; opened without context (e.g. from
     * the global Mietverträge overview) any visible Mieteinheit can be chosen.
     */
    public function create(Request $request): View
    {
        $tenants = Tenant::visibleTo($request->user())->orderBy('last_name')->get();

        if ($request->filled('rental_unit_id')) {
            $rentalUnit = RentalUnit::findOrFail($request->query('rental_unit_id'));
            $this->authorize('view', $rentalUnit);

            return view('leases.create', [
                'scope' => 'rental_unit',
                'rentalUnit' => $rentalUnit,
                'tenants' => $tenants,
                'referenceableLeases' => $this->referenceableLeasesFor($rentalUnit),
            ]);
        }

        if ($request->filled('management_unit_id')) {
            $managementUnit = ManagementUnit::findOrFail($request->query('management_unit_id'));
            $this->authorize('view', $managementUnit);

            return view('leases.create', [
                'scope' => 'management_unit',
                'managementUnit' => $managementUnit,
                'rentalUnits' => $managementUnit->rentalUnits,
                'tenants' => $tenants,
                'referenceableLeasesByRentalUnit' => $this->referenceableLeasesByRentalUnitFor($managementUnit->rentalUnits),
            ]);
        }

        $rentalUnits = RentalUnit::whereHas(
            'managementUnit.managementGroup',
            fn ($q) => $q->visibleTo($request->user())
        )->with('managementUnit.managementGroup')->get();

        return view('leases.create', [
            'scope' => 'global',
            'rentalUnits' => $rentalUnits,
            'tenants' => $tenants,
            'referenceableLeasesByRentalUnit' => $this->referenceableLeasesByRentalUnitFor($rentalUnits),
        ]);
    }

    /**
     * Konzept.md "### Detailbild Mieteinheit": the create dialog for a nicht
     * abrechnungsrelevante Mieteinheit (e.g. ein Stellplatz) offers coupling the new
     * Mietvertrag to an existing Mietvertrag of an abrechnungsrelevanten Mieteinheit of
     * the same Verwaltungseinheit instead of assigning its own Mieter – see
     * ValidLeaseCoupling. Empty (and unused by the view) for an abrechnungsrelevante
     * Mieteinheit, which may only ever be assigned its own Mieter.
     *
     * @return Collection<int, Lease>
     */
    private function referenceableLeasesFor(RentalUnit $rentalUnit): Collection
    {
        if ($rentalUnit->is_relevant_for_billing) {
            return collect();
        }

        return Lease::whereHas(
            'rentalUnit',
            fn ($q) => $q->where('management_unit_id', $rentalUnit->management_unit_id)->where('is_relevant_for_billing', true)
        )->with('tenants', 'rentalUnit')->get();
    }

    /**
     * Same as referenceableLeasesFor(), but for EVERY nicht abrechnungsrelevante
     * RentalUnit in $rentalUnits at once – used by the "management_unit"/"global"
     * scopes of the create dialog, where the Mieteinheit is only chosen client-side via
     * a <select> (so which candidate list applies isn't known server-side until the
     * form is actually submitted). The view sends this along as JSON and switches the
     * coupling option in/out via JavaScript as the user picks a different Mieteinheit.
     *
     * @param  Collection<int, RentalUnit>  $rentalUnits
     * @return array<int, array<int, array{id: int, label: string}>> keyed by rental_unit_id
     */
    private function referenceableLeasesByRentalUnitFor(Collection $rentalUnits): array
    {
        $nonBillingRelevant = $rentalUnits->where('is_relevant_for_billing', false);

        if ($nonBillingRelevant->isEmpty()) {
            return [];
        }

        $managementUnitIds = $nonBillingRelevant->pluck('management_unit_id')->unique();

        $leasesByManagementUnit = Lease::whereHas(
            'rentalUnit',
            fn ($q) => $q->whereIn('management_unit_id', $managementUnitIds)->where('is_relevant_for_billing', true)
        )->with('tenants', 'rentalUnit')->get()->groupBy(fn (Lease $lease) => $lease->rentalUnit->management_unit_id);

        return $nonBillingRelevant->mapWithKeys(function (RentalUnit $rentalUnit) use ($leasesByManagementUnit) {
            $leases = $leasesByManagementUnit->get($rentalUnit->management_unit_id, collect());

            return [$rentalUnit->id => $leases->map(fn (Lease $lease) => [
                'id' => $lease->id,
                'label' => "{$lease->rentalUnit->label()} — {$lease->tenantNames()}",
            ])->values()->all()];
        })->all();
    }

    /**
     * Store a newly created resource in storage.
     *
     * Konzept.md "### Detailbild Mieteinheit": "assignment_mode" chooses whether the
     * new Mietvertrag gets its own Mieter ("tenants", the default/only option for an
     * abrechnungsrelevante Mieteinheit) or is coupled to another Mietvertrag via
     * reference ("reference", only offered for a nicht abrechnungsrelevante Mieteinheit
     * – see ValidLeaseCoupling), inheriting that Mietvertrag's Mieter.
     */
    public function store(Request $request): RedirectResponse
    {
        $assignmentMode = $request->input('assignment_mode', 'tenants');

        $validated = $request->validate([
            'rental_unit_id' => ['required', 'exists:rental_units,id'],
            'assignment_mode' => ['nullable', 'in:tenants,reference'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', new DateAfterField($request->input('start_date'), 'Das Enddatum muss nach dem Startdatum liegen.')],
            'is_pre_contract' => ['boolean'],
            'rent_adjustment_type' => ['required', 'in:fixed,stepped,indexed'],
            'cold_rent' => ['required', 'numeric', 'min:0'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'min_term_months' => ['nullable', 'integer', 'min:0'],
            'notice_period' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            ...($assignmentMode === 'reference' ? [
                'coupled_to_lease_id' => ['required', 'integer', 'exists:leases,id'],
            ] : [
                'tenant_ids' => ['required', 'array', 'min:1'],
                'tenant_ids.*' => [Rule::exists('tenants', 'id')->whereIn('user_group_id', $request->user()->groups->pluck('id'))],
            ]),
        ]);

        $rentalUnit = RentalUnit::findOrFail($validated['rental_unit_id']);
        $this->authorize('view', $rentalUnit);

        $request->validate([
            'start_date' => [new NotOverlappingLease(
                $validated['rental_unit_id'],
                $validated['start_date'],
                $validated['end_date'] ?? null,
            )],
            'is_pre_contract' => [new PreContractRequiresFollowingLease(
                (int) $validated['rental_unit_id'],
                $validated['start_date'],
            )],
            ...($assignmentMode === 'reference' ? [
                'coupled_to_lease_id' => [new ValidLeaseCoupling(
                    (int) $validated['rental_unit_id'],
                    $validated['end_date'] ?? null,
                )],
            ] : []),
        ]);

        $attributes = collect($validated)->except(['tenant_ids', 'assignment_mode', 'coupled_to_lease_id'])->all();

        if ($assignmentMode === 'reference') {
            $targetLease = Lease::findOrFail($validated['coupled_to_lease_id']);
            $attributes['coupled_to_lease_id'] = $targetLease->id;
            $tenantIds = $targetLease->tenants()->pluck('tenants.id')->all();
        } else {
            $tenantIds = $validated['tenant_ids'];
        }

        $lease = Lease::create($attributes);
        $lease->tenants()->attach($tenantIds);

        return redirect()->route('leases.show', $lease);
    }

    /**
     * Display the specified resource.
     */
    public function show(Lease $lease, RentPaymentGenerator $rentPaymentGenerator): View
    {
        $lease->load(
            'tenants', 'rentalUnit.managementUnit.managementGroup', 'leaseIntervals', 'documents',
            'settlementClaims.billingInterval', 'coupledToLease.rentalUnit', 'coupledToLease.tenants',
            'rentPaymentContributions.leaseInterval.lease'
        );

        foreach ($lease->leaseIntervals as $interval) {
            $rentPaymentGenerator->generate($interval);
        }

        $lease->load('leaseIntervals.rentPayments.accounts', 'leaseIntervals.rentPayments.contributingLeases.rentalUnit');

        $managementGroup = $lease->rentalUnit->managementUnit->managementGroup;

        $invoices = Invoice::where('costable_type', $lease->getMorphClass())
            ->where('costable_id', $lease->id)
            ->with('invoiceIssuer')
            ->latest('invoice_date')
            ->get();

        return view('leases.show', compact('lease', 'managementGroup', 'invoices'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, Lease $lease): View
    {
        $tenants = Tenant::visibleTo($request->user())->orderBy('last_name')->get();

        return view('leases.edit', compact('lease', 'tenants'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Lease $lease): RedirectResponse
    {
        $currentInterval = $lease->currentLeaseInterval();

        $validated = $request->validate([
            'tenant_ids' => ['required', 'array', 'min:1'],
            'tenant_ids.*' => [Rule::exists('tenants', 'id')->whereIn('user_group_id', $request->user()->groups->pluck('id'))],
            'start_date' => ['required', 'date'],
            'end_date' => [
                'nullable', 'date',
                new DateAfterField($request->input('start_date'), 'Das Enddatum muss nach dem Startdatum liegen.'),
                // Setting the Lease's end date earlier than the currently active Mietintervall's
                // own start would otherwise let LeaseObserver silently propagate an end_date
                // before that interval's start_date onto it.
                ...($currentInterval !== null ? [new DateAfterField(
                    $currentInterval->start_date->toDateString(),
                    'Das Enddatum darf nicht vor dem Beginn des aktuellen Mietintervalls liegen.',
                    orEqual: true,
                )] : []),
            ],
            'is_pre_contract' => ['boolean'],
            'rent_adjustment_type' => ['required', 'in:fixed,stepped,indexed'],
            'cold_rent' => ['required', 'numeric', 'min:0'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'min_term_months' => ['nullable', 'integer', 'min:0'],
            'notice_period' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $request->validate([
            'start_date' => [new NotOverlappingLease(
                $lease->rental_unit_id,
                $validated['start_date'],
                $validated['end_date'] ?? null,
                $lease->id,
            )],
            'is_pre_contract' => [new PreContractRequiresFollowingLease(
                $lease->rental_unit_id,
                $validated['start_date'],
                $lease->id,
            )],
        ]);

        // An unchecked checkbox is absent from the request, so set the flag explicitly
        // to let the Vorvertrag option be turned off again.
        $lease->update(collect($validated)->except('tenant_ids')
            ->merge(['is_pre_contract' => $request->boolean('is_pre_contract')])->all());
        $lease->tenants()->sync($validated['tenant_ids']);

        return redirect()->route('leases.show', $lease);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Lease $lease): RedirectResponse
    {
        $rentalUnit = $lease->rentalUnit;

        $lease->delete();

        return redirect()->route('rental-units.show', $rentalUnit);
    }
}
