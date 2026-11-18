<?php

namespace App\Http\Controllers;

use App\Models\Lease;
use App\Models\LeaseInterval;
use App\Rules\DateAfterField;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Carbon\Carbon;

/**
 * Manages lease intervals, closing the previous interval automatically when a new one starts.
 */
class LeaseIntervalController extends Controller
{
    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request): View
    {
        $lease = Lease::findOrFail($request->query('lease_id'));
        $this->authorize('view', $lease);

        $latest = $lease->currentLeaseInterval();

        return view('lease-intervals.create', compact('lease', 'latest'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $lease = Lease::findOrFail($request->input('lease_id'));
        $this->authorize('view', $lease);

        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'rent_amount' => ['required', 'numeric', 'min:0'],
            'occupant_count' => ['required', 'integer', 'min:0'],
            'utility_advance_payment' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $latest = $lease->currentLeaseInterval();

        if ($latest !== null) {
            $newStart = Carbon::parse($validated['start_date']);

            if ($newStart->lte($latest->start_date)) {
                throw ValidationException::withMessages([
                    'start_date' => 'Das Startdatum muss nach dem Beginn des letzten Mietintervalls liegen.',
                ]);
            }

            $latest->update(['end_date' => $newStart->copy()->subDay()]);
            $latest->pruneOpenRentPaymentsBeyondEndDate();
        }

        $leaseInterval = $lease->leaseIntervals()->create($validated);

        return redirect()->route('leases.show', $lease)->withFragment("interval-{$leaseInterval->id}");
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(LeaseInterval $leaseInterval): View
    {
        $this->authorize('view', $leaseInterval->lease);

        return view('lease-intervals.edit', compact('leaseInterval'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, LeaseInterval $leaseInterval): RedirectResponse
    {
        $this->authorize('update', $leaseInterval->lease);

        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', new DateAfterField($request->input('start_date'), 'Das Enddatum muss nach dem Startdatum liegen.')],
            'rent_amount' => ['required', 'numeric', 'min:0'],
            'occupant_count' => ['required', 'integer', 'min:0'],
            'utility_advance_payment' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $leaseInterval->update($validated);
        $leaseInterval->pruneOpenRentPaymentsBeyondEndDate();

        return redirect()->route('leases.show', $leaseInterval->lease);
    }

    /**
     * Delete the last (most recent) lease interval of a lease, reopening the
     * previous interval by clearing its end_date. The lease's only/first interval
     * cannot be deleted this way.
     */
    public function destroy(LeaseInterval $leaseInterval): RedirectResponse
    {
        $lease = $leaseInterval->lease;
        $this->authorize('update', $lease);

        $intervals = $lease->leaseIntervals()->orderBy('start_date')->get();

        if ($intervals->count() < 2 || $intervals->last()->isNot($leaseInterval)) {
            throw ValidationException::withMessages([
                'lease_interval' => 'Nur das letzte Mietintervall kann gelöscht werden.',
            ]);
        }

        $previous = $intervals[$intervals->count() - 2];
        $previous->update(['end_date' => null]);

        $leaseInterval->delete();

        return redirect()->route('leases.show', $lease);
    }
}
