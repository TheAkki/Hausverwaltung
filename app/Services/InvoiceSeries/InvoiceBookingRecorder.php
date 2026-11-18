<?php

namespace App\Services\InvoiceSeries;

use App\Models\BillingInterval;
use App\Models\Cost;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Bucht tatsächlich erfolgte Abbuchungen einer Rechnung (Konzept.md "### Rechnung":
 * "Bei regelmäßigen Abbuchungen müssen die Buchungsdaten trotzdem eingetragen werden,
 * um eine Zahlung als geschehen zu markieren"). Gemeinsame Logik für Seeder und
 * REST-API.
 *
 * Jede Abbuchung wird dem kalendarisch nächstgelegenen Termin zugeordnet. Die offene
 * Prognosezeile dieses Termins wird zur Buchung (echter Betrag, echtes Datum), statt
 * daneben eine zweite Zeile anzulegen. Eine bereits gebuchte Zeile wird nie verändert;
 * dieselbe Abbuchung erneut zu buchen ändert nichts.
 */
final class InvoiceBookingRecorder
{
    public function __construct(private readonly BillingIntervalLocator $intervals) {}

    /**
     * Anker der Terminfolge: der festgehaltene Beginn der Wiederholung, sonst das
     * Buchungs- bzw. Rechnungsdatum.
     */
    public static function anchorOf(Invoice $invoice): string
    {
        return ($invoice->recurrence_start ?? $invoice->booking_date ?? $invoice->invoice_date)->toDateString();
    }

    /**
     * @param  list<CostAllocation>  $allocations  echte Beträge je Kategorie
     * @param  BillingInterval|null  $pin  für die ganze Buchung angeheftetes Intervall
     * @return Collection<int, Cost> die gebuchten Zeilen
     */
    public function record(
        Invoice $invoice,
        Model $costable,
        string $anchor,
        string $bookingDate,
        array $allocations,
        ?BillingInterval $pin = null,
    ): Collection {
        $schedule = OccurrenceSchedule::for($invoice);
        $periodDate = $schedule->periodDate($anchor, $schedule->nearestIndex($anchor, $bookingDate));
        $defaultInterval = $pin ?? $this->intervals->covering(CostableManagementGroup::of($costable), $periodDate);

        return collect($allocations)->map(
            fn (CostAllocation $allocation) => $this->upsert($invoice, $costable, $periodDate, $bookingDate, $allocation, $defaultInterval)
        );
    }

    private function upsert(
        Invoice $invoice,
        Model $costable,
        string $periodDate,
        string $bookingDate,
        CostAllocation $allocation,
        BillingInterval $defaultInterval,
    ): Cost {
        $interval = $allocation->billingInterval ?? $defaultInterval;
        $query = $this->costsOf($invoice, $costable)->where('cost_category_id', $allocation->category->id);

        // Nur ein ausdrücklich angeheftetes Intervall grenzt die Suche ein: sonst fände
        // ein zweiter Anteil derselben Kategorie (anderes Intervall) fälschlich den
        // ersten. Ohne Anheftung darf die vorhandene Prognosezeile auf einem anderen
        // Intervall stehen und wird unten korrigiert.
        if ($allocation->billingInterval !== null) {
            $query->where('billing_interval_id', $interval->id);
        }

        $alreadyBooked = (clone $query)->whereDate('period_date', $periodDate)->whereDate('booking_date', $bookingDate)->first();

        if ($alreadyBooked !== null) {
            return $alreadyBooked;
        }

        $cost = (clone $query)->whereNull('booking_date')->whereDate('period_date', $periodDate)->first()
            ?? new Cost([
                'invoice_id' => $invoice->id,
                'costable_type' => $costable->getMorphClass(),
                'costable_id' => $costable->getKey(),
                'cost_category_id' => $allocation->category->id,
            ]);

        $cost->billing_interval_id = $interval->id;
        $cost->amount = $allocation->amount;
        $cost->period_date = $periodDate;
        $cost->booking_date = $bookingDate;
        $cost->save();

        return $cost;
    }

    /**
     * @return Builder<Cost>
     */
    private function costsOf(Invoice $invoice, Model $costable): Builder
    {
        return Cost::where('invoice_id', $invoice->id)
            ->where('costable_type', $costable->getMorphClass())
            ->where('costable_id', $costable->getKey());
    }
}
