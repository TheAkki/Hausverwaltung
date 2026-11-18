<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountTransfer;
use App\Models\ManagementGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * REST-API für Konten (Kontensystem) einer Verwaltungsgruppe und Umbuchungen
 * zwischen ihnen, gespiegelt von AccountController/AccountTransferController.
 */
class AccountController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'management_group_id' => ['required', 'exists:management_groups,id'],
            ...$this->rules(),
        ]);

        $managementGroup = ManagementGroup::visibleTo($request->user())->findOrFail($validated['management_group_id']);
        $this->authorize('update', $managementGroup);
        abort_unless($managementGroup->kontosystemEnabled(), 404);

        $this->resetDefaultIfRequested($request, $managementGroup);

        $account = $managementGroup->accounts()->create($this->attributes($request, $validated));

        return response()->json($account, 201);
    }

    public function update(Request $request, Account $account): JsonResponse
    {
        $this->authorize('update', $account->managementGroup);

        $validated = $request->validate($this->rules());

        $this->resetDefaultIfRequested($request, $account->managementGroup);

        $account->update($this->attributes($request, $validated));

        return response()->json($account);
    }

    public function destroy(Account $account): JsonResponse
    {
        $this->authorize('update', $account->managementGroup);

        $account->delete();

        return response()->json(status: 204);
    }

    public function transfer(Request $request): JsonResponse
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

        $accountIds = $managementGroup->accounts()->pluck('id');

        if (! $accountIds->contains($validated['from_account_id']) || ! $accountIds->contains($validated['to_account_id'])) {
            throw ValidationException::withMessages([
                'to_account_id' => 'Beide Konten müssen zur selben Verwaltungsgruppe gehören.',
            ]);
        }

        $transfer = AccountTransfer::create(collect($validated)->except('management_group_id')->all());

        return response()->json($transfer, 201);
    }

    private function resetDefaultIfRequested(Request $request, ManagementGroup $managementGroup): void
    {
        if ($request->boolean('is_default')) {
            $managementGroup->accounts()->update(['is_default' => false]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(Request $request, array $validated): array
    {
        return [
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'initial_balance' => $validated['initial_balance'] ?? 0,
            'is_default' => $request->boolean('is_default'),
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'initial_balance' => ['nullable', 'numeric'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }
}
