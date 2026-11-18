<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\RentalUnit;
use App\Models\SettlementClaim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * REST-API für Forderungen (Nachzahlung/Guthaben aus einer Abrechnung),
 * gespiegelt von SettlementClaimController: eine Forderung gehört entweder zu
 * einem Mietvertrag oder – bei Leerstand – zu einer Mieteinheit.
 */
class SettlementClaimController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        [$lease, $rentalUnit, $managementGroup] = $this->resolveOwner($request->input('lease_id'), $request->input('rental_unit_id'));

        $claim = SettlementClaim::create([
            ...$request->validate($this->rules($managementGroup)),
            'lease_id' => $lease?->id,
            'rental_unit_id' => $rentalUnit?->id,
        ]);

        return response()->json($claim, 201);
    }

    public function update(Request $request, SettlementClaim $settlementClaim): JsonResponse
    {
        $this->authorizeClaim($settlementClaim);

        $settlementClaim->update($request->validate($this->rules($settlementClaim->managementGroup())));

        return response()->json($settlementClaim);
    }

    public function destroy(SettlementClaim $settlementClaim): JsonResponse
    {
        $this->authorizeClaim($settlementClaim);

        $settlementClaim->delete();

        return response()->json(status: 204);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(ManagementGroup $managementGroup): array
    {
        return [
            'billing_interval_id' => ['required', Rule::exists('billing_intervals', 'id')->where('management_group_id', $managementGroup->id)],
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric'],
            'booking_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array{0: ?Lease, 1: ?RentalUnit, 2: ManagementGroup}
     */
    private function resolveOwner(mixed $leaseId, mixed $rentalUnitId): array
    {
        if (($leaseId !== null) === ($rentalUnitId !== null)) {
            throw ValidationException::withMessages([
                'lease_id' => 'Eine Forderung braucht entweder einen Mietvertrag oder eine (leerstehende) Mieteinheit – nicht beides, nicht keines.',
            ]);
        }

        if ($leaseId !== null) {
            $lease = Lease::findOrFail($leaseId);
            $this->authorize('update', $lease);

            return [$lease, null, $lease->rentalUnit->managementUnit->managementGroup];
        }

        $rentalUnit = RentalUnit::findOrFail($rentalUnitId);
        $this->authorize('update', $rentalUnit);

        return [null, $rentalUnit, $rentalUnit->managementUnit->managementGroup];
    }

    private function authorizeClaim(SettlementClaim $settlementClaim): void
    {
        $this->authorize('update', $settlementClaim->lease_id !== null ? $settlementClaim->lease : $settlementClaim->rentalUnit);
    }
}
