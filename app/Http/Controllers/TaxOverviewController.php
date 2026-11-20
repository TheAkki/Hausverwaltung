<?php

namespace App\Http\Controllers;

use App\Models\ManagementGroup;
use App\Services\FinancialOverviewService;
use App\Services\RentPaymentGenerator;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Shows the Steuerübersicht (tax overview) for a Verwaltungsgruppe: a Mieteinnahmen-
 * Pivot grouped by Etage (with Nachzahlungen/Rückzahlungen) and a Werbungskosten table
 * of steuerrelevante Kostenkategorien – both scoped to a selectable Abrechnungsjahr and
 * based only on tatsächlich gebuchte (confirmed) Beträge (Konzept.md "### Steuerübersicht").
 */
class TaxOverviewController extends Controller
{
    public function show(Request $request, ManagementGroup $managementGroup, FinancialOverviewService $overview, RentPaymentGenerator $rentPaymentGenerator): View
    {
        $this->authorize('view', $managementGroup);

        $year = (int) $request->query('year', now()->year);

        $managementGroup->load('managementUnits.rentalUnits.leases.tenants', 'managementUnits.rentalUnits.leases.leaseIntervals');
        $leases = $managementGroup->managementUnits->flatMap->rentalUnits->flatMap->leases;

        // Setzt rentPayments an den Intervallen – loadMissing lädt sie nicht noch einmal.
        $rentPaymentGenerator->generateMany($leases->flatMap->leaseIntervals);

        $managementGroup->loadMissing(
            'managementUnits.rentalUnits.leases.leaseIntervals.rentPayments',
            'managementUnits.rentalUnits.leases.settlementClaims'
        );
        $leases = $managementGroup->managementUnits->flatMap->rentalUnits->flatMap->leases;

        $income = $overview->taxOverviewIncomeByFloor($leases, $year);

        $costs = $managementGroup->costs()->with('costCategory', 'billingInterval', 'invoice.invoiceIssuer', 'invoice.invoiceIssuerAccount')->get()
            ->merge($managementGroup->managementUnits->flatMap(function ($managementUnit) {
                return $managementUnit->costs()->with('costCategory', 'billingInterval', 'invoice.invoiceIssuer', 'invoice.invoiceIssuerAccount')->get()
                    ->merge($managementUnit->rentalUnits->flatMap(
                        fn ($ru) => $ru->costs()->with('costCategory', 'billingInterval', 'invoice.invoiceIssuer', 'invoice.invoiceIssuerAccount')->get()
                    ));
            }))
            ->filter(fn ($cost) => $cost->booking_date !== null && $cost->costCategory->is_tax_relevant);

        $expenses = $overview->expensesByCategory($costs, $year, 'booking_date');

        return view('tax-overview.show', compact('managementGroup', 'year', 'income', 'expenses'));
    }
}
