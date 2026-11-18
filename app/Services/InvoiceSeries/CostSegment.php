<?php

namespace App\Services\InvoiceSeries;

/**
 * Ein Betragsintervall einer wiederkehrenden Rechnung (Konzept.md "### Rechnungen":
 * "Ähnlich wie bei einen Mietvertrag können auch Intervalle definiert werden", z. B.
 * eine Lohnerhöhung im selben Arbeitsvertrag). Ab $start gelten $amount und die
 * Aufteilung $allocations; $end = null heißt offen. $note landet an jeder Kostenzeile.
 */
final class CostSegment
{
    /**
     * @param  list<CostAllocation>  $allocations
     */
    public function __construct(
        public readonly string $start,
        public readonly ?string $end,
        public readonly float $amount,
        public readonly ?string $note,
        public readonly array $allocations,
    ) {}

    public function covers(string $date): bool
    {
        return $date >= $this->start && ($this->end === null || $date <= $this->end);
    }
}
