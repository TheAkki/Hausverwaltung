<?php

namespace App\Services\InvoiceSeries;

use App\Models\Cost;
use App\Models\CostCategory;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Kostenzeilen einer Rechnung mit genau einer Kategorie – so legt das Webformular (und
 * die REST-API ohne Aufteilung) Rechnungen an. Dieselben Bausteine wie Seeder und
 * Aufteilung: InvoiceForecastWriter für die Termine, ForecastRefresher für die Prognose.
 */
final class InvoiceSeriesService
{
    public function __construct(
        private readonly InvoiceForecastWriter $writer,
        private readonly ForecastRefresher $refresher,
    ) {}

    /**
     * Termine der neuen Rechnung anlegen: händisch je eingetragenem Termin, sonst ab
     * Beginn (bzw. Buchungs-/Rechnungsdatum) mit dem wirksamen Betrag.
     *
     * @return Collection<int, Cost> die Kostenzeilen der Rechnung, nach Periode sortiert
     *
     * @throws InvalidSeriesException wenn das Ende vor dem Beginn liegt
     */
    public function write(Invoice $invoice, Model $costable, CostCategory $category): Collection
    {
        if ($invoice->periodicity === Invoice::PERIODICITY_MANUAL) {
            $this->writer->writeManual($invoice, $costable, $category);
        } else {
            $this->writer->writeAllocations(
                $invoice, $costable, InvoiceBookingRecorder::anchorOf($invoice), $invoice->recurrence_end?->toDateString(),
                [new CostAllocation($invoice->effectiveAmount(), $category)],
            );
            $this->refresher->refreshInvoice($invoice);
        }

        return $invoice->costs()->orderBy('period_date')->get();
    }

    /**
     * "Serie vervollständigen": fehlende Termine einer Kategorie bis $until (sonst bis
     * zum Ende bzw. Prognosehorizont) ergänzen, ohne Beginn und Ende der Rechnung zu
     * ändern, und die Prognose anpassen.
     */
    public function complete(Invoice $invoice, CostCategory $category, ?string $until = null): void
    {
        $this->writer->writeAllocations(
            $invoice, $invoice->costable, InvoiceBookingRecorder::anchorOf($invoice),
            $until ?? $invoice->recurrence_end?->toDateString(),
            [new CostAllocation($invoice->effectiveAmount(), $category)], remember: false,
        );
        $this->refresher->refresh($invoice, $category);
    }
}
