<?php

namespace App\Services\InvoiceSeries;

use App\Models\Cost;
use App\Models\CostCategory;
use App\Models\Invoice;
use App\Models\ManagementGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Passt die offenen Termine (Prognose) einer wiederkehrenden Rechnung an ihre
 * Buchungen an – dieselbe Logik für Seeder, Web und REST-API.
 *
 * Nur "schwankende" Rechnungen werden angepasst: Betragsüberprüfung abgeschaltet oder
 * mindestens zwei verschiedene gebuchte Beträge einer Kategorie (z. B. Zins und
 * Tilgung eines Kredits). Dann gilt der Prognosemodus der Verwaltungsgruppe:
 *
 * - "last_booking" (Standard): der zuletzt gebuchte Betrag,
 * - "regression": die am besten passende Ausgleichsfunktion der gebuchten Beträge
 *   (CurveFit), ohne ausreichende Buchungen der zuletzt gebuchte Betrag,
 * - "none": keine Prognose – offene Termine werden entfernt.
 *
 * Angepasst werden nur offene Termine nach der letzten Buchung – ein verpasster
 * früherer Monat ist keine Prognose und behält seinen Betrag. Fehlende Termine bis zum
 * Ende bzw. Prognosehorizont werden mit der Prognose ergänzt (z. B. nach dem Wechsel
 * von "none" zurück). Gebuchte Zeilen bleiben unverändert.
 */
class ForecastRefresher
{
    public function __construct(private readonly InvoiceForecastWriter $writer) {}

    /**
     * Alle Kategorien einer Rechnung.
     */
    public function refreshInvoice(Invoice $invoice): void
    {
        foreach ($invoice->costs()->distinct()->pluck('cost_category_id') as $categoryId) {
            $this->refresh($invoice, CostCategory::findOrFail($categoryId));
        }
    }

    /**
     * Alle wiederkehrenden Rechnungen einer Verwaltungsgruppe (samt Verwaltungs- und
     * Mieteinheiten und Mietverträgen) – nach einem Wechsel ihres Prognosemodus.
     */
    public function refreshManagementGroup(ManagementGroup $managementGroup): void
    {
        $managementGroup->loadMissing('managementUnits.rentalUnits.leases');
        $units = $managementGroup->managementUnits;
        $rentalUnits = $units->flatMap->rentalUnits;

        foreach (collect([$managementGroup])->merge($units)->merge($rentalUnits)->merge($rentalUnits->flatMap->leases) as $costable) {
            Invoice::where('costable_type', $costable->getMorphClass())
                ->where('costable_id', $costable->getKey())
                ->whereIn('periodicity', OccurrenceSchedule::RECURRING)
                ->get()
                ->each(fn (Invoice $invoice) => $this->refreshInvoice($invoice));
        }
    }

    /**
     * @param  int|null  $excludeCostId  eine gerade bearbeitete Zeile, die unverändert bleibt
     */
    public function refresh(Invoice $invoice, CostCategory $category, ?int $excludeCostId = null): void
    {
        $costable = $invoice->costable;
        $schedule = OccurrenceSchedule::for($invoice);

        if ($costable === null || ! $schedule->isRecurring()) {
            return;
        }

        $costs = $this->costs($invoice, $costable, $category);
        $booked = $costs->whereNotNull('booking_date')->values();

        if (! $this->isVariable($invoice, $booked)) {
            return;
        }

        $open = $costs->whereNull('booking_date')->reject(fn (Cost $cost) => $cost->id === $excludeCostId);
        $mode = CostableManagementGroup::of($costable)->variable_cost_forecast_mode;

        if ($mode === ManagementGroup::VARIABLE_FORECAST_NONE) {
            $open->each->delete();

            return;
        }

        if ($booked->isEmpty()) {
            return;
        }

        $anchor = InvoiceBookingRecorder::anchorOf($invoice);
        $forecast = $this->forecaster($booked, $schedule, $anchor, $mode);
        $latest = $booked->last()->period_date;
        $this->updateOpen($open->filter(fn (Cost $cost) => $cost->period_date->gt($latest)), $schedule, $anchor, $forecast);
        $this->writer->fillMissing($invoice, $costable, $category, $anchor, $invoice->recurrence_end?->toDateString(), $forecast);
    }

    /**
     * @param  Collection<int, Cost>  $booked
     */
    private function isVariable(Invoice $invoice, Collection $booked): bool
    {
        return $invoice->disable_amount_cap
            || $booked->map(fn (Cost $cost) => round((float) $cost->amount, 2))->unique()->count() >= 2;
    }

    /**
     * @param  Collection<int, Cost>  $booked  nach Periode sortiert
     * @return callable(int $n): float
     */
    private function forecaster(Collection $booked, OccurrenceSchedule $schedule, string $anchor, string $mode): callable
    {
        if ($mode === ManagementGroup::VARIABLE_FORECAST_REGRESSION && $booked->count() >= 2) {
            $predict = CurveFit::bestFit($booked->map(fn (Cost $cost) => [
                $schedule->nearestIndex($anchor, $cost->period_date->toDateString()), (float) $cost->amount,
            ])->all());

            if ($predict !== null) {
                return fn (int $n): float => max(0.0, round($predict($n), 2));
            }
        }

        $last = (float) $booked->last()->amount;

        return fn (): float => $last;
    }

    /**
     * @param  Collection<int, Cost>  $open
     * @param  callable(int $n): float  $forecast
     */
    private function updateOpen(Collection $open, OccurrenceSchedule $schedule, string $anchor, callable $forecast): void
    {
        foreach ($open as $cost) {
            $amount = $forecast($schedule->nearestIndex($anchor, $cost->period_date->toDateString()));

            if (round((float) $cost->amount, 2) !== round($amount, 2)) {
                $cost->update(['amount' => $amount]);
            }
        }
    }

    /**
     * @return Collection<int, Cost>
     */
    private function costs(Invoice $invoice, Model $costable, CostCategory $category): Collection
    {
        return Cost::where('invoice_id', $invoice->id)
            ->where('costable_type', $costable->getMorphClass())
            ->where('costable_id', $costable->getKey())
            ->where('cost_category_id', $category->id)
            ->orderBy('period_date')
            ->get();
    }
}
