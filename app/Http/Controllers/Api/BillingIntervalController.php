<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingInterval;
use App\Models\Cost;
use App\Models\ManagementGroup;
use App\Models\SettlementClaim;
use App\Rules\DateAfterField;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * REST-API für Abrechnungsintervalle, gespiegelt von BillingIntervalController
 * (lückenlos, höchstens ein Jahr). Zusätzlich lässt sich das erste oder letzte,
 * noch unbenutzte Intervall löschen – z. B. das beim Anlegen einer
 * Verwaltungsgruppe automatisch erzeugte, wenn es nicht gebraucht wird.
 */
class BillingIntervalController extends Controller
{
    private const MAX_DAYS = 366;

    public function index(ManagementGroup $managementGroup): JsonResponse
    {
        $this->authorize('view', $managementGroup);

        return response()->json($managementGroup->billingIntervals()->orderBy('start_date')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $managementGroup = ManagementGroup::findOrFail($request->input('management_group_id'));
        $this->authorize('update', $managementGroup);

        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', new DateAfterField($request->input('start_date'), 'Das Enddatum muss nach dem Startdatum liegen.')],
            'description' => ['nullable', 'string'],
        ]);

        $start = Carbon::parse($validated['start_date']);
        $end = isset($validated['end_date'])
            ? Carbon::parse($validated['end_date'])
            : Carbon::createFromDate($start->year, 12, 31);

        $this->assertValidRange($managementGroup, $start, $end);
        $this->assertAttachesGapFree($managementGroup, $start, $end);

        $interval = $managementGroup->billingIntervals()->create([
            'start_date' => $start,
            'end_date' => $end,
            'description' => $validated['description'] ?? null,
        ]);

        return response()->json($interval, 201);
    }

    public function destroy(BillingInterval $billingInterval): JsonResponse
    {
        $managementGroup = $billingInterval->managementGroup;
        $this->authorize('update', $managementGroup);

        $this->assertDeletable($managementGroup, $billingInterval);

        $billingInterval->delete();

        return response()->json(status: 204);
    }

    private function assertValidRange(ManagementGroup $managementGroup, Carbon $start, Carbon $end): void
    {
        if ($start->diffInDays($end) > self::MAX_DAYS) {
            throw ValidationException::withMessages([
                'end_date' => 'Ein Abrechnungsintervall darf maximal ein Jahr dauern.',
            ]);
        }

        if ($managementGroup->billing_start !== null && $start->lt($managementGroup->billing_start)) {
            throw ValidationException::withMessages([
                'start_date' => 'Das Abrechnungsintervall darf nicht vor dem Beginn des Abrechnungsintervalls der Verwaltungsgruppe liegen.',
            ]);
        }
    }

    private function assertAttachesGapFree(ManagementGroup $managementGroup, Carbon $start, Carbon $end): void
    {
        $earliest = $managementGroup->billingIntervals()->orderBy('start_date')->first();
        $latest = $managementGroup->billingIntervals()->orderBy('end_date', 'desc')->first();

        if ($latest === null) {
            return;
        }

        $attachesAfterLatest = $start->copy()->subDay()->isSameDay($latest->end_date);
        $attachesBeforeEarliest = $end->copy()->addDay()->isSameDay($earliest->start_date);

        if (! $attachesAfterLatest && ! $attachesBeforeEarliest) {
            throw ValidationException::withMessages([
                'start_date' => 'Das Abrechnungsintervall muss lückenlos vor dem ersten oder nach dem letzten bestehenden Intervall anschließen.',
            ]);
        }
    }

    private function assertDeletable(ManagementGroup $managementGroup, BillingInterval $billingInterval): void
    {
        $earliest = $managementGroup->billingIntervals()->orderBy('start_date')->first();
        $latest = $managementGroup->billingIntervals()->orderBy('end_date', 'desc')->first();

        if ($billingInterval->isNot($earliest) && $billingInterval->isNot($latest)) {
            throw ValidationException::withMessages([
                'billing_interval' => 'Nur das erste oder letzte Abrechnungsintervall kann gelöscht werden (sonst entstünde eine Lücke).',
            ]);
        }

        $inUse = Cost::where('billing_interval_id', $billingInterval->id)->exists()
            || SettlementClaim::where('billing_interval_id', $billingInterval->id)->exists();

        if ($inUse) {
            throw ValidationException::withMessages([
                'billing_interval' => 'Das Abrechnungsintervall wird noch von Kostenzeilen oder Forderungen verwendet.',
            ]);
        }
    }
}
