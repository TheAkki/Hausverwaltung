<?php

namespace App\Services;

use App\Models\Lease;
use App\Models\LeaseInterval;
use App\Models\RentPayment;
use Illuminate\Support\Collection;

/**
 * Ensures a RentPayment row exists for every elapsed month of a LeaseInterval, so
 * that missing Buchungsdaten ("offene Buchungen") can be recognized.
 */
class RentPaymentGenerator
{
    /**
     * @return Collection<int, RentPayment>
     */
    public function generate(LeaseInterval $interval): Collection
    {
        $cursor = $interval->start_date->copy()->startOfMonth();
        $lastElapsedMonth = now()->startOfMonth();
        $end = $interval->end_date !== null
            ? $interval->end_date->copy()->startOfMonth()->min($lastElapsedMonth)
            : $lastElapsedMonth;

        $managementGroup = $interval->lease->rentalUnit->managementUnit->managementGroup;
        $defaultAccount = $managementGroup->kontosystemEnabled() ? $managementGroup->defaultAccount() : null;

        // Konzept.md "### Mietvertrag": a Mietvertrag coupled to this one via
        // "Mietvertrag: <ref>" (e.g. ein Stellplatz gekoppelt an diese Wohnung) is never
        // booked on its own (NotBookableOnCoupledLease/SeedsBuchungen::seedZahlungen()
        // both forbid it) – the tenant only transfers rent once. So its rent is instead
        // folded directly into THIS Mietvertrag's own monatliche RentPayment, month by
        // month picking whichever of the coupled Mietvertrag's LeaseIntervals was active
        // that month (none = 0, correctly handling e.g. a mid-Intervall Kündigung of the
        // Stellplatz without affecting the Wohnung's own Mietintervall).
        $coupledLeases = Lease::where('coupled_to_lease_id', $interval->lease_id)->with('leaseIntervals')->get();

        $payments = collect();

        while ($cursor->lte($end)) {
            $payment = RentPayment::firstOrNew(
                ['lease_interval_id' => $interval->id, 'period_date' => $cursor->copy()]
            );
            $isNew = ! $payment->exists;

            // Only still-open (unbooked) rows track later edits to the LeaseInterval's
            // amounts; an already booked payment is a confirmed historical record and
            // must not be silently rewritten. $contributions stays null for an already
            // booked payment, so its (likewise frozen) Aufschlüsselung is left untouched.
            $contributions = null;

            if ($payment->booking_date === null) {
                // Which Mietvertrag contributed how much of this Mietzahlung – always
                // this Mietvertrag itself, plus one entry per aktiv gekoppeltem
                // Mietvertrag that month (see contributingLeases()/rentPaymentContributions()).
                $contributions = [$interval->lease_id => (float) $interval->totalMonthlyAmount()];

                $coupledRent = 0.0;
                $coupledUtility = 0.0;

                foreach ($coupledLeases as $coupledLease) {
                    $coupledInterval = $coupledLease->leaseIntervals->first(
                        fn (LeaseInterval $li) => $li->start_date->lte($cursor) && ($li->end_date === null || $li->end_date->gte($cursor))
                    );

                    if ($coupledInterval === null) {
                        continue;
                    }

                    $coupledRent += (float) $coupledInterval->rent_amount;
                    $coupledUtility += (float) $coupledInterval->utility_advance_payment;
                    $contributions[$coupledLease->id] = (float) $coupledInterval->totalMonthlyAmount();
                }

                $payment->rent_amount = (float) $interval->rent_amount + $coupledRent;
                $payment->utility_amount = (float) $interval->utility_advance_payment + $coupledUtility;
                $payment->amount = $payment->rent_amount + $payment->utility_amount;
            }

            $payment->save();

            if ($contributions !== null) {
                $payment->contributingLeases()->sync(
                    collect($contributions)->map(fn ($amount) => ['amount' => $amount])->all()
                );
            }

            // A newly created payment starts out assigned to the Defaultkonto (if any);
            // this only happens once at creation so a user can still clear it afterwards
            // without it being silently reattached on the next lazy generation.
            if ($isNew && $defaultAccount !== null) {
                $payment->accounts()->attach($defaultAccount->id, ['amount' => $payment->amount]);
            }

            $payments->push($payment);

            $cursor->addMonth();
        }

        return $payments;
    }
}
