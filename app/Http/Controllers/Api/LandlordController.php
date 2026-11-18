<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Landlord;
use App\Models\ManagementGroup;
use App\Rules\ValidIban;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REST-API für den Vermieter einer Verwaltungsgruppe, gespiegelt von
 * LandlordController: "scope=account" pflegt den Vermieter der Benutzergruppe,
 * sonst den Override der Verwaltungsgruppe (mit "remove_override" entfernbar).
 */
class LandlordController extends Controller
{
    private const SCOPE_ACCOUNT = 'account';

    public function show(ManagementGroup $managementGroup): JsonResponse
    {
        $this->authorize('view', $managementGroup);

        return response()->json([
            'account' => $this->accountLandlord($managementGroup),
            'override' => $managementGroup->landlord,
        ]);
    }

    public function update(Request $request, ManagementGroup $managementGroup): JsonResponse
    {
        $this->authorize('update', $managementGroup);

        $isAccountScope = $request->input('scope') === self::SCOPE_ACCOUNT;

        if (! $isAccountScope && $request->boolean('remove_override')) {
            $managementGroup->landlord?->delete();

            return response()->json(status: 204);
        }

        $key = $isAccountScope
            ? ['user_group_id' => $managementGroup->user_group_id, 'management_group_id' => null]
            : ['user_group_id' => null, 'management_group_id' => $managementGroup->id];

        return response()->json(Landlord::updateOrCreate($key, $this->validated($request)));
    }

    /**
     * Vermieter der Benutzergruppe direkt an der Gruppe pflegen – auch für eine
     * Gruppe ohne Verwaltungsgruppe (z. B. die persönliche Gruppe eines Accounts).
     */
    public function updateForGroup(Request $request, Group $group): JsonResponse
    {
        $this->authorize('update', $group);

        $landlord = Landlord::updateOrCreate(
            ['user_group_id' => $group->id, 'management_group_id' => null],
            $this->validated($request)
        );

        return response()->json($landlord);
    }

    private function accountLandlord(ManagementGroup $managementGroup): ?Landlord
    {
        return Landlord::where('user_group_id', $managementGroup->user_group_id)
            ->whereNull('management_group_id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'salutation' => ['nullable', 'in:Herr,Frau'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'street' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'mobile_phone' => ['nullable', 'string', 'max:30'],
            'iban' => ['nullable', 'string', 'max:34', new ValidIban],
            'bic' => ['nullable', 'string', 'max:11'],
            'notes' => ['nullable', 'string'],
        ]);
    }
}
