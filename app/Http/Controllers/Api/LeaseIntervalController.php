<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\LeaseInterval;
use App\Rules\DateAfterField;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * REST-API für Mietintervalle, gespiegelt von LeaseIntervalController: ein neues
 * Intervall beendet das bisher letzte am Vortag; gelöscht werden kann nur das
 * letzte Intervall.
 */
class LeaseIntervalController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $lease = Lease::findOrFail($request->input('lease_id'));
        $this->authorize('update', $lease);

        $validated = $request->validate($this->rules());

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

        return response()->json($lease->leaseIntervals()->create($validated), 201);
    }

    public function update(Request $request, LeaseInterval $leaseInterval): JsonResponse
    {
        $this->authorize('update', $leaseInterval->lease);

        $validated = $request->validate([
            ...$this->rules(),
            'end_date' => ['nullable', 'date', new DateAfterField($request->input('start_date'), 'Das Enddatum muss nach dem Startdatum liegen.')],
        ]);

        $leaseInterval->update($validated);
        $leaseInterval->pruneOpenRentPaymentsBeyondEndDate();

        return response()->json($leaseInterval);
    }

    public function destroy(LeaseInterval $leaseInterval): JsonResponse
    {
        $lease = $leaseInterval->lease;
        $this->authorize('update', $lease);

        $intervals = $lease->leaseIntervals()->orderBy('start_date')->get();

        if ($intervals->count() < 2 || $intervals->last()->isNot($leaseInterval)) {
            throw ValidationException::withMessages([
                'lease_interval' => 'Nur das letzte Mietintervall kann gelöscht werden.',
            ]);
        }

        $intervals[$intervals->count() - 2]->update(['end_date' => null]);

        $leaseInterval->delete();

        return response()->json(status: 204);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function rules(): array
    {
        return [
            'start_date' => ['required', 'date'],
            'rent_amount' => ['required', 'numeric', 'min:0'],
            'occupant_count' => ['required', 'integer', 'min:0'],
            'utility_advance_payment' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
