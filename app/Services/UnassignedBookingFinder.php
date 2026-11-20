<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceSeries\OccurrenceSchedule;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * "Detailbild nicht zugeordnete Buchungen" (Konzept.md): jeder Termin einer sichtbaren
 * Rechnung bis heute, bei dem die Summe der Kostenzeilen nicht dem Sollbetrag
 * entspricht – außer bei Rechnungen mit deaktiviertem Betragsdeckel (disable_amount_cap).
 *
 * Sollbetrag je Termin: einmalig der wirksame Rechnungsbetrag (Summe aller
 * Kostenzeilen), händisch der Betrag des Termins, wiederkehrend der Betrag des
 * abdeckenden Betragsintervalls bzw. der wirksame Rechnungsbetrag.
 */
final class UnassignedBookingFinder
{
    /** Auf Cent genau vergleichen. */
    private const PRECISION = 2;

    /** Schlüssel für Kostenzeilen ohne Leistungsdatum (zählen nur bei einmaligen Rechnungen). */
    private const WITHOUT_DATE = '';

    /**
     * @return Collection<int, array{invoice: Invoice, date: string, expected: float, assigned: float, difference: float}>
     */
    public function find(User $user, ?Carbon $today = null): Collection
    {
        $today = ($today ?? now())->toDateString();

        return Invoice::visibleTo($user)
            ->where('disable_amount_cap', false)
            ->whereDate('invoice_date', '<=', $today)
            ->with('invoiceIssuer', 'costable', 'costs', 'occurrences')
            ->get()
            ->flatMap(fn (Invoice $invoice) => $this->mismatches($invoice, $today))
            ->sortBy(fn (array $row) => sprintf('%s|%010d', $row['date'], $row['invoice']->id))
            ->values();
    }

    /**
     * @return list<array{invoice: Invoice, date: string, expected: float, assigned: float, difference: float}>
     */
    private function mismatches(Invoice $invoice, string $today): array
    {
        $rows = [];
        $assignedByDate = $this->assignedByDate($invoice);

        foreach ($this->expectedByDate($invoice, $today) as $date => $expected) {
            $assigned = $invoice->periodicity === Invoice::PERIODICITY_ONCE
                ? round(array_sum($assignedByDate), self::PRECISION)
                : round($assignedByDate[(string) $date] ?? 0.0, self::PRECISION);
            $difference = round($expected - $assigned, self::PRECISION);

            if ($difference !== 0.0) {
                $rows[] = ['invoice' => $invoice, 'date' => (string) $date, 'expected' => $expected, 'assigned' => $assigned, 'difference' => $difference];
            }
        }

        return $rows;
    }

    /**
     * Sollbetrag je Termin bis heute.
     *
     * @return array<string, float>
     */
    private function expectedByDate(Invoice $invoice, string $today): array
    {
        if ($invoice->periodicity === Invoice::PERIODICITY_MANUAL) {
            return $invoice->occurrences
                ->filter(fn ($occurrence) => $occurrence->occurrence_date->toDateString() <= $today)
                ->mapWithKeys(fn ($occurrence) => [$occurrence->occurrence_date->toDateString() => round((float) $occurrence->amount, self::PRECISION)])
                ->all();
        }

        $schedule = OccurrenceSchedule::for($invoice);

        if (! $schedule->isRecurring() || $invoice->recurrence_start === null) {
            return [$invoice->invoice_date->toDateString() => round($invoice->effectiveAmount(), self::PRECISION)];
        }

        $end = $invoice->recurrence_end !== null && $invoice->recurrence_end->toDateString() < $today
            ? $invoice->recurrence_end->toDateString()
            : $today;
        $start = $invoice->recurrence_start->toDateString();

        if ($start > $end) {
            return [];
        }

        $expected = [];
        foreach ($schedule->dates($start, $end) as $date) {
            $expected[$date] = round($invoice->expectedAmountOn($date), self::PRECISION);
        }

        return $expected;
    }

    /**
     * Summe der Kostenzeilen je Termin, in einem Durchlauf – eine einmalige Rechnung
     * zählt alle zusammen (siehe mismatches()).
     *
     * @return array<string, float> Datum (Y-m-d) => Summe
     */
    private function assignedByDate(Invoice $invoice): array
    {
        $sums = [];

        foreach ($invoice->costs as $cost) {
            $date = $cost->period_date?->toDateString() ?? self::WITHOUT_DATE;
            $sums[$date] = ($sums[$date] ?? 0.0) + (float) $cost->amount;
        }

        return $sums;
    }
}
