<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\ManagementGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Records booking-neutral transfers of money between two Konten of the same
 * ManagementGroup.
 */
class AccountTransferController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'management_group_id' => ['required', 'exists:management_groups,id'],
            'from_account_id' => ['required', 'exists:accounts,id'],
            'to_account_id' => ['required', 'exists:accounts,id', 'different:from_account_id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'transfer_date' => ['required', 'date'],
            'comment' => ['nullable', 'string'],
        ]);

        $managementGroup = ManagementGroup::visibleTo($request->user())->findOrFail($validated['management_group_id']);
        $this->authorize('update', $managementGroup);

        $fromAccount = Account::findOrFail($validated['from_account_id']);
        $toAccount = Account::findOrFail($validated['to_account_id']);

        if ($fromAccount->management_group_id !== $managementGroup->id || $toAccount->management_group_id !== $managementGroup->id) {
            throw ValidationException::withMessages([
                'to_account_id' => 'Beide Konten müssen zur selben Verwaltungsgruppe gehören.',
            ]);
        }

        \App\Models\AccountTransfer::create([
            'from_account_id' => $fromAccount->id,
            'to_account_id' => $toAccount->id,
            'amount' => $validated['amount'],
            'transfer_date' => $validated['transfer_date'],
            'comment' => $validated['comment'] ?? null,
        ]);

        return redirect()->route('management-groups.show', $managementGroup);
    }
}
