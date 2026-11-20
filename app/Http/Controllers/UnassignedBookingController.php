<?php

namespace App\Http\Controllers;

use App\Services\UnassignedBookingFinder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Nicht zugeordnete Buchungen": Termine sichtbarer Rechnungen bis heute, deren
 * Kostenzeilen nicht den Sollbetrag ergeben (siehe UnassignedBookingFinder).
 */
class UnassignedBookingController extends Controller
{
    public function index(Request $request, UnassignedBookingFinder $finder): View
    {
        return view('unassigned-bookings.index', ['rows' => $finder->find($request->user())]);
    }
}
