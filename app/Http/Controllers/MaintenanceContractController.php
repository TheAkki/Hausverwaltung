<?php

namespace App\Http\Controllers;

use App\Models\ManagementGroup;
use App\Models\MaintenanceContract;
use App\Rules\DateAfterField;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Manages maintenance contracts belonging to a management group.
 */
class MaintenanceContractController extends Controller
{
    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request): View
    {
        $managementGroup = ManagementGroup::visibleTo($request->user())
            ->findOrFail($request->query('management_group_id'));

        return view('maintenance-contracts.create', compact('managementGroup'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'management_group_id' => ['required', 'exists:management_groups,id'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:255'],
            'provider' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', new DateAfterField($request->input('start_date'), 'Das Enddatum muss nach dem Startdatum liegen.')],
        ]);

        $managementGroup = ManagementGroup::visibleTo($request->user())->findOrFail($validated['management_group_id']);

        $managementGroup->maintenanceContracts()->create($validated);

        return redirect()->route('management-groups.show', $managementGroup);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(MaintenanceContract $maintenanceContract): View
    {
        $this->authorize('view', $maintenanceContract->managementGroup);

        return view('maintenance-contracts.edit', compact('maintenanceContract'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, MaintenanceContract $maintenanceContract): RedirectResponse
    {
        $this->authorize('update', $maintenanceContract->managementGroup);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:255'],
            'provider' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', new DateAfterField($request->input('start_date'), 'Das Enddatum muss nach dem Startdatum liegen.')],
        ]);

        $maintenanceContract->update($validated);

        return redirect()->route('management-groups.show', $maintenanceContract->managementGroup);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(MaintenanceContract $maintenanceContract): RedirectResponse
    {
        $this->authorize('update', $maintenanceContract->managementGroup);

        $managementGroup = $maintenanceContract->managementGroup;

        $maintenanceContract->delete();

        return redirect()->route('management-groups.show', $managementGroup);
    }
}
