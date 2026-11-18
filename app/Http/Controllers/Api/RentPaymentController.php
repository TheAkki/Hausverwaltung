<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaseInterval;
use App\Models\RentPayment;
use App\Rules\NotBookableOnCoupledLease;
use App\Services\RentPaymentGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * REST-API für Mietzahlungen. Die Liste eines Mietintervalls erzeugt – wie die
 * Mietvertragsseite im Web – zuerst fehlende Monatszeilen (RentPaymentGenerator);
 * gebucht wird per update(), gespiegelt von RentPaymentController.
 */
class RentPaymentController extends Controller
{
    public function index(LeaseInterval $leaseInterval, RentPaymentGenerator $generator): JsonResponse
    {
        $this->authorize('view', $leaseInterval->lease);

        $generator->generate($leaseInterval);

        return response()->json($leaseInterval->rentPayments()->with('accounts')->orderBy('period_date')->get());
    }

    public function update(Request $request, RentPayment $rentPayment): JsonResponse
    {
        $lease = $rentPayment->leaseInterval->lease;
        $this->authorize('update', $lease);

        $kontosystemEnabled = $lease->rentalUnit->managementUnit->managementGroup->kontosystemEnabled();

        $validated = $request->validate([
            'booking_date' => ['nullable', 'date', new NotBookableOnCoupledLease($lease)],
            'account_id' => [
                'nullable', 'exists:accounts,id',
                Rule::requiredIf($kontosystemEnabled && $request->boolean('confirm_booking')),
            ],
        ], [
            'account_id.required' => 'Bei aktivem Kontensystem muss ein Konto ausgewählt werden, um eine Mietzahlung zu buchen.',
        ]);

        $rentPayment->update(collect($validated)->except('account_id')->all());

        $rentPayment->accounts()->sync(
            empty($validated['account_id']) ? [] : [$validated['account_id'] => ['amount' => $rentPayment->amount]]
        );

        return response()->json($rentPayment->load('accounts'));
    }
}
