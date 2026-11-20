<?php

namespace App\Http\Controllers;

use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\RentalUnit;
use App\Models\SettlementClaim;
use App\Services\RecordReceipt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Manages Forderungen (SettlementClaim): a Betriebskostennachzahlung (positiver
 * Betrag) oder -rückzahlung (negativer Betrag), gebunden an ein Abrechnungsintervall
 * der zugehörigen Verwaltungsgruppe. Entweder für einen Mietvertrag (üblicher Fall)
 * oder für eine zeitweise leerstehende Mieteinheit ohne Mietvertrag (Konzept.md
 * "#### Berechnung": Forderungen z. B. einer Abrechnungsgesellschaft für den
 * Leerstand) – "create"/"store" unterscheiden anhand von "lease_id" vs.
 * "rental_unit_id", welcher der beiden Fälle gemeint ist.
 */
class SettlementClaimController extends Controller
{
    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request): View
    {
        [$lease, $rentalUnit, $managementGroup] = $this->resolveOwner($request->query('lease_id'), $request->query('rental_unit_id'));

        $billingIntervals = $managementGroup->billingIntervals()->orderByDesc('start_date')->get();

        return view('settlement-claims.create', compact('lease', 'rentalUnit', 'billingIntervals'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, RecordReceipt $receipt): RedirectResponse
    {
        [$lease, $rentalUnit, $managementGroup] = $this->resolveOwner($request->input('lease_id'), $request->input('rental_unit_id'));

        $validated = $request->validate([
            'billing_interval_id' => ['required', Rule::exists('billing_intervals', 'id')->where('management_group_id', $managementGroup->id)],
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric'],
            'booking_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            ...RecordReceipt::rules(),
        ]);

        $claim = SettlementClaim::create([
            ...collect($validated)->except(array_keys(RecordReceipt::rules()))->all(),
            'lease_id' => $lease?->id,
            'rental_unit_id' => $rentalUnit?->id,
        ]);
        $receipt->applyUpload($claim, $request->file(RecordReceipt::FIELD_FILE), false);

        return $this->redirectTo($claim);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(SettlementClaim $settlementClaim): View
    {
        $this->authorizeClaim($settlementClaim);

        $managementGroup = $settlementClaim->managementGroup();
        $billingIntervals = $managementGroup->billingIntervals()->orderByDesc('start_date')->get();
        $lease = $settlementClaim->lease;
        $rentalUnit = $settlementClaim->rentalUnit;

        return view('settlement-claims.edit', compact('settlementClaim', 'lease', 'rentalUnit', 'billingIntervals'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, SettlementClaim $settlementClaim, RecordReceipt $receipt): RedirectResponse
    {
        $this->authorizeClaim($settlementClaim, forUpdate: true);

        $managementGroup = $settlementClaim->managementGroup();

        $validated = $request->validate([
            'billing_interval_id' => ['required', Rule::exists('billing_intervals', 'id')->where('management_group_id', $managementGroup->id)],
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric'],
            'booking_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            ...RecordReceipt::rules(),
        ]);

        $settlementClaim->update(collect($validated)->except(array_keys(RecordReceipt::rules()))->all());
        $receipt->applyUpload($settlementClaim, $request->file(RecordReceipt::FIELD_FILE), $request->boolean(RecordReceipt::FIELD_REMOVE));

        return $this->redirectTo($settlementClaim);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(SettlementClaim $settlementClaim, RecordReceipt $receipt): RedirectResponse
    {
        $this->authorizeClaim($settlementClaim, forUpdate: true);

        $receipt->forget($settlementClaim);
        $settlementClaim->delete();

        return $this->redirectTo($settlementClaim);
    }

    /**
     * Resolves "lease_id" xor "rental_unit_id" (query oder Request-Body, je nach
     * Aufrufer) zum jeweiligen Owner-Modell plus dessen ManagementGroup – genau einer
     * der beiden IDs muss angegeben sein.
     *
     * @return array{0: ?Lease, 1: ?RentalUnit, 2: ManagementGroup}
     */
    private function resolveOwner(?string $leaseId, ?string $rentalUnitId): array
    {
        if (($leaseId !== null) === ($rentalUnitId !== null)) {
            throw ValidationException::withMessages([
                'lease_id' => 'Eine Forderung braucht entweder einen Mietvertrag oder eine (leerstehende) Mieteinheit – nicht beides, nicht keines.',
            ]);
        }

        if ($leaseId !== null) {
            $lease = Lease::findOrFail($leaseId);
            $this->authorize('view', $lease);

            return [$lease, null, $lease->rentalUnit->managementUnit->managementGroup];
        }

        $rentalUnit = RentalUnit::findOrFail($rentalUnitId);
        $this->authorize('view', $rentalUnit);

        return [null, $rentalUnit, $rentalUnit->managementUnit->managementGroup];
    }

    private function authorizeClaim(SettlementClaim $settlementClaim, bool $forUpdate = false): void
    {
        $ability = $forUpdate ? 'update' : 'view';

        if ($settlementClaim->lease_id !== null) {
            $this->authorize($ability, $settlementClaim->lease);
        } else {
            $this->authorize($ability, $settlementClaim->rentalUnit);
        }
    }

    private function redirectTo(SettlementClaim $settlementClaim): RedirectResponse
    {
        return $settlementClaim->isForVacancy()
            ? redirect()->route('management-units.show', $settlementClaim->rentalUnit->managementUnit)->withFragment('leerstand')
            : redirect()->route('leases.show', $settlementClaim->lease)->withFragment('forderungen');
    }
}
