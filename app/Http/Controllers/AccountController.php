<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\ManagementGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Manages Konten (accounts) belonging to a ManagementGroup, only relevant when that
 * group's Benutzergruppe has the Kontensystem enabled.
 */
class AccountController extends Controller
{
    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request): View
    {
        $managementGroup = ManagementGroup::visibleTo($request->user())
            ->findOrFail($request->query('management_group_id'));

        abort_unless($managementGroup->kontosystemEnabled(), 404);

        return view('accounts.create', compact('managementGroup'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'management_group_id' => ['required', 'exists:management_groups,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'initial_balance' => ['nullable', 'numeric'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $managementGroup = ManagementGroup::visibleTo($request->user())->findOrFail($validated['management_group_id']);
        abort_unless($managementGroup->kontosystemEnabled(), 404);

        if ($request->boolean('is_default')) {
            $managementGroup->accounts()->update(['is_default' => false]);
        }

        $managementGroup->accounts()->create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'initial_balance' => $validated['initial_balance'] ?? 0,
            'is_default' => $request->boolean('is_default'),
        ]);

        return redirect()->route('management-groups.show', $managementGroup);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Account $account): View
    {
        $this->authorize('view', $account->managementGroup);

        return view('accounts.edit', compact('account'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Account $account): RedirectResponse
    {
        $this->authorize('update', $account->managementGroup);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'initial_balance' => ['nullable', 'numeric'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('is_default')) {
            $account->managementGroup->accounts()->update(['is_default' => false]);
        }

        $account->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'initial_balance' => $validated['initial_balance'] ?? 0,
            'is_default' => $request->boolean('is_default'),
        ]);

        return redirect()->route('management-groups.show', $account->managementGroup);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Account $account): RedirectResponse
    {
        $this->authorize('update', $account->managementGroup);

        $managementGroup = $account->managementGroup;

        $account->delete();

        return redirect()->route('management-groups.show', $managementGroup);
    }
}
