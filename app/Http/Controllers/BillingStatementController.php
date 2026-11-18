<?php

namespace App\Http\Controllers;

use App\Models\BillingInterval;
use App\Models\BillingTemplate;
use App\Models\Cost;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\ManagementUnit;
use App\Models\RentalUnit;
use App\Services\BillingCostAllocationService;
use App\Services\BillingStatementLeaseResolver;
use App\Services\BillingTemplateRenderer;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Renders the Betriebskostenabrechnung (operating cost billing statement) for a
 * ManagementUnit/BillingInterval as an HTML preview or a downloadable PDF – either
 * combined for all abrechnungsrelevant Mieter at once, or for a single Mietvertrag.
 */
class BillingStatementController extends Controller
{
    /**
     * Preview the combined rendered billing statement (all relevant Mieter) as HTML.
     */
    public function show(ManagementUnit $managementUnit, BillingInterval $billingInterval, BillingStatementLeaseResolver $resolver, BillingTemplateRenderer $renderer, BillingCostAllocationService $costAllocation): Response
    {
        $this->authorize('view', $managementUnit);

        $leases = $this->leasesWithVacancies($managementUnit, $billingInterval, $resolver, $costAllocation);
        $prepared = $this->prepare($managementUnit, $billingInterval, $leases, $costAllocation);

        return response($renderer->render($prepared['template']->content, $prepared['template']->header, $prepared['template']->footer, $prepared['data'], $prepared['template']->css_content));
    }

    /**
     * Export the combined rendered billing statement (all relevant Mieter) as a single
     * PDF download.
     */
    public function pdf(ManagementUnit $managementUnit, BillingInterval $billingInterval, BillingStatementLeaseResolver $resolver, BillingTemplateRenderer $renderer, BillingCostAllocationService $costAllocation): Response
    {
        $this->authorize('view', $managementUnit);

        $leases = $this->leasesWithVacancies($managementUnit, $billingInterval, $resolver, $costAllocation);
        $prepared = $this->prepare($managementUnit, $billingInterval, $leases, $costAllocation);
        $pdf = $renderer->renderPdf($prepared['template']->content, $prepared['template']->header, $prepared['template']->footer, $prepared['data'], $prepared['template']->css_content);

        return $this->pdfDownloadResponse($pdf, "betriebskostenabrechnung-{$managementUnit->id}-{$billingInterval->id}.pdf");
    }

    /**
     * Konzept.md "##### Leerstände": leerstehende, abrechnungsrelevante Mieteinheiten
     * werden "wie ein Mieter gehandhabt" und erscheinen mit in der Betriebskosten
     * abrechnung – nur in der KOMBINIERTEN Abrechnung (show()/pdf()), nicht auf der
     * Einzelabrechnung eines Mietvertrags (showLease()/pdfLease()) oder im ZIP (dort
     * gibt es je Datei genau einen echten Mieter als Empfänger).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function leasesWithVacancies(ManagementUnit $managementUnit, BillingInterval $billingInterval, BillingStatementLeaseResolver $resolver, BillingCostAllocationService $costAllocation): Collection
    {
        return $resolver->forInterval($managementUnit, $billingInterval)
            ->concat($costAllocation->vacancyLeaseEntries($managementUnit, $billingInterval));
    }

    /**
     * Preview the billing statement of a single Mietvertrag as HTML.
     */
    public function showLease(ManagementUnit $managementUnit, BillingInterval $billingInterval, Lease $lease, BillingStatementLeaseResolver $resolver, BillingTemplateRenderer $renderer, BillingCostAllocationService $costAllocation): Response
    {
        $this->authorize('view', $managementUnit);

        $entry = $this->findLeaseEntry($managementUnit, $billingInterval, $lease, $resolver);
        $prepared = $this->prepare($managementUnit, $billingInterval, collect([$entry]), $costAllocation);

        return response($renderer->render($prepared['template']->content, $prepared['template']->header, $prepared['template']->footer, $prepared['data'], $prepared['template']->css_content));
    }

    /**
     * Export a single Mietvertrag's billing statement as a PDF download, named
     * "{Abrechnungsjahr}__Betriebskostenabrechnung__{Mietername}.pdf".
     */
    public function pdfLease(ManagementUnit $managementUnit, BillingInterval $billingInterval, Lease $lease, BillingStatementLeaseResolver $resolver, BillingTemplateRenderer $renderer, BillingCostAllocationService $costAllocation): Response
    {
        $this->authorize('view', $managementUnit);

        $entry = $this->findLeaseEntry($managementUnit, $billingInterval, $lease, $resolver);
        $prepared = $this->prepare($managementUnit, $billingInterval, collect([$entry]), $costAllocation);
        $pdf = $renderer->renderPdf($prepared['template']->content, $prepared['template']->header, $prepared['template']->footer, $prepared['data'], $prepared['template']->css_content);

        return $this->pdfDownloadResponse($pdf, $this->pdfFilename($billingInterval, $entry['tenantNames']));
    }

    /**
     * Downloads a ZIP archive containing one PDF per relevant Mietvertrag, each named
     * "{Abrechnungsjahr}_Betriebskostenabrechnung_{Mietername}.pdf".
     */
    public function zip(ManagementUnit $managementUnit, BillingInterval $billingInterval, BillingStatementLeaseResolver $resolver, BillingTemplateRenderer $renderer, BillingCostAllocationService $costAllocation): BinaryFileResponse
    {
        $this->authorize('view', $managementUnit);

        $entries = $resolver->forInterval($managementUnit, $billingInterval);

        $zipPath = tempnam(sys_get_temp_dir(), 'betriebskostenabrechnungen-');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::OVERWRITE);

        foreach ($entries as $entry) {
            $prepared = $this->prepare($managementUnit, $billingInterval, collect([$entry]), $costAllocation);
            $pdf = $renderer->renderPdf($prepared['template']->content, $prepared['template']->header, $prepared['template']->footer, $prepared['data'], $prepared['template']->css_content);
            $zip->addFromString($this->pdfFilename($billingInterval, $entry['tenantNames']), $pdf);
        }

        $zip->close();

        $year = $billingInterval->start_date->year;

        return response()->download($zipPath, "{$year}_Betriebskostenabrechnungen.zip")->deleteFileAfterSend();
    }

    private function pdfDownloadResponse(string $pdf, string $filename): Response
    {
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * @return array{lease: Lease, tenants: Collection, tenantNames: string, rentalUnit: RentalUnit, periodStart: Carbon, periodEnd: Carbon, days: int}
     */
    private function findLeaseEntry(ManagementUnit $managementUnit, BillingInterval $billingInterval, Lease $lease, BillingStatementLeaseResolver $resolver): array
    {
        $entry = $resolver->forInterval($managementUnit, $billingInterval)->first(fn ($e) => $e['lease']->is($lease));

        abort_if($entry === null, 404, 'Dieser Mietvertrag ist in diesem Abrechnungsintervall nicht abrechnungsrelevant.');

        return $entry;
    }

    private function pdfFilename(BillingInterval $billingInterval, string $tenantNames): string
    {
        $year = $billingInterval->start_date->year;
        $safeName = str_replace(' ', '_', preg_replace('/[\/\\\\:*?"<>|]/', '-', $tenantNames));

        return "{$year}__Betriebskostenabrechnung__{$safeName}.pdf";
    }

    /**
     * Builds the template + data pair shared by both the HTML preview and the PDF
     * export – kept separate from the actual rendering (BillingTemplateRenderer::
     * render() vs. renderPdf()) so both output modes reuse the exact same computed
     * numbers.
     *
     * @param  Collection<int, array{lease: Lease, tenants: Collection, tenantNames: string, rentalUnit: RentalUnit, periodStart: Carbon, periodEnd: Carbon, days: int}>  $leases
     * @return array{template: BillingTemplate, data: array<string, mixed>}
     */
    private function prepare(ManagementUnit $managementUnit, BillingInterval $billingInterval, $leases, BillingCostAllocationService $costAllocation): array
    {
        $managementGroup = $managementUnit->managementGroup;
        $managementGroup->loadMissing('managementUnits.rentalUnits.leases.leaseIntervals');

        // Computed once per statement, not once per Mietvertrag: see
        // BillingCostAllocationService::precomputeForStatement()'s doc comment.
        $precomputed = $costAllocation->precomputeForStatement($managementGroup, $managementUnit, $billingInterval);

        // Anzahl der Monate im Abrechnungsintervall (üblicherweise 12), Nenner für den
        // Vorschlag einer neuen monatlichen Betriebskostenvorauszahlung unten.
        $billingIntervalMonths = $billingInterval->start_date->diffInMonths($billingInterval->end_date->copy()->addDay());

        $leases = $leases->map(function ($entry) use ($managementGroup, $managementUnit, $billingInterval, $costAllocation, $precomputed, $billingIntervalMonths) {
            $allocatedCosts = $costAllocation->allocateForLease(
                $managementGroup, $managementUnit, $entry['rentalUnit'], $entry['lease'], $billingInterval,
                $entry['periodStart'], $entry['periodEnd'], $precomputed
            );

            return [
                ...$entry,
                'allocatedCosts' => $allocatedCosts,
                // Nachzahlung (positiv) bzw. Rückerstattung (negativ) gegenüber den
                // bereits geleisteten Betriebskostenvorauszahlungen dieses Mietvertrags.
                'balance' => $allocatedCosts['total'] - $entry['totalUtilityAdvance'],
                // Vorschlag für eine neue monatliche Betriebskostenvorauszahlung, auf
                // Basis der tatsächlichen Abrechnung dieses Mietvertrags (siehe
                // BillingCostAllocationService::suggestedMonthlyUtilityAdvance()).
                // "Mietdauer" (Konzept.md Regel b) = Monate seit Vertragsbeginn bis zum
                // Ende dieses Abrechnungsintervalls, unabhängig vom Intervallbeginn.
                'suggestedUtilityAdvance' => $costAllocation->suggestedMonthlyUtilityAdvance(
                    $allocatedCosts['total'], (float) $entry['rentalUnit']->size, $billingIntervalMonths,
                    $entry['currentUtilityAdvance'], max(0, (int) $entry['lease']->start_date->diffInMonths($billingInterval->end_date))
                ),
            ];
        });

        $template = $managementGroup->defaultBillingTemplate
            ?? BillingTemplate::where('is_default', true)->first();

        abort_if($template === null, 404, 'Kein Betriebskostenabrechnung-Template hinterlegt.');

        $allCosts = $managementUnit->costs()->with('costCategory')
            ->where('billing_interval_id', $billingInterval->id)
            ->get()
            ->merge($managementUnit->rentalUnits->flatMap(
                fn ($ru) => $ru->costs()->with('costCategory')->where('billing_interval_id', $billingInterval->id)->get()
            ))
            ->filter(fn ($cost) => $cost->costCategory->is_relevant_for_billing)
            ->values();

        $costsByCategory = $allCosts->groupBy(fn ($cost) => $cost->costCategory->name)
            ->map(fn ($costs) => $costs->sum('amount'));

        $costsByMonth = $allCosts->groupBy(fn ($cost) => $cost->period_date->format('Y-m'))
            ->sortKeys()
            ->map(fn ($costs) => [
                'label' => $costs->first()->period_date->locale('de')->translatedFormat('F Y'),
                'amount' => $costs->sum('amount'),
            ]);

        $bookingsByLevel = $this->bookingsByAssignmentLevel($managementGroup, $managementUnit, $billingInterval, $leases);

        $data = [
            'managementGroup' => $managementGroup,
            'managementUnit' => $managementUnit,
            'billingInterval' => $billingInterval,
            'landlord' => $managementGroup->effectiveLandlord(),
            'leases' => $leases,
            'costsByCategory' => $costsByCategory,
            'costsByMonth' => $costsByMonth,
            // Embedded as a data URI (not a plain storage URL): DomPDF/mPDF don't fetch
            // "remote" URLs by default (SSRF protection) – see BillingTemplate::
            // headerImageDataUri() for why this also avoids a self-request deadlock.
            'headerImage' => $template->headerImageDataUri(),
            'managementUnitTotalArea' => $precomputed['areaUnit'],
            'managementGroupTotalArea' => $precomputed['areaGroup'],
            // Anzahl der abrechnungsrelevanten Mieteinheiten – Nenner des Abrechnungsschlüssels
            // "Mieteinheit" und im Template verfügbar (Konzept "Abrechnungsschlüssel Mieteinheit").
            'managementUnitRelevantUnits' => $precomputed['relevantUnitsUnit'],
            'managementGroupRelevantUnits' => $precomputed['relevantUnitsGroup'],
            'managementUnitPersonMonths' => $precomputed['personMonthsUnit'],
            'managementGroupPersonMonths' => $precomputed['personMonthsGroup'],
            'billingIntervalDays' => $billingInterval->start_date->diffInDays($billingInterval->end_date) + 1,
            'billingIntervalMonths' => $billingIntervalMonths,
            'bookingsByLevel' => $bookingsByLevel,
        ];

        return ['template' => $template, 'data' => $data];
    }

    /**
     * Konzept.md "#### Template": "alle relevanten Buchungen mit (Buchungsdatum, Betrag,
     * Empfänger und Beschreibung) gruppiert nach Zuordnungsebene und danach nach
     * Kategorie. Wenn eine Zuordnungsebene keine Einträge hat wird sie weggelassen.
     * Ebenso, wenn eine Kategorie keine Einträge hat." Gathers every already gebuchte
     * (booking_date gesetzt), abrechnungsrelevante Kostenzeile across all four Stufen
     * (Verwaltungsgruppe, Verwaltungseinheit, jede Mieteinheit, jeder in diesem
     * Abrechnungsintervall relevante Mietvertrag), then groups them level -> Kategorie
     * -> Buchungen, in this fixed Stufen-Reihenfolge; a level/Kategorie without any
     * Buchung is simply absent from the result rather than an empty group.
     *
     * @param  Collection<int, array{lease: Lease}>  $leases
     * @return Collection<string, Collection<string, Collection<int, array{date: Carbon, issuer: string, description: string, amount: float}>>>
     */
    private function bookingsByAssignmentLevel(ManagementGroup $managementGroup, ManagementUnit $managementUnit, BillingInterval $billingInterval, $leases): Collection
    {
        $withInvoiceIssuer = fn ($query) => $query
            ->with('costCategory', 'invoice.invoiceIssuer')
            ->where('billing_interval_id', $billingInterval->id)
            ->whereNotNull('booking_date');

        $costs = collect()
            ->merge($withInvoiceIssuer($managementGroup->costs())->get())
            ->merge($withInvoiceIssuer($managementUnit->costs())->get())
            ->merge($managementUnit->rentalUnits->flatMap(fn ($ru) => $withInvoiceIssuer($ru->costs())->get()))
            ->merge($leases->flatMap(fn ($entry) => $withInvoiceIssuer($entry['lease']->costs())->get()))
            ->filter(fn (Cost $cost) => $cost->costCategory->is_relevant_for_billing);

        $rows = $costs
            ->sortBy('booking_date')
            ->map(fn (Cost $cost) => [
                'level' => $cost->assignmentLevelLabel(),
                'category' => $cost->costCategory->name,
                'date' => $cost->booking_date,
                'issuer' => $cost->invoice?->invoiceIssuer?->fullName() ?? '–',
                'description' => $cost->invoice?->description ?: ($cost->note ?? ''),
                'amount' => (float) $cost->amount,
            ]);

        return collect(['Verwaltungsgruppe', 'Verwaltungseinheit', 'Mieteinheit', 'Mietvertrag'])
            ->mapWithKeys(fn ($level) => [$level => $rows->where('level', $level)])
            ->filter(fn ($levelRows) => $levelRows->isNotEmpty())
            ->map(fn ($levelRows) => $levelRows->groupBy('category'));
    }
}
