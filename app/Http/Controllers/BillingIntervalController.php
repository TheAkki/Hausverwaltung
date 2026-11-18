<?php

namespace App\Http\Controllers;

use App\Models\ManagementGroup;
use App\Rules\DateAfterField;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Creates Abrechnungsintervalle (billing intervals) for a ManagementGroup, enforcing
 * gap-free, at-most-one-year intervals and authorizing against the group's visibility.
 */
class BillingIntervalController extends Controller
{
    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request): View
    {
        $managementGroup = ManagementGroup::findOrFail($request->query('management_group_id'));
        $this->authorize('view', $managementGroup);

        $earliest = $managementGroup->billingIntervals()->orderBy('start_date')->first();
        $latest = $managementGroup->billingIntervals()->orderBy('end_date', 'desc')->first();

        return view('billing-intervals.create', compact('managementGroup', 'earliest', 'latest'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $managementGroup = ManagementGroup::findOrFail($request->input('management_group_id'));
        $this->authorize('view', $managementGroup);

        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', new DateAfterField($request->input('start_date'), 'Das Enddatum muss nach dem Startdatum liegen.')],
            'description' => ['nullable', 'string'],
        ]);

        $start = Carbon::parse($validated['start_date']);
        $end = isset($validated['end_date'])
            ? Carbon::parse($validated['end_date'])
            : Carbon::createFromDate($start->year, 12, 31);

        if ($start->diffInDays($end) > 366) {
            throw ValidationException::withMessages([
                'end_date' => 'Ein Abrechnungsintervall darf maximal ein Jahr dauern.',
            ]);
        }

        if ($managementGroup->billing_start !== null && $start->lt($managementGroup->billing_start)) {
            throw ValidationException::withMessages([
                'start_date' => 'Das Abrechnungsintervall darf nicht vor dem Beginn des Abrechnungsintervalls der Verwaltungsgruppe liegen.',
            ]);
        }

        $earliest = $managementGroup->billingIntervals()->orderBy('start_date')->first();
        $latest = $managementGroup->billingIntervals()->orderBy('end_date', 'desc')->first();

        // A new interval must attach gap-free to the existing timeline – either appending
        // right after the latest interval's end, or (to allow backfilling older years,
        // e.g. a 2026-only group that later needs 2025 added) prepending right before the
        // earliest interval's start. Anything else (a gap, an overlap, or an interval
        // stuck in the middle) is rejected.
        if ($latest !== null) {
            $attachesAfterLatest = $start->copy()->subDay()->isSameDay($latest->end_date);
            $attachesBeforeEarliest = $end->copy()->addDay()->isSameDay($earliest->start_date);

            if (! $attachesAfterLatest && ! $attachesBeforeEarliest) {
                throw ValidationException::withMessages([
                    'start_date' => 'Das Abrechnungsintervall muss lückenlos vor dem ersten oder nach dem letzten bestehenden Intervall anschließen.',
                ]);
            }
        }

        $managementGroup->billingIntervals()->create([
            'start_date' => $start,
            'end_date' => $end,
            'description' => $validated['description'] ?? null,
        ]);

        return redirect()->route('management-groups.show', $managementGroup);
    }
}
