<?php

namespace App\Http\Controllers;

use App\Models\Landlord;
use App\Models\ManagementGroup;
use App\Rules\ValidIban;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Edits the Vermieter (landlord) for a Verwaltungsgruppe: either its own override or –
 * when no override is set – the account-level Vermieter of the owning Benutzergruppe
 * (see ManagementGroup::effectiveLandlord()). The Vermieter is used as the sender on
 * the Betriebskostenabrechnung.
 */
class LandlordController extends Controller
{
    /**
     * Show the form for editing the Vermieter of a Verwaltungsgruppe.
     */
    public function edit(ManagementGroup $managementGroup): View
    {
        $this->authorize('update', $managementGroup);

        $accountLandlord = Landlord::where('user_group_id', $managementGroup->user_group_id)
            ->whereNull('management_group_id')
            ->first();

        return view('landlords.edit', [
            'managementGroup' => $managementGroup,
            'accountLandlord' => $accountLandlord,
            'overrideLandlord' => $managementGroup->landlord,
        ]);
    }

    /**
     * Update (create/replace/remove) the Vermieter of a Verwaltungsgruppe. The "scope"
     * field decides whether the account-level Vermieter or this group's override is
     * edited; for the override, "remove_override" deletes it so the account-level one
     * applies again.
     */
    public function update(Request $request, ManagementGroup $managementGroup): RedirectResponse
    {
        $this->authorize('update', $managementGroup);

        $scope = $request->input('scope') === 'account' ? 'account' : 'override';

        if ($scope === 'override' && $request->boolean('remove_override')) {
            $managementGroup->landlord?->delete();

            return redirect()->route('management-groups.landlord.edit', $managementGroup);
        }

        $validated = $request->validate([
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

        $key = $scope === 'account'
            ? ['user_group_id' => $managementGroup->user_group_id, 'management_group_id' => null]
            : ['user_group_id' => null, 'management_group_id' => $managementGroup->id];

        Landlord::updateOrCreate($key, $validated);

        return redirect()->route('management-groups.landlord.edit', $managementGroup);
    }
}
