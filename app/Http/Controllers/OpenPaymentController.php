<?php

namespace App\Http\Controllers;

use App\Services\OpenPaymentFinder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Offene Zahlungen" overview: lists every still unbooked Cost (Ausgabe) and
 * RentPayment (Einnahme) whose period has already started (see OpenPaymentFinder).
 */
class OpenPaymentController extends Controller
{
    public function index(Request $request, OpenPaymentFinder $finder): View
    {
        $openCosts = $finder->costs($request->user());
        $openRentPayments = $finder->rentPayments($request->user());

        return view('open-payments.index', compact('openCosts', 'openRentPayments'));
    }
}
