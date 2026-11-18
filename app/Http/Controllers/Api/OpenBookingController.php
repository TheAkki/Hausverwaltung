<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OpenPaymentFinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Anzeigen von offenen Buchungen" (Konzept.md "## API"): dieselben offenen Ausgaben
 * und Mietzahlungen wie die Web-Übersicht "Offene Zahlungen".
 */
class OpenBookingController extends Controller
{
    public function index(Request $request, OpenPaymentFinder $finder): JsonResponse
    {
        return response()->json([
            'costs' => $finder->costs($request->user()),
            'rent_payments' => $finder->rentPayments($request->user()),
        ]);
    }
}
