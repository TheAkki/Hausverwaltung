<?php

namespace App\Http\Controllers;

use App\Models\RentPayment;
use App\Rules\NotBookableOnCoupledLease;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Lets a user mark a monthly rent payment as booked (or clear it back to open) by
 * setting its Buchungsdatum, and optionally assign it to a Konto (when the Kontosystem
 * is enabled for the lease's management group).
 */
class RentPaymentController extends Controller
{
    public function update(Request $request, RentPayment $rentPayment): RedirectResponse
    {
        $lease = $rentPayment->leaseInterval->lease;
        $this->authorize('update', $lease);

        $kontosystemEnabled = $lease->rentalUnit->managementUnit->managementGroup->kontosystemEnabled();

        $validated = $request->validate([
            'booking_date' => ['nullable', 'date', new NotBookableOnCoupledLease($lease)],
            // The Buchungsdatum field always carries a value (the real booking_date, or a
            // placeholder so the calendar opens to the right month), so it can't signal
            // booking intent on its own. The "Buchen" button marks intent explicitly via
            // this field, which is only present in the submitted data when that specific
            // button triggered the submit.
            'account_id' => [
                'nullable', 'exists:accounts,id',
                Rule::requiredIf($kontosystemEnabled && $request->filled('confirm_booking')),
            ],
        ], [
            'account_id.required' => 'Bei aktivem Kontensystem muss ein Konto ausgewählt werden, um eine Mietzahlung zu buchen.',
        ]);

        $rentPayment->update(collect($validated)->except('account_id')->all());

        if (! empty($validated['account_id'])) {
            $rentPayment->accounts()->sync([$validated['account_id'] => ['amount' => $rentPayment->amount]]);
        } else {
            $rentPayment->accounts()->sync([]);
        }

        return redirect()->route('leases.show', $lease)->withFragment('mietzahlungen');
    }
}
