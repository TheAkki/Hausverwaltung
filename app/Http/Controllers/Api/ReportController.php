<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BillingStatementController;
use App\Http\Controllers\Controller;
use App\Models\BillingInterval;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\ManagementUnit;
use App\Services\FinancialOverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * "Berichte erstellen" (Konzept.md "## API"): liefert dieselbe Betriebskosten
 * abrechnung wie die Web-Oberfläche – als JSON-Rohdaten (Einnahmen/Ausgaben, siehe
 * FinancialOverviewService) oder als fertige PDF-Abrechnung. Die PDF-Generierung
 * selbst delegiert an BillingStatementController, um dessen bestehende Berechnung
 * (BillingCostAllocationService, BillingTemplateRenderer) nicht zu duplizieren.
 */
class ReportController extends Controller
{
    /**
     * Rohdaten-Bericht: Einnahmen je Mieteinheitstyp und Ausgaben je Kategorie einer
     * Verwaltungsgruppe für ein gegebenes Jahr (Default: laufendes Jahr).
     */
    public function financialOverview(Request $request, ManagementGroup $managementGroup, FinancialOverviewService $overview): JsonResponse
    {
        $this->authorize('view', $managementGroup);

        $year = (int) $request->query('year', now()->year);

        $managementGroup->load('managementUnits.rentalUnits.leases.leaseIntervals');

        $leases = $managementGroup->managementUnits->flatMap->rentalUnits->flatMap->leases;
        $income = $overview->incomeByRentalUnitType($leases, $year);

        $costs = $managementGroup->costs()->with('costCategory')->get()
            ->merge($managementGroup->managementUnits->flatMap(function (ManagementUnit $managementUnit) {
                return $managementUnit->costs()->with('costCategory')->get()
                    ->merge($managementUnit->rentalUnits->flatMap(
                        fn ($rentalUnit) => $rentalUnit->costs()->with('costCategory')->get()
                    ));
            }));
        $expenses = $overview->expensesByCategory($costs, $year);

        return response()->json([
            'year' => $year,
            'income' => $income,
            'expenses' => $expenses,
        ]);
    }

    /**
     * Kombinierte Betriebskostenabrechnung einer Verwaltungseinheit (alle
     * abrechnungsrelevanten Mieter plus Leerstände) als PDF-Download.
     */
    public function statementPdf(ManagementUnit $managementUnit, BillingInterval $billingInterval): Response
    {
        return app()->call([app(BillingStatementController::class), 'pdf'], [
            'managementUnit' => $managementUnit,
            'billingInterval' => $billingInterval,
        ]);
    }

    /**
     * Einzelabrechnung eines Mietvertrags als PDF-Download.
     */
    public function leaseStatementPdf(ManagementUnit $managementUnit, BillingInterval $billingInterval, Lease $lease): Response
    {
        return app()->call([app(BillingStatementController::class), 'pdfLease'], [
            'managementUnit' => $managementUnit,
            'billingInterval' => $billingInterval,
            'lease' => $lease,
        ]);
    }

    /**
     * Alle Einzelabrechnungen einer Verwaltungseinheit als ZIP-Download.
     */
    public function statementZip(ManagementUnit $managementUnit, BillingInterval $billingInterval): BinaryFileResponse
    {
        return app()->call([app(BillingStatementController::class), 'zip'], [
            'managementUnit' => $managementUnit,
            'billingInterval' => $billingInterval,
        ]);
    }
}
