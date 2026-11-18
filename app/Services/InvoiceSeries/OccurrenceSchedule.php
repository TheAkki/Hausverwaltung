<?php

namespace App\Services\InvoiceSeries;

use App\Models\Invoice;
use Carbon\Carbon;

/**
 * Terminplan einer Rechnung: welche Termine (Vorkommnisse) eine Wiederholung hat und
 * welcher Termin zu einem tatsächlichen Buchungsdatum gehört. Rein rechnerisch, ohne
 * Datenbankzugriff.
 *
 * Jeder Termin wird direkt aus dem Anker berechnet (z. B. addMonthsNoOverflow($n)),
 * nicht durch Weiterzählen von Termin zu Termin: Weiterzählen läuft über kurze Monate
 * hinaus (31.1. -> 3.3.) und verschiebt den Tag dauerhaft. So wird nur der betroffene
 * Monat gekürzt (31.1. -> 28.2.) und der Tag kehrt danach zurück (-> 31.3.).
 */
final class OccurrenceSchedule
{
    /**
     * Eine unbefristete Rechnung (kein Ende) wird bis zum Ende des Folgejahres
     * prognostiziert, damit die Ausgabenübersicht auch das nächste Jahr schon zeigt.
     */
    public const OPEN_ENDED_HORIZON_YEARS = 1;

    /**
     * Periodizitäten mit einer berechneten Terminfolge.
     */
    public const RECURRING = [
        Invoice::PERIODICITY_WEEKLY, Invoice::PERIODICITY_BIWEEKLY, Invoice::PERIODICITY_MONTHLY,
        Invoice::PERIODICITY_QUARTERLY, Invoice::PERIODICITY_YEARLY,
    ];

    public function __construct(private readonly string $periodicity) {}

    public static function for(Invoice $invoice): self
    {
        return new self((string) $invoice->periodicity);
    }

    public function isRecurring(): bool
    {
        return $this->step() !== null;
    }

    /**
     * Alle Termine ab $first bis $end (einschließlich); ohne $end bis zum
     * Prognosehorizont. Einmalige/händische Rechnungen haben nur den einen Termin.
     *
     * @return list<string>
     */
    public function dates(string $first, ?string $end): array
    {
        $step = $this->step();

        if ($step === null) {
            return [$first];
        }

        $start = Carbon::parse($first);
        $limit = $end !== null ? Carbon::parse($end) : now()->addYears(self::OPEN_ENDED_HORIZON_YEARS)->endOfYear();
        $dates = [];

        for ($n = 0; ($date = $step($start, $n))->lte($limit); $n++) {
            $dates[] = $date->toDateString();
        }

        return $dates;
    }

    /**
     * Datum des $n-ten Termins (0 = Anker; negative Werte liegen vor dem Anker).
     */
    public function periodDate(string $anchor, int $n): string
    {
        $step = $this->step();

        return $step === null ? $anchor : $step(Carbon::parse($anchor), $n)->toDateString();
    }

    /**
     * Index des Termins, der kalendarisch am nächsten an $actual liegt. Eine ein paar
     * Tage zu früh oder zu spät ausgeführte Abbuchung landet so bei ihrem Termin, und
     * eine fehlende Buchung verschiebt die folgenden nicht. Liegt $actual vor dem
     * Anker, wird rückwärts gezählt (nachgetragene ältere Zahlungen).
     */
    public function nearestIndex(string $anchor, string $actual): int
    {
        $step = $this->step();

        if ($step === null) {
            return 0;
        }

        $start = Carbon::parse($anchor);
        $date = Carbon::parse($actual);
        $n = $this->indexAtOrBefore($step, $start, $date);
        $before = $step($start, $n);
        $after = $step($start, $n + 1);

        // diffInDays() ist vorzeichenbehaftet (Carbon 3) – ohne abs() verlöre der
        // nähere Termin immer gegen den mit positiver Differenz.
        return abs($before->diffInDays($date)) <= abs($after->diffInDays($date)) ? $n : $n + 1;
    }

    /**
     * Größter Index, dessen Termin nicht nach $date liegt.
     *
     * @param  callable(Carbon, int): Carbon  $step
     */
    private function indexAtOrBefore(callable $step, Carbon $start, Carbon $date): int
    {
        $n = 0;

        if ($date->lt($step($start, $n))) {
            while ($step($start, $n)->gt($date)) {
                $n--;
            }

            return $n;
        }

        while ($step($start, $n + 1)->lte($date)) {
            $n++;
        }

        return $n;
    }

    /**
     * @return (callable(Carbon $start, int $n): Carbon)|null null für einmalige und
     *                                                        händische Rechnungen
     */
    private function step(): ?callable
    {
        return match ($this->periodicity) {
            Invoice::PERIODICITY_WEEKLY => fn (Carbon $start, int $n) => $start->copy()->addWeeks($n),
            Invoice::PERIODICITY_BIWEEKLY => fn (Carbon $start, int $n) => $start->copy()->addWeeks(2 * $n),
            Invoice::PERIODICITY_MONTHLY => fn (Carbon $start, int $n) => $start->copy()->addMonthsNoOverflow($n),
            Invoice::PERIODICITY_QUARTERLY => fn (Carbon $start, int $n) => $start->copy()->addMonthsNoOverflow(3 * $n),
            Invoice::PERIODICITY_YEARLY => fn (Carbon $start, int $n) => $start->copy()->addYearsNoOverflow($n),
            default => null,
        };
    }
}
