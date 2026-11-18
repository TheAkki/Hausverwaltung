<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\InvoiceSeries\CostableManagementGroup;
use App\Services\InvoiceSeries\ForecastRefresher;
use App\Services\InvoiceSeries\InvoiceBookingRecorder;
use App\Services\InvoiceSeries\SeriesInputResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Tatsächlich erfolgte Abbuchungen einer Rechnung buchen (z. B. vom Kontoauszug):
 * je Abbuchung Datum und Betrag (bzw. Beträge je Kategorie). Der Server ordnet jede
 * Abbuchung dem nächstgelegenen Termin zu und macht aus dessen offener Prognosezeile
 * eine Buchung – dieselbe Logik wie der Seeder (InvoiceBookingRecorder). Danach passt
 * der ForecastRefresher die offenen Termine an (Prognosemodus der Verwaltungsgruppe).
 * Dieselbe Abbuchung erneut zu senden ändert nichts.
 */
class InvoiceBookingController extends Controller
{
    /**
     * Mit "refresh_forecast: false" bleiben die offenen Termine unverändert (z. B. bei
     * Betragsintervallen, deren künftige Beträge exakt bekannt sind).
     */
    public const REFRESH_FORECAST = 'refresh_forecast';

    public function store(Request $request, Invoice $invoice, InvoiceBookingRecorder $recorder, ForecastRefresher $refresher): JsonResponse
    {
        $this->authorize('update', $invoice);
        $validated = $request->validate($this->rules());

        $resolver = new SeriesInputResolver(CostableManagementGroup::of($invoice->costable), $request->user(), checkSums: false);
        $pin = $resolver->interval($validated['billing_interval_id'] ?? null, 'billing_interval_id');
        $anchor = InvoiceBookingRecorder::anchorOf($invoice);
        $booked = collect();

        foreach ($validated['bookings'] as $i => $booking) {
            $allocations = $resolver->allocations($this->allocationRows($invoice, $booking, "bookings.{$i}"), 0.0, "bookings.{$i}.allocations");
            $booked = $booked->merge($recorder->record($invoice, $invoice->costable, $anchor, $booking['booking_date'], $allocations, $pin));
        }

        if ($request->boolean(self::REFRESH_FORECAST, true)) {
            $refresher->refreshInvoice($invoice);
        }

        return response()->json(['costs' => $booked->map->fresh()->values()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'bookings' => ['required', 'array', 'min:1'],
            'bookings.*.booking_date' => ['required', 'date_format:Y-m-d'],
            'bookings.*.amount' => ['required_without:bookings.*.allocations', 'nullable', 'numeric'],
            'bookings.*.allocations' => ['nullable', 'array', 'min:1'],
            'bookings.*.allocations.*.cost_category_id' => ['required', 'integer'],
            'bookings.*.allocations.*.amount' => ['required', 'numeric'],
            'bookings.*.allocations.*.billing_interval_id' => ['nullable', 'integer'],
            'billing_interval_id' => ['nullable', 'integer'],
            self::REFRESH_FORECAST => ['boolean'],
        ];
    }

    /**
     * Ein bloßer Betrag gilt für die einzige Kategorie der Rechnung.
     *
     * @param  array<string, mixed>  $booking
     * @return list<array<string, mixed>>
     */
    private function allocationRows(Invoice $invoice, array $booking, string $field): array
    {
        if (! empty($booking['allocations'])) {
            return $booking['allocations'];
        }

        $categoryIds = $invoice->costs()->distinct()->pluck('cost_category_id');

        if ($categoryIds->count() !== 1) {
            throw ValidationException::withMessages([
                "{$field}.allocations" => 'Die Rechnung hat nicht genau eine Kategorie – bitte die Beträge je Kategorie angeben (allocations).',
            ]);
        }

        return [['cost_category_id' => $categoryIds->first(), 'amount' => $booking['amount']]];
    }
}
