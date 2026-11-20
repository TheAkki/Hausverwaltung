<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Lease;
use App\Models\LeaseInterval;
use App\Models\RentPayment;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ensures a RentPayment row exists for every elapsed month of a LeaseInterval, so
 * that missing Buchungsdaten ("offene Buchungen") can be recognized.
 *
 * Die Übersichtsseiten rufen das bei jedem Aufruf für alle Mietintervalle einer
 * Verwaltungsgruppe auf. Deshalb liest generateMany() alles Nötige mit wenigen
 * Abfragen für alle Intervalle zusammen und schreibt nur, was sich wirklich geändert hat.
 */
class RentPaymentGenerator
{
    private const CENTS_PER_EURO = 100;

    private const PIVOT_TABLE = 'lease_rent_payment';

    /** Höchstzahl der IDs je whereIn – bleibt unter den Grenzen aller Datenbanken. */
    private const QUERY_CHUNK_SIZE = 500;

    /**
     * @return Collection<int, RentPayment>
     */
    public function generate(LeaseInterval $interval): Collection
    {
        return $this->generateMany(collect([$interval]))[$interval->id];
    }

    /**
     * Wie generate(), für viele Intervalle auf einmal. Setzt außerdem an jedem Intervall
     * die Beziehung rentPayments (alle Zeilen, auch neu angelegte), sodass Aufrufer
     * sie mit loadMissing() nicht noch einmal laden müssen.
     *
     * @param  Collection<int, LeaseInterval>  $intervals
     * @return array<int, Collection<int, RentPayment>> Intervall-ID => Zeilen der abgelaufenen Monate
     */
    public function generateMany(Collection $intervals): array
    {
        $intervals = new EloquentCollection($intervals->unique(fn (LeaseInterval $interval) => $interval->id)->values()->all());
        if ($intervals->isEmpty()) {
            return [];
        }

        $intervals->loadMissing('lease.rentalUnit.managementUnit.managementGroup.accounts');
        $coupledLeases = $this->coupledLeases($intervals);
        $existing = $this->existingPayments($intervals);
        $stored = $this->storedContributions($existing->flatten(1));

        $generated = [];
        foreach ($intervals as $interval) {
            $payments = $existing->get($interval->id) ?? new EloquentCollection;
            $generated[$interval->id] = $this->fill(
                $interval,
                $payments->keyBy(fn (RentPayment $payment) => $payment->period_date->toDateString()),
                $coupledLeases->get($interval->lease_id, collect()),
                $stored,
            );
            $interval->setRelation('rentPayments', $this->allPayments($payments, $generated[$interval->id]));
        }

        return $generated;
    }

    /**
     * @param  Collection<string, RentPayment>  $existing  Monat (Y-m-d) => vorhandene Zeile
     * @param  Collection<int, Lease>  $coupledLeases
     * @param  Collection<int, array<int, int>>  $stored  RentPayment-ID => [Mietvertrag-ID => Cent]
     * @return Collection<int, RentPayment>
     */
    private function fill(LeaseInterval $interval, Collection $existing, Collection $coupledLeases, Collection $stored): Collection
    {
        $cursor = $interval->start_date->copy()->startOfMonth();
        $lastElapsedMonth = now()->startOfMonth();
        $end = $interval->end_date !== null
            ? $interval->end_date->copy()->startOfMonth()->min($lastElapsedMonth)
            : $lastElapsedMonth;
        $defaultAccount = $this->defaultAccount($interval);

        $payments = collect();

        while ($cursor->lte($end)) {
            $payment = $existing->get($cursor->toDateString())
                ?? new RentPayment(['lease_interval_id' => $interval->id, 'period_date' => $cursor->copy()]);
            $isNew = ! $payment->exists;

            // Only still-open (unbooked) rows track later edits to the LeaseInterval's
            // amounts; an already booked payment is a confirmed historical record and
            // must not be silently rewritten. $contributions stays null for an already
            // booked payment, so its (likewise frozen) Aufschlüsselung is left untouched.
            $contributions = $payment->booking_date === null
                ? $this->applyOpenAmounts($payment, $interval, $coupledLeases, $cursor)
                : null;

            // Unveränderte Zeilen gar nicht erst speichern (spart die Model-Events).
            if ($isNew || $payment->isDirty()) {
                $payment->save();
            }

            if ($contributions !== null && $this->contributionsChanged($stored->get($payment->id), $contributions)) {
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

    /**
     * Setzt die Beträge einer offenen Zeile und liefert, welcher Mietvertrag wie viel
     * beigetragen hat – immer dieser Mietvertrag selbst, plus ein Eintrag je in diesem
     * Monat aktiv gekoppeltem Mietvertrag (siehe contributingLeases()).
     *
     * Konzept.md "### Mietvertrag": a Mietvertrag coupled to this one via
     * "Mietvertrag: <ref>" (e.g. ein Stellplatz gekoppelt an diese Wohnung) is never
     * booked on its own (NotBookableOnCoupledLease/SeedsBuchungen::seedZahlungen()
     * both forbid it) – the tenant only transfers rent once. So its rent is instead
     * folded directly into THIS Mietvertrag's own monatliche RentPayment, month by
     * month picking whichever of the coupled Mietvertrag's LeaseIntervals was active
     * that month (none = 0, correctly handling e.g. a mid-Intervall Kündigung of the
     * Stellplatz without affecting the Wohnung's own Mietintervall).
     *
     * @param  Collection<int, Lease>  $coupledLeases
     * @return array<int, float> Mietvertrag-ID => Betrag
     */
    private function applyOpenAmounts(RentPayment $payment, LeaseInterval $interval, Collection $coupledLeases, Carbon $month): array
    {
        $contributions = [$interval->lease_id => (float) $interval->totalMonthlyAmount()];
        $coupledRent = 0.0;
        $coupledUtility = 0.0;

        foreach ($coupledLeases as $coupledLease) {
            $coupledInterval = $coupledLease->leaseIntervals->first(
                fn (LeaseInterval $li) => $li->start_date->lte($month) && ($li->end_date === null || $li->end_date->gte($month))
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

        return $contributions;
    }

    private function defaultAccount(LeaseInterval $interval): ?Account
    {
        $managementGroup = $interval->lease->rentalUnit->managementUnit->managementGroup;

        return $managementGroup->kontosystemEnabled() ? $managementGroup->defaultAccount() : null;
    }

    /**
     * An die Mietverträge der Intervalle gekoppelte Mietverträge (z. B. Stellplätze).
     *
     * @param  EloquentCollection<int, LeaseInterval>  $intervals
     * @return Collection<int, Collection<int, Lease>> Mietvertrag-ID => gekoppelte Mietverträge
     */
    private function coupledLeases(EloquentCollection $intervals): Collection
    {
        return Lease::whereIn('coupled_to_lease_id', $intervals->pluck('lease_id')->unique()->values())
            ->with('leaseIntervals')->get()
            ->groupBy('coupled_to_lease_id');
    }

    /**
     * Alle schon vorhandenen Zeilen der Intervalle, nach ID sortiert.
     *
     * @param  EloquentCollection<int, LeaseInterval>  $intervals
     * @return Collection<int, EloquentCollection<int, RentPayment>> Intervall-ID => Zeilen
     */
    private function existingPayments(EloquentCollection $intervals): Collection
    {
        return collect($intervals->modelKeys())->chunk(self::QUERY_CHUNK_SIZE)
            ->flatMap(fn (Collection $ids) => RentPayment::whereIn('lease_interval_id', $ids->values())->orderBy('id')->get())
            ->groupBy('lease_interval_id')
            ->map(fn (Collection $payments) => new EloquentCollection($payments->values()->all()));
    }

    /**
     * Gespeicherte Aufschlüsselung der offenen Zeilen, direkt aus der Zwischentabelle –
     * ohne dafür jeden Mietvertrag als Modell zu laden.
     *
     * @param  Collection<int, RentPayment>  $payments
     * @return Collection<int, array<int, int>> RentPayment-ID => [Mietvertrag-ID => Cent]
     */
    private function storedContributions(Collection $payments): Collection
    {
        $openIds = $payments->filter(fn (RentPayment $payment) => $payment->booking_date === null)->pluck('id');

        return $openIds->chunk(self::QUERY_CHUNK_SIZE)
            ->flatMap(fn (Collection $ids) => DB::table(self::PIVOT_TABLE)->whereIn('rent_payment_id', $ids->values())->get(['rent_payment_id', 'lease_id', 'amount']))
            ->groupBy('rent_payment_id')
            ->map(fn (Collection $rows) => $rows->mapWithKeys(fn ($row) => [(int) $row->lease_id => $this->cents($row->amount)])->sortKeys()->all());
    }

    /**
     * Ob die gespeicherte Aufschlüsselung von der gewünschten abweicht – nur dann wird
     * neu geschrieben, damit ein bloßes Anzeigen der Seite nichts in die Datenbank schreibt.
     *
     * @param  array<int, int>|null  $stored  Mietvertrag-ID => Cent; null = noch keine
     * @param  array<int, float>  $contributions  Mietvertrag-ID => Betrag
     */
    private function contributionsChanged(?array $stored, array $contributions): bool
    {
        $wanted = collect($contributions)->map(fn ($amount) => $this->cents($amount))->sortKeys()->all();

        return $stored !== $wanted;
    }

    /**
     * @param  EloquentCollection<int, RentPayment>  $existing
     * @param  Collection<int, RentPayment>  $generated
     * @return EloquentCollection<int, RentPayment>
     */
    private function allPayments(EloquentCollection $existing, Collection $generated): EloquentCollection
    {
        return $existing->merge($generated->filter(fn (RentPayment $payment) => $payment->wasRecentlyCreated))->values();
    }

    private function cents(mixed $amount): int
    {
        return (int) round((float) $amount * self::CENTS_PER_EURO);
    }
}
