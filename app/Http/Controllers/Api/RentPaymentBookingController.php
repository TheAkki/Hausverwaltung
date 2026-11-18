<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Services\RentPaymentBooker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Eingegangene Mietzahlungen eines Mietvertrags anhand ihres Zahlungsdatums buchen
 * (z. B. vom Kontoauszug). Welcher Monat bezahlt wurde, entscheidet der Server
 * (Halbmonatsregel, RentPaymentBooker) – dieselbe Logik wie der Seeder. Ein Datum, zu
 * dem keine offene Mietzahlung passt, wird mit "rent_payment": null zurückgemeldet.
 */
class RentPaymentBookingController extends Controller
{
    public function store(Request $request, Lease $lease, RentPaymentBooker $booker): JsonResponse
    {
        $this->authorize('update', $lease);

        $validated = $request->validate([
            'booking_dates' => ['required', 'array', 'min:1'],
            'booking_dates.*' => ['required', 'date_format:Y-m-d'],
        ]);

        try {
            $booked = $booker->bookAll($lease->load('leaseIntervals'), $validated['booking_dates']);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['booking_dates' => $exception->getMessage()]);
        }

        return response()->json(collect($booked)->map(
            fn ($payment, $date) => ['booking_date' => $date, 'rent_payment' => $payment]
        )->values());
    }
}
