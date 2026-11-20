<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountTransfer;
use App\Models\ManagementGroup;
use App\Services\RecordReceipt;
use App\Support\FileStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Records booking-neutral transfers of money between two Konten of the same
 * ManagementGroup, optionally with a Beleg (RecordReceipt).
 */
class AccountTransferController extends Controller
{
    public function store(Request $request, RecordReceipt $receipt): RedirectResponse
    {
        $validated = $request->validate([
            'management_group_id' => ['required', 'exists:management_groups,id'],
            'from_account_id' => ['required', 'exists:accounts,id'],
            'to_account_id' => ['required', 'exists:accounts,id', 'different:from_account_id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'transfer_date' => ['required', 'date'],
            'comment' => ['nullable', 'string'],
            ...RecordReceipt::rules(),
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

        $transfer = AccountTransfer::create([
            'from_account_id' => $fromAccount->id,
            'to_account_id' => $toAccount->id,
            'amount' => $validated['amount'],
            'transfer_date' => $validated['transfer_date'],
            'comment' => $validated['comment'] ?? null,
        ]);
        $receipt->applyUpload($transfer, $request->file(RecordReceipt::FIELD_FILE), false);

        return $this->backTo($managementGroup);
    }

    /**
     * Beleg einer Umbuchung hochladen (ersetzt einen vorhandenen).
     */
    public function storeFile(Request $request, AccountTransfer $accountTransfer, RecordReceipt $receipt): RedirectResponse
    {
        $this->authorize('update', $accountTransfer);

        $file = $request->validate([RecordReceipt::FIELD_FILE => ['required', ...FileStorage::uploadRules()]])[RecordReceipt::FIELD_FILE];
        $receipt->attach($accountTransfer, $file, $file->getClientOriginalName());

        return $this->backTo($accountTransfer->fromAccount->managementGroup);
    }

    public function destroyFile(AccountTransfer $accountTransfer, RecordReceipt $receipt): RedirectResponse
    {
        $this->authorize('update', $accountTransfer);

        $receipt->remove($accountTransfer);

        return $this->backTo($accountTransfer->fromAccount->managementGroup);
    }

    private function backTo(ManagementGroup $managementGroup): RedirectResponse
    {
        return redirect()->route('management-groups.show', $managementGroup);
    }
}
