<?php

namespace App\Http\Controllers;

use App\Models\ManagementGroup;
use App\Models\ManagementUnit;
use App\Services\BillingCostAllocationService;
use App\Services\BillingStatementLeaseResolver;
use App\Services\FinancialOverviewService;
use App\Services\RentPaymentGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CRUD for Verwaltungseinheiten (management units), with resource authorization via
 * authorizeResource and a show view including income/expense financial overviews.
 */
class ManagementUnitController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(ManagementUnit::class, 'managementUnit');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): View
    {
        $managementUnits = ManagementUnit::whereHas(
            'managementGroup',
            fn ($q) => $q->visibleTo($request->user())
        )->with('managementGroup')->get();

        return view('management-units.index', compact('managementUnits'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request): View
    {
        $managementGroup = ManagementGroup::visibleTo($request->user())
            ->findOrFail($request->query('management_group_id'));

        return view('management-units.create', compact('managementGroup'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
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

        return redirect()->route('management-units.show', $managementUnit);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, ManagementUnit $managementUnit, FinancialOverviewService $overview, RentPaymentGenerator $rentPaymentGenerator, BillingStatementLeaseResolver $leaseResolver, BillingCostAllocationService $costAllocation): View
    {
        $managementUnit->load('rentalUnits.leases.tenants', 'rentalUnits.leases.leaseIntervals');

        $year = (int) $request->query('year', now()->year);
        $incomeView = $request->query('income_view', 'tenant');

        if (! in_array($incomeView, ['tenant_unit', 'tenant', 'tenant_split'], true)) {
            $incomeView = 'tenant';
        }

        $leases = $managementUnit->rentalUnits->flatMap->leases;

        $income = match ($incomeView) {
            'tenant' => $overview->incomeMatrixByTenant($leases, $year),
            'tenant_split' => $overview->incomeMatrixByTenantSplit($leases, $year),
            default => $overview->incomeMatrix($leases, $year),
        };

        // Setzt rentPayments an den Intervallen – loadMissing lädt sie nicht noch einmal.
        $rentPaymentGenerator->generateMany($leases->flatMap->leaseIntervals);
        $managementUnit->loadMissing('rentalUnits.leases.leaseIntervals.rentPayments');
        $leases = $managementUnit->rentalUnits->flatMap->leases;

        $bookedIncome = match ($incomeView) {
            'tenant' => $overview->bookedIncomeMatrixByTenant($leases, $year),
            'tenant_split' => $overview->bookedIncomeMatrixByTenantSplit($leases, $year),
            default => $overview->bookedIncomeMatrix($leases, $year),
        };

        $occupancy = $overview->occupancyMatrix($managementUnit->rentalUnits, $year);
        // Only auto-expand when the Belegungsmatrix's own year switcher changed the year
        // (marked via "matrix"), not when the unrelated Mieteinnahmen/Ausgaben switcher on
        // the same page did – otherwise a user's explicit collapse would keep getting
        // undone by navigating years for the income/expense tables.
        $occupancyMatrixOpen = $request->has('matrix');

        $occupancyPlan = $overview->occupancyPlan($managementUnit->rentalUnits, $year);
        // Same "only auto-expand via its own year switcher" behavior as the Belegungsmatrix
        // above, marked via "plan" instead of "matrix" so the two don't interfere.
        $occupancyPlanOpen = $request->has('plan');

        $costs = $managementUnit->costs()->with('costCategory', 'billingInterval', 'invoice.invoiceIssuer', 'invoice.invoiceIssuerAccount')->get()
            ->merge($managementUnit->rentalUnits->flatMap(
                fn ($ru) => $ru->costs()->with('costCategory', 'billingInterval', 'invoice.invoiceIssuer', 'invoice.invoiceIssuerAccount')->get()
            ));
        $expenses = $overview->expensesByCategory($costs, $year);
        $bookedExpenses = $overview->expensesByCategory($costs->filter(fn ($cost) => $cost->booking_date !== null), $year, 'booking_date');

        $billingIntervals = $managementUnit->managementGroup->billingIntervals()->orderBy('start_date', 'desc')->get();
        $billingStatementLeases = $billingIntervals->mapWithKeys(
            fn ($billingInterval) => [$billingInterval->id => $leaseResolver->summaryForInterval($managementUnit, $billingInterval)]
        );
        // "Übersicht aller Buchungen" (Konzept.md "### Betriebskostenabrechnung"):
        // reuses the same $costs already fetched above for Prognostizierte/Gebuchte
        // Ausgaben, just grouped per Abrechnungsintervall instead of per Jahr.
        $costsByInterval = $costs->groupBy('billing_interval_id');
        $bookingsByInterval = $billingIntervals->mapWithKeys(
            fn ($billingInterval) => [$billingInterval->id => $overview->bookingsByCategory($costsByInterval->get($billingInterval->id, collect()), $billingInterval->id)]
        );

        // "Leerstand" (Konzept.md "##### Leerstände"): dieselben Phantom-Mietvertrags-
        // Einträge, die die echte Betriebskostenabrechnung selbst mit anzeigt (siehe
        // BillingCostAllocationService::vacancyLeaseEntries()) – hier nur zur
        // Übersicht/Forderungsverwaltung gelistet, die eigentliche Kostenberechnung
        // findet in der Abrechnung selbst statt.
        $vacancyByInterval = $billingIntervals->mapWithKeys(
            fn ($billingInterval) => [$billingInterval->id => $costAllocation->vacancyLeaseEntries($managementUnit, $billingInterval)]
        );

        return view('management-units.show', compact(
            'managementUnit', 'year', 'income', 'incomeView', 'bookedIncome', 'occupancy', 'occupancyMatrixOpen',
            'expenses', 'bookedExpenses', 'billingIntervals', 'occupancyPlan', 'occupancyPlanOpen', 'billingStatementLeases',
            'bookingsByInterval', 'vacancyByInterval'
        ));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(ManagementUnit $managementUnit): View
    {
        return view('management-units.edit', compact('managementUnit'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ManagementUnit $managementUnit): RedirectResponse
    {
        $validated = $request->validate([
            'street' => ['required', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:20'],
            'city' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $managementUnit->update($validated);

        return redirect()->route('management-units.show', $managementUnit);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ManagementUnit $managementUnit): RedirectResponse
    {
        $managementGroup = $managementUnit->managementGroup;

        $managementUnit->delete();

        return redirect()->route('management-groups.show', $managementGroup);
    }
}
