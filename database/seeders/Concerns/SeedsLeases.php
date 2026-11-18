<?php

namespace Database\Seeders\Concerns;

use App\Models\Lease;
use App\Models\RentalUnit;
use App\Models\Tenant;
use Carbon\Carbon;

/**
 * Seeds a Mieteinheit's Mietverträge and their Mietintervalle.
 */
trait SeedsLeases
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, Tenant>  $tenantsByKey
     * @param  array<int, int>|null  $explicitTenantIds  when given, used as-is instead of resolving
     *                                                   "MieterId"/"MieterId-*" from $data – used by
     *                                                   the "Mietvertrag: <ref>"-coupling pass (see
     *                                                   TestDataSeeder::seedManagementUnit()), where
     *                                                   the tenant identity comes from the referenced
     *                                                   Mietvertrag instead of this entry's own data
     * @param  int|null  $coupledToLeaseId  set together with $explicitTenantIds by the coupling pass –
     *                                      persisted so the Steuerübersicht can fold this lease's
     *                                      income into the referenced one instead of listing it
     *                                      separately (Konzept.md "### Mietvertrag")
     * @return Lease the created/found lease, keyed by its Mietvertrag-* YAML suffix
     *               by the caller so a "Rechnungen"-block entry nested under the
     *               same Mietvertrag-* can book Kosten directly against it
     */
    private function seedLease(array $data, RentalUnit $rentalUnit, array $tenantsByKey, ?array $explicitTenantIds = null, ?int $coupledToLeaseId = null): Lease
    {
        $start = $this->parseDate($data['Beginn'] ?? null);

        if (empty($start)) {
            throw new \RuntimeException("Mietvertrag \"{$rentalUnit->label()}\" hat kein (gültiges) Beginn-Datum.");
        }

        if ($explicitTenantIds !== null) {
            $tenantIds = $explicitTenantIds;
        } else {
            $tenantIds = [];
            $unresolvedKeys = [];

            foreach ($this->tenantKeysFromLeaseData($data) as $key) {
                if (isset($tenantsByKey[$key])) {
                    $tenantIds[] = $tenantsByKey[$key]->id;
                } else {
                    $unresolvedKeys[] = $key;
                }
            }

            if ($unresolvedKeys !== []) {
                throw new \RuntimeException(
                    "Mietvertrag \"{$rentalUnit->label()}\" (Beginn {$start}) referenziert unbekannte MieterId(s): ".
                    implode(', ', $unresolvedKeys).'.'
                );
            }
        }

        if (empty($tenantIds)) {
            throw new \RuntimeException("Mietvertrag \"{$rentalUnit->label()}\" (Beginn {$start}) hat keinen (auflösbaren) Mieter.");
        }

        $intervalEntries = $this->entriesMatchingWithKeys($data, 'Intervall');
        uksort($intervalEntries, fn ($a, $b) => (int) $a <=> (int) $b);

        // Kaltmiete/Nebenkosten can be declared on the Mietvertrag itself (the "vereinbarte
        // Kaltmiete") and are used as the default for every Intervall that doesn't override them.
        $defaultColdRent = $this->parseDecimal($data['Kaltmiete'] ?? null);
        $defaultUtilityAdvance = $this->parseDecimal($data['Nebenkosten'] ?? null);

        $firstIntervalData = $intervalEntries === [] ? null : reset($intervalEntries);
        $coldRent = $defaultColdRent
            ?? ($firstIntervalData !== null ? $this->parseDecimal($firstIntervalData['Kaltmiete'] ?? 0) : 0.0);

        // "Vorvertrag": a pre-contract that is not billed separately – its period and
        // Betriebskostenvorauszahlung roll into the following lease (default false).
        $isPreContract = (bool) ($data['Vorvertrag'] ?? false);

        $lease = $rentalUnit->leases()->whereDate('start_date', $start)->first();

        if ($lease === null) {
            $lease = $rentalUnit->leases()->create([
                'start_date' => $start,
                'end_date' => $this->parseDate($data['Ende'] ?? null),
                'is_pre_contract' => $isPreContract,
                'coupled_to_lease_id' => $coupledToLeaseId,
                'rent_adjustment_type' => $this->mapRentAdjustmentType($data['Mietpreistyp'] ?? null),
                'cold_rent' => $coldRent,
                'deposit_amount' => $this->parseDecimal($data['Kaution'] ?? null),
                'min_term_months' => (int) ($data['MinLaufzeit'] ?? 0),
                'notice_period' => $data['Kundigungsfrist'] ?? null,
                'notes' => $data['Notiz'] ?? null,
            ]);
        } else {
            $updates = [];

            if ($lease->is_pre_contract !== $isPreContract) {
                $updates['is_pre_contract'] = $isPreContract;
            }

            if ($lease->coupled_to_lease_id !== $coupledToLeaseId) {
                $updates['coupled_to_lease_id'] = $coupledToLeaseId;
            }

            if ($updates !== []) {
                $lease->update($updates);
            }
        }

        $lease->tenants()->syncWithoutDetaching($tenantIds);

        if ($intervalEntries !== []) {
            $this->seedLeaseIntervals($lease, $intervalEntries, $defaultColdRent, $defaultUtilityAdvance);
        }

        return $lease;
    }

    /**
     * Creates/updates the Mietintervalle for a Mietvertrag from its "Intervall-*" entries
     * (already sorted chronologically). The first entry maps onto the Mietintervall the
     * LeaseObserver auto-creates when the Mietvertrag itself is created; every following
     * entry is appended, closing the previous interval the day before the next one begins.
     * Kaltmiete/Nebenkosten fall back to the previous Intervall's value (so an increase
     * carries forward), or to the Mietvertrag-level defaults for the very first Intervall.
     *
     * @param  array<string, array<string, mixed>>  $intervalEntries  sorted by chronological key
     */
    private function seedLeaseIntervals(
        Lease $lease,
        array $intervalEntries,
        ?float $defaultColdRent,
        ?float $defaultUtilityAdvance
    ): void {
        $previous = null;
        $lastKey = array_key_last($intervalEntries);

        foreach ($intervalEntries as $key => $data) {
            $start = $this->parseDate($data['Beginn'] ?? null) ?? ($previous === null ? $lease->start_date->toDateString() : null);

            // Konzept.md "### Mietintervall": "Beim neuen Mietintervall muss ein
            // Startdatum gesetzt werden." – only the very first Intervall may omit it
            // (falls back to the Mietvertrag's own start_date above).
            if (empty($start)) {
                throw new \RuntimeException("Intervall-{$key} des Mietvertrags \"{$lease->rentalUnit->label()}\" (Id {$lease->id}) hat kein (gültiges) Beginn-Datum.");
            }

            if ($previous !== null) {
                $newStart = Carbon::parse($start);

                if ($newStart->gt($previous->start_date)) {
                    $previous->update(['end_date' => $newStart->copy()->subDay()]);
                }
            }

            $interval = $lease->leaseIntervals()->whereDate('start_date', $start)->first();

            if ($interval === null) {
                $interval = $lease->leaseIntervals()->create([
                    'start_date' => $start,
                    'rent_amount' => 0,
                    'utility_advance_payment' => 0,
                    'occupant_count' => 1,
                ]);
            }

            // The last Intervall in the chain has no following one to close it. If it
            // doesn't specify its own "Ende", it must still inherit the Mietvertrag's own
            // end date (e.g. a terminated lease whose single/final Intervall has no Ende
            // of its own) instead of being left "offen" – otherwise RentPaymentGenerator
            // keeps generating rent payments long past the lease's actual end.
            $endDate = $this->parseDate($data['Ende'] ?? null)
                ?? ($key === $lastKey ? $lease->end_date?->toDateString() : null);

            $interval->update([
                'end_date' => $endDate,
                'rent_amount' => $this->parseDecimal($data['Kaltmiete'] ?? null)
                    ?? optional($previous)->rent_amount ?? $defaultColdRent ?? 0.0,
                'utility_advance_payment' => $this->parseDecimal($data['Nebenkosten'] ?? null)
                    ?? optional($previous)->utility_advance_payment ?? $defaultUtilityAdvance ?? 0.0,
                'occupant_count' => (int) ($data['Personen'] ?? optional($previous)->occupant_count ?? 1),
                'notes' => $data['Notiz'] ?? null,
            ]);

            $interval->pruneOpenRentPaymentsBeyondEndDate();

            $previous = $interval;
        }
    }

    /**
     * Collects the "MieterId" value together with any "MieterId-*" values. Accepts either
     * the bare key suffix (e.g. "1") or the full "Mieter-1" reference.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function tenantKeysFromLeaseData(array $data): array
    {
        $keys = [];

        if (isset($data['MieterId']) && $data['MieterId'] !== '') {
            $keys[] = $this->normalizeTenantKey((string) $data['MieterId']);
        }

        foreach ($data as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'MieterId-') && $value !== '') {
                $keys[] = $this->normalizeTenantKey((string) $value);
            }
        }

        return $keys;
    }

    private function normalizeTenantKey(string $value): string
    {
        return str_starts_with($value, 'Mieter-') ? substr($value, strlen('Mieter-')) : $value;
    }

    /**
     * Resolves a Mietvertrag-*'s "Mietvertrag: <ref>" coupling reference (Konzept.md
     * "### Mietvertrag"): instead of its own MieterId, a Mietvertrag can point at
     * another Mietvertrag of a DIFFERENT Mieteinheit of the SAME Verwaltungseinheit and
     * inherit its Mieter(-Identität) – e.g. a non-abrechnungsrelevante Garage's Vertrag
     * coupled to the abrechnungsrelevanten Wohnung's Vertrag of the same tenant.
     *
     * The reference is "<Verwaltungsgruppe>.<Verwaltungseinheit>.<Mieteinheit>.<Mietvertrag>",
     * with any leading segments omittable as long as the remainder stays unambiguous;
     * since a reference may never leave the current Verwaltungseinheit (restriction
     * "a" below), a given Verwaltungsgruppe/Verwaltungseinheit segment only ever serves
     * to *validate* that restriction, never to broaden the search.
     *
     * @param  array<string, RentalUnit>  $rentalUnitsByKey  every Mieteinheit of the current
     *                                                       Verwaltungseinheit, keyed by suffix
     * @param  array<string, array<string, Lease>>  $leasesByKey  every already-seeded (first-pass,
     *                                                            MieterId-based) Lease of the current
     *                                                            Verwaltungseinheit, keyed by
     *                                                            [Mieteinheit suffix][Mietvertrag suffix]
     *
     * @throws \RuntimeException if the reference is malformed, leaves the current
     *                           Verwaltungsgruppe/-einheit, stays within the same
     *                           Mieteinheit, doesn't resolve to exactly one Mietvertrag,
     *                           or targets a Mieteinheit that isn't abrechnungsrelevant
     *                           (restrictions "a"/"b" and the "nur … an eine
     *                           abrechnungsrelevante Mieteinheit gekoppelt" rule)
     */
    private function resolveLeaseReference(
        string $ref,
        string $currentVgKey,
        string $currentVeKey,
        string $sourceMeKey,
        array $rentalUnitsByKey,
        array $leasesByKey
    ): Lease {
        $segments = array_values(array_filter(
            array_map('trim', explode('.', $ref)),
            fn ($segment) => $segment !== ''
        ));

        if ($segments === []) {
            throw new \RuntimeException("Leere Mietvertrag-Referenz \"{$ref}\".");
        }

        $mietvertragSeg = array_pop($segments);
        $meSeg = $segments !== [] ? array_pop($segments) : null;
        $veSeg = $segments !== [] ? array_pop($segments) : null;
        $vgSeg = $segments !== [] ? array_pop($segments) : null;

        if ($segments !== []) {
            throw new \RuntimeException("Ungültige Mietvertrag-Referenz \"{$ref}\": zu viele Segmente.");
        }

        if ($vgSeg !== null && $this->normalizeReferenceKey($vgSeg, 'Verwaltungsgruppe-') !== $currentVgKey) {
            throw new \RuntimeException(
                "Mietvertrag-Referenz \"{$ref}\": es kann nicht auf eine andere Verwaltungsgruppe referenziert werden."
            );
        }

        if ($veSeg !== null && $this->normalizeReferenceKey($veSeg, 'Verwaltungseinheit-') !== $currentVeKey) {
            throw new \RuntimeException(
                "Mietvertrag-Referenz \"{$ref}\": es kann nicht auf eine andere Verwaltungseinheit referenziert werden."
            );
        }

        $mietvertragKey = $this->normalizeReferenceKey($mietvertragSeg, 'Mietvertrag-');

        if ($meSeg !== null) {
            $meKey = $this->normalizeReferenceKey($meSeg, 'Mieteinheit-');
            $candidateMeKeys = isset($leasesByKey[$meKey][$mietvertragKey]) ? [$meKey] : [];
        } else {
            $candidateMeKeys = [];
            foreach ($leasesByKey as $meKey => $leasesOfThisMe) {
                if (isset($leasesOfThisMe[$mietvertragKey])) {
                    $candidateMeKeys[] = $meKey;
                }
            }
        }

        if ($candidateMeKeys === []) {
            throw new \RuntimeException("Mietvertrag-Referenz \"{$ref}\" konnte nicht aufgelöst werden.");
        }

        if (count($candidateMeKeys) > 1) {
            throw new \RuntimeException(
                "Mietvertrag-Referenz \"{$ref}\" ist mehrdeutig: mehrere Mieteinheiten dieser Verwaltungseinheit ".
                "haben einen \"Mietvertrag-{$mietvertragKey}\"."
            );
        }

        $targetMeKey = $candidateMeKeys[0];

        if ($targetMeKey === $sourceMeKey) {
            throw new \RuntimeException(
                "Mietvertrag-Referenz \"{$ref}\": es kann nicht auf einen anderen Mietvertrag in der selben ".
                'Mieteinheit referenziert werden.'
            );
        }

        if (! $rentalUnitsByKey[$targetMeKey]->is_relevant_for_billing) {
            throw new \RuntimeException(
                "Mietvertrag-Referenz \"{$ref}\": die referenzierte Mieteinheit ist nicht abrechnungsrelevant – ".
                'es darf nur eine nicht abrechnungsrelevante Mieteinheit an eine abrechnungsrelevante gekoppelt werden.'
            );
        }

        return $leasesByKey[$targetMeKey][$mietvertragKey];
    }

    /**
     * Konzept.md "### Detailbild Mieteinheit": a coupled ("referenzierender")
     * Mietvertrag may not outlive the Mietvertrag it references – it must end at the
     * latest when the referenced Mietvertrag does. An open-ended (no "Ende") referenced
     * Mietvertrag imposes no limit on the coupled one.
     *
     * @param  array<string, mixed>  $leaseData
     *
     * @throws \RuntimeException if the referenced Mietvertrag has an "Ende" and the
     *                           coupled Mietvertrag either has none or ends later
     */
    private function assertCoupledLeaseDoesNotOutliveReference(string $ref, array $leaseData, Lease $targetLease): void
    {
        if ($targetLease->end_date === null) {
            return;
        }

        $end = $this->parseDate($leaseData['Ende'] ?? null);

        if ($end === null || Carbon::parse($end)->gt($targetLease->end_date)) {
            throw new \RuntimeException(
                "Mietvertrag-Referenz \"{$ref}\": der referenzierende Mietvertrag darf nicht nach dem ".
                'referenzierten Mietvertrag enden.'
            );
        }
    }
}
