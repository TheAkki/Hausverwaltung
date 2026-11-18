<?php

namespace App\Services;

use App\Models\Cost;
use App\Models\ManagementGroup;
use App\Models\ManagementUnit;
use App\Models\RentalUnit;
use App\Models\RentPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * "Offene Zahlungen"/"offene Buchungen": every Cost (Ausgabe) and RentPayment
 * (Einnahme) that is still unbooked (booking_date null) and whose period has already
 * started, across all management groups visible to the given user. Shared by the web
 * overview (OpenPaymentController) and the REST API.
 */
class OpenPaymentFinder
{
    /**
     * @return Collection<int, Cost>
     */
    public function costs(User $user): Collection
    {
        [$managementGroupIds, $managementUnitIds, $rentalUnitIds] = $this->visibleIds($user);

        return Cost::query()
            ->whereNull('booking_date')
            ->whereDate('period_date', '<=', now())
            ->where(function ($query) use ($managementGroupIds, $managementUnitIds, $rentalUnitIds) {
                $query->where(fn ($q) => $q->where('costable_type', 'management_group')->whereIn('costable_id', $managementGroupIds))
                    ->orWhere(fn ($q) => $q->where('costable_type', 'management_unit')->whereIn('costable_id', $managementUnitIds))
                    ->orWhere(fn ($q) => $q->where('costable_type', 'rental_unit')->whereIn('costable_id', $rentalUnitIds));
            })
            ->with('invoice.invoiceIssuer', 'costCategory', 'costable')
            ->orderBy('period_date')
            ->get();
    }

    /**
     * @return Collection<int, RentPayment>
     */
    public function rentPayments(User $user): Collection
    {
        [, , $rentalUnitIds] = $this->visibleIds($user);

        return RentPayment::query()
            ->whereNull('booking_date')
            ->whereDate('period_date', '<=', now())
            ->whereHas(
                'leaseInterval.lease.rentalUnit',
                fn ($q) => $q->whereIn('id', $rentalUnitIds)
            )
            // Konzept.md "### Mietvertrag": a referenzierender (gekoppelter) Mietvertrag
            // (z. B. ein Stellplatz gekoppelt an die Wohnung desselben Mieters) is never
            // booked on its own – its Mietzahlungen stay "offen" forever by design
            // (NotBookableOnCoupledLease), so they must not clutter "offene Zahlungen".
            ->whereHas('leaseInterval.lease', fn ($q) => $q->whereNull('coupled_to_lease_id'))
            ->with('leaseInterval.lease.tenants', 'leaseInterval.lease.rentalUnit.managementUnit')
            ->orderBy('period_date')
            ->get();
    }

    /**
     * @return array{0: \Illuminate\Support\Collection<int, int>, 1: \Illuminate\Support\Collection<int, int>, 2: \Illuminate\Support\Collection<int, int>}
     */
    private function visibleIds(User $user): array
    {
        $managementGroupIds = ManagementGroup::visibleTo($user)->pluck('id');
        $managementUnitIds = ManagementUnit::whereIn('management_group_id', $managementGroupIds)->pluck('id');
        $rentalUnitIds = RentalUnit::whereIn('management_unit_id', $managementUnitIds)->pluck('id');

        return [$managementGroupIds, $managementUnitIds, $rentalUnitIds];
    }
}
