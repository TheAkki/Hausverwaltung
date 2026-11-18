<?php

namespace App\Http\Controllers;

use App\Models\BillingTemplate;
use App\Models\CostCategory;
use App\Models\ManagementGroup;
use App\Services\FinancialOverviewService;
use App\Services\InvoiceSeries\ForecastRefresher;
use App\Services\RentPaymentGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * CRUD for Verwaltungsgruppen (management groups), scoped to groups visible to the
 * current user, with resource authorization via authorizeResource.
 */
class ManagementGroupController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(ManagementGroup::class, 'managementGroup');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): View
    {
        $managementGroups = ManagementGroup::visibleTo($request->user())
            ->withCount('managementUnits')
            ->with('userGroup.users')
            ->get();

        return view('management-groups.index', compact('managementGroups'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request): View
    {
        $userGroups = $request->user()->groups;

        return view('management-groups.create', compact('userGroups'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'description' => ['nullable', 'string'],
            'billing_start' => ['nullable', 'date'],
            'user_group_id' => ['required', 'exists:groups,id'],
            'variable_cost_forecast_mode' => ['nullable', Rule::in(ManagementGroup::VARIABLE_FORECAST_MODES)],
        ]);

        abort_unless($request->user()->groups->contains($validated['user_group_id']), 403);

        $validated['kontosystem_enabled'] = $request->boolean('kontosystem_enabled');
        $validated['variable_cost_forecast_mode'] ??= ManagementGroup::VARIABLE_FORECAST_LAST_BOOKING;

        $managementGroup = ManagementGroup::create($validated);

        return redirect()->route('management-groups.show', $managementGroup);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, ManagementGroup $managementGroup, FinancialOverviewService $overview, RentPaymentGenerator $rentPaymentGenerator): View
    {
        $managementGroup->load(
            'managementUnits.rentalUnits.leases.tenants',
            'managementUnits.rentalUnits.leases.leaseIntervals',
            'costCategories',
            'maintenanceContracts',
            'accounts'
        );
        $billingIntervals = $managementGroup->billingIntervals()->orderBy('start_date')->get();
        $costCategories = CostCategory::visibleTo($request->user())->orderBy('name')->get();

        $year = (int) $request->query('year', now()->year);

        $leases = $managementGroup->managementUnits
            ->flatMap->rentalUnits
            ->flatMap->leases;
        $income = $overview->incomeByRentalUnitType($leases, $year);

        foreach ($leases->flatMap->leaseIntervals as $leaseInterval) {
            $rentPaymentGenerator->generate($leaseInterval);
        }
        $managementGroup->load('managementUnits.rentalUnits.leases.leaseIntervals.rentPayments');
        $leases = $managementGroup->managementUnits->flatMap->rentalUnits->flatMap->leases;
        $bookedIncome = $overview->bookedIncomeByRentalUnitType($leases, $year);

        $costs = $managementGroup->costs()->with('costCategory', 'billingInterval', 'invoice.invoiceIssuer', 'invoice.invoiceIssuerAccount')->get()
            ->merge($managementGroup->managementUnits->flatMap(function ($managementUnit) {
                return $managementUnit->costs()->with('costCategory', 'billingInterval', 'invoice.invoiceIssuer', 'invoice.invoiceIssuerAccount')->get()
                    ->merge($managementUnit->rentalUnits->flatMap(
                        fn ($ru) => $ru->costs()->with('costCategory', 'billingInterval', 'invoice.invoiceIssuer', 'invoice.invoiceIssuerAccount')->get()
                    ));
            }));
        $expenses = $overview->expensesByCategory($costs, $year);
        $bookedExpenses = $overview->expensesByCategory($costs->filter(fn ($cost) => $cost->booking_date !== null), $year, 'booking_date');

        // "Übersicht aller Buchungen" (Konzept.md "### Betriebskostenabrechnung"): gibt
        // es sowohl für die Verwaltungsgruppe als auch für die Verwaltungseinheit (siehe
        // ManagementUnitController::show()) – hier mit demselben $costs, das auch schon
        // für die Ausgabenübersicht oben verwendet wird (Verwaltungsgruppe selbst plus
        // alle untergeordneten Verwaltungseinheiten/Mieteinheiten).
        $bookingsByInterval = $billingIntervals->mapWithKeys(
            fn ($billingInterval) => [$billingInterval->id => $overview->bookingsByCategory($costs, $billingInterval->id)]
        );

        $rentalUnits = $managementGroup->managementUnits->flatMap->rentalUnits;
        $occupancyPlan = $overview->occupancyPlan($rentalUnits, $year);
        // Only auto-expand when the Belegungsplan's own year switcher changed the year
        // (marked via "plan"), not when the unrelated Einnahmen/Ausgaben switcher on the
        // same page did.
        $occupancyPlanOpen = $request->has('plan');

        return view('management-groups.show', compact(
            'managementGroup', 'billingIntervals', 'costCategories', 'year', 'income', 'bookedIncome',
            'expenses', 'bookedExpenses', 'occupancyPlan', 'occupancyPlanOpen', 'bookingsByInterval'
        ));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, ManagementGroup $managementGroup): View
    {
        $userGroups = $request->user()->groups;
        $billingTemplates = BillingTemplate::orderBy('name')->get();

        return view('management-groups.edit', compact('managementGroup', 'userGroups', 'billingTemplates'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ManagementGroup $managementGroup, ForecastRefresher $forecastRefresher): RedirectResponse
    {
        $validated = $request->validate([
            'description' => ['nullable', 'string'],
            'billing_start' => ['nullable', 'date'],
            'user_group_id' => ['required', 'exists:groups,id'],
            'default_billing_template_id' => ['nullable', 'exists:billing_templates,id'],
            'variable_cost_forecast_mode' => ['nullable', Rule::in(ManagementGroup::VARIABLE_FORECAST_MODES)],
        ]);

        abort_unless($request->user()->groups->contains($validated['user_group_id']), 403);

        $validated['kontosystem_enabled'] = $request->boolean('kontosystem_enabled');
        $validated['variable_cost_forecast_mode'] ??= $managementGroup->variable_cost_forecast_mode;

        // Only worth the (potentially many-invoices-wide) refresh when the mode
        // actually changed – every other save (Beschreibung, Kontensystem, …) leaves
        // it untouched.
        $forecastModeChanged = $validated['variable_cost_forecast_mode'] !== $managementGroup->variable_cost_forecast_mode;

        $managementGroup->update($validated);

        if ($forecastModeChanged) {
            $forecastRefresher->refreshManagementGroup($managementGroup);
        }

        return redirect()->route('management-groups.show', $managementGroup);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ManagementGroup $managementGroup): RedirectResponse
    {
        $managementGroup->delete();

        return redirect()->route('management-groups.index');
    }
}
