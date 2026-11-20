<?php

namespace App\Services\InvoiceSeries;

use App\Models\BillingInterval;
use App\Models\Cost;
use App\Models\CostCategory;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Legt die Kostenzeilen (Prognose) einer Rechnung an: je Termin und Kostenkategorie
 * eine Zeile. Gemeinsame Logik für Seeder und REST-API.
 *
 * Abrechnungsintervall (Konzept.md "### Rechnung"): Eine befristete Rechnung (mit
 * Ende) bleibt komplett im Intervall, das bei ihrem Beginn galt; eine unbefristete
 * ordnet jeden Termin dem Intervall seines eigenen Datums zu und legt neue Intervalle
 * bei Bedarf an. Ein angeheftetes Intervall ($pin) gilt für alle Termine.
 *
 * Bereits vorhandene Zeilen derselben Periode werden nicht doppelt angelegt, so kann
 * dieselbe Rechnung erneut geschrieben werden (z. B. ein zweiter Import-Lauf).
 */
final class InvoiceForecastWriter
{
    public function __construct(private readonly BillingIntervalLocator $intervals) {}

    /**
     * @param  list<CostAllocation>  $allocations  Anteile, gleich für jeden Termin
     * @param  bool  $remember  Beginn und Ende an der Rechnung festhalten (beim bloßen
     *                          Vervollständigen einer Serie nicht)
     *
     * @throws InvalidSeriesException wenn das Ende vor dem Beginn liegt
     */
    public function writeAllocations(
        Invoice $invoice,
        Model $costable,
        string $anchor,
        ?string $end,
        array $allocations,
        ?BillingInterval $pin = null,
        bool $remember = true,
    ): void {
        $schedule = OccurrenceSchedule::for($invoice);
        $this->assertRange($schedule, $anchor, $end);

        if ($remember) {
            $this->rememberRecurrence($invoice, $schedule, $anchor, $end);
        }

        $managementGroup = CostableManagementGroup::of($costable);

        // Eine einmalige Rechnung hat genau einen Termin; dessen Zeile übernimmt das
        // Buchungsdatum der Rechnung. Wiederkehrende Termine starten offen und werden
        // einzeln gebucht, sobald sie tatsächlich stattfinden.
        $bookingDate = $invoice->periodicity === Invoice::PERIODICITY_ONCE ? $invoice->booking_date?->toDateString() : null;
        $forecastInterval = $end !== null ? ($pin ?? $this->intervals->covering($managementGroup, $anchor)) : null;

        foreach ($schedule->dates($anchor, $end) as $date) {
            $interval = $forecastInterval ?? $pin ?? $this->intervals->covering($managementGroup, $date);
            $this->createRows($invoice, $costable, $date, $allocations, $interval, $bookingDate, null);
        }
    }

    /**
     * Wie writeAllocations(), aber mit Betragsintervallen: eine durchgehende Folge von
     * Terminen ab dem ersten Intervall, deren Betrag/Aufteilung/Notiz aus dem
     * jeweils abdeckenden Intervall kommt.
     *
     * @param  non-empty-list<CostSegment>  $segments  lückenlos und chronologisch
     */
    public function writeSegments(Invoice $invoice, Model $costable, array $segments, ?BillingInterval $pin = null): void
    {
        $schedule = OccurrenceSchedule::for($invoice);
        $anchor = $segments[0]->start;
        $end = $segments[array_key_last($segments)]->end;
        $this->assertRange($schedule, $anchor, $end);
        $this->rememberRecurrence($invoice, $schedule, $anchor, $end);
        $this->rememberSegments($invoice, $segments);
        $managementGroup = CostableManagementGroup::of($costable);
        $forecastInterval = $end !== null ? ($pin ?? $this->intervals->covering($managementGroup, $anchor)) : null;

        foreach ($schedule->dates($anchor, $end) as $date) {
            $segment = $this->segmentCovering($segments, $date);
            $interval = $forecastInterval ?? $pin ?? $this->intervals->covering($managementGroup, $date);
            $this->createRows($invoice, $costable, $date, $segment->allocations, $interval, null, $segment->note);
        }
    }

    /**
     * Händische Rechnung: je eingetragenem Termin eine Zeile mit dessen Betrag.
     */
    public function writeManual(Invoice $invoice, Model $costable, CostCategory $category): void
    {
        $managementGroup = CostableManagementGroup::of($costable);

        foreach ($invoice->occurrences as $occurrence) {
            $date = $occurrence->occurrence_date->toDateString();
            $this->createRows(
                $invoice, $costable, $date, [new CostAllocation((float) $occurrence->amount, $category)],
                $this->intervals->covering($managementGroup, $date), null, null,
            );
        }
    }

    /**
     * Fehlende Termine einer Kategorie bis zum Ende bzw. Prognosehorizont anlegen; den
     * Betrag je Termin (Index ab dem Anker) liefert $amountFor – z. B. die Prognose.
     *
     * @param  callable(int $n): float  $amountFor
     */
    public function fillMissing(Invoice $invoice, Model $costable, CostCategory $category, string $anchor, ?string $end, callable $amountFor): void
    {
        $managementGroup = CostableManagementGroup::of($costable);
        $forecastInterval = $end !== null ? $this->intervals->covering($managementGroup, $anchor) : null;

        foreach (OccurrenceSchedule::for($invoice)->dates($anchor, $end) as $n => $date) {
            if (! $this->costExistsForPeriod($invoice, $costable, $category, $date)) {
                $interval = $forecastInterval ?? $this->intervals->covering($managementGroup, $date);
                $this->createRows($invoice, $costable, $date, [new CostAllocation($amountFor($n), $category)], $interval, null, null);
            }
        }
    }

    private function assertRange(OccurrenceSchedule $schedule, string $anchor, ?string $end): void
    {
        if ($schedule->isRecurring() && $end !== null && $end < $anchor) {
            throw new InvalidSeriesException("Das Ende der Wiederholung ({$end}) liegt vor ihrem Beginn ({$anchor}).");
        }
    }

    /**
     * Beginn und Ende der Wiederholung an der Rechnung festhalten – Buchungen werden
     * später relativ zu diesem Anker ihren Terminen zugeordnet.
     */
    private function rememberRecurrence(Invoice $invoice, OccurrenceSchedule $schedule, string $anchor, ?string $end): void
    {
        if (! $schedule->isRecurring()) {
            return;
        }

        $invoice->update(['recurrence_start' => $anchor, 'recurrence_end' => $end]);
    }

    /**
     * Betragsintervalle an der Rechnung festhalten (Sollbetrag je Termin, siehe
     * Invoice::expectedAmountOn()). Beim Verlängern ersetzen die neuen Intervalle die
     * bisherigen ab ihrem Beginn; frühere bleiben, enden aber spätestens davor.
     *
     * @param  non-empty-list<CostSegment>  $segments
     */
    private function rememberSegments(Invoice $invoice, array $segments): void
    {
        $newStart = $segments[0]->start;
        $dayBefore = Carbon::parse($newStart)->subDay()->toDateString();
        $kept = [];

        foreach ($invoice->amount_segments ?? [] as $old) {
            if ($old['start_date'] < $newStart) {
                $end = $old['end_date'] === null || $old['end_date'] >= $newStart ? $dayBefore : $old['end_date'];
                $kept[] = [...$old, 'end_date' => $end];
            }
        }

        $new = array_map(fn (CostSegment $segment) => [
            'start_date' => $segment->start, 'end_date' => $segment->end, 'amount' => $segment->amount,
        ], $segments);

        $invoice->update(['amount_segments' => [...$kept, ...$new]]);
    }

    /**
     * @param  list<CostSegment>  $segments
     */
    private function segmentCovering(array $segments, string $date): CostSegment
    {
        foreach ($segments as $segment) {
            if ($segment->covers($date)) {
                return $segment;
            }
        }

        throw new \InvalidArgumentException("Der Termin {$date} liegt außerhalb aller Betragsintervalle.");
    }

    /**
     * @param  list<CostAllocation>  $allocations
     */
    private function createRows(
        Invoice $invoice,
        Model $costable,
        string $date,
        array $allocations,
        BillingInterval $interval,
        ?string $bookingDate,
        ?string $note,
    ): void {
        foreach ($allocations as $allocation) {
            if ($this->costExistsForPeriod($invoice, $costable, $allocation->category, $date)) {
                continue;
            }

            Cost::create([
                'invoice_id' => $invoice->id,
                'costable_type' => $costable->getMorphClass(),
                'costable_id' => $costable->getKey(),
                'billing_interval_id' => $interval->id,
                'cost_category_id' => $allocation->category->id,
                'amount' => $allocation->amount,
                'period_date' => $date,
                'booking_date' => $bookingDate,
                'note' => $note,
            ]);
        }
    }

    /**
     * Gibt es für diese Periode schon eine Zeile? Bei monatlichen, quartalsweisen und
     * jährlichen Rechnungen (höchstens ein Termin je Monat) wird nach Monat verglichen,
     * damit eine auf ihr echtes Buchungsdatum verschobene Zeile erkannt wird; bei
     * wöchentlichen exakt nach Datum.
     */
    private function costExistsForPeriod(Invoice $invoice, Model $costable, CostCategory $category, string $date): bool
    {
        $query = Cost::where('invoice_id', $invoice->id)
            ->where('costable_type', $costable->getMorphClass())
            ->where('costable_id', $costable->getKey())
            ->where('cost_category_id', $category->id);

        if (in_array($invoice->periodicity, [Invoice::PERIODICITY_WEEKLY, Invoice::PERIODICITY_BIWEEKLY], true)) {
            return $query->whereDate('period_date', $date)->exists();
        }

        $month = Carbon::parse($date);

        return $query->whereYear('period_date', $month->year)->whereMonth('period_date', $month->month)->exists();
    }
}
