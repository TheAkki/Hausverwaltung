<?php

namespace Database\Seeders\Concerns;

use App\Models\Account;
use App\Models\AccountTransfer;
use App\Models\BillingInterval;
use App\Models\InvoiceIssuer;
use App\Models\InvoiceIssuerAccount;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\ManagementUnit;
use App\Models\RentalUnit;
use App\Models\SettlementClaim;
use App\Services\RentPaymentBooker;
use Symfony\Component\Yaml\Yaml;

/**
 * Seeds the "Buchungen" block: a parallel, optionally externalized ("from-File")
 * entry point that mirrors "Rechnungen"'s Verwaltungsgruppe/-einheit/Mieteinheit/
 * Mietvertrag/Rechnung-* structure (reusing seedRechnungenForCostable() as-is) and
 * additionally supports two Mietvertrag-level nodes "Rechnungen" doesn't have:
 * "Zahlungen" (bucht bereits vom RentPaymentGenerator erzeugte Mietzahlungen) and
 * "Forderung-*" (Betriebskostennachzahlung/-rückzahlung, siehe SettlementClaim), as
 * well as a Verwaltungsgruppe-level "Umbuchung-*" node (buchungsneutrale Verschiebung
 * zwischen zwei Konten dieser Verwaltungsgruppe, siehe AccountTransfer).
 * "Rechnungen" selbst bleibt davon vollständig unberührt.
 */
trait SeedsBuchungen
{
    /**
     * @param  array<string, mixed>  $data  the Account's "Buchungen" node (may itself be
     *                                      the from-File wrapper, or the structure directly)
     * @param  array<string, ManagementGroup>  $managementGroupsByKey
     * @param  array<string, array<string, ManagementUnit>>  $managementUnitsByKey
     * @param  array<string, array<string, array<string, RentalUnit>>>  $rentalUnitsByKey
     * @param  array<string, InvoiceIssuer>  $invoiceIssuersByKey
     * @param  array<string, array<string, InvoiceIssuerAccount>>  $invoiceIssuerAccountsByKey
     * @param  array<string, array<string, BillingInterval>>  $billingIntervalsByKey  keyed by [vgKey][intervalKey]
     * @param  array<string, array<string, array<string, array<string, Lease>>>>  $leasesByKey  keyed by [vgKey][veKey][meKey][mietvertragKey]
     * @param  array<string, mixed>  $rechnungenData  the Account's "Rechnungen" node (dieselbe
     *                                                Verwaltungsgruppe/-einheit/Mieteinheit/Mietvertrag-Verschachtelung wie
     *                                                "Buchungen"), damit ein "Buchungen.Rechnung-N", das seine eigenen
     *                                                Rechnungssteller/Rechnungsdatum/…-Felder ausspart, sie vom gleichnamigen
     *                                                "Rechnungen.Rechnung-N" derselben Ebene erben kann (siehe
     *                                                seedRechnungenForCostable()'s $fallbackRechnungenByKey) – so muss eine
     *                                                Buchungen-Datei, die nur explizite Buchungstermine zu einer bereits in
     *                                                "Rechnungen" vollständig definierten Rechnung nachträgt, deren
     *                                                Stammdaten nicht wiederholen.
     * @param  array<string, array<string, Account>>  $accountsByKey  keyed by [vgKey][kontoKey], damit eine
     *                                                                "Umbuchung"'s "Von"/"Nach: Konto-*" auf das erzeugte Konto (Account) dieser
     *                                                                Verwaltungsgruppe aufgelöst werden kann.
     */
    private function seedBuchungen(
        array $data,
        array $managementGroupsByKey,
        array $managementUnitsByKey,
        array $rentalUnitsByKey,
        array $invoiceIssuersByKey,
        array $invoiceIssuerAccountsByKey,
        array $billingIntervalsByKey,
        array $leasesByKey,
        array $rechnungenData = [],
        array $accountsByKey = []
    ): void {
        $data = $this->resolveBuchungenFromFile($data);

        foreach ($this->entriesMatchingWithKeys($data, 'Verwaltungsgruppe') as $vgKey => $vgData) {
            $managementGroup = $managementGroupsByKey[$vgKey] ?? null;

            if ($managementGroup === null) {
                throw new \RuntimeException("Buchungen: \"Verwaltungsgruppe-{$vgKey}\" referenziert keine bestehende Verwaltungsgruppe dieses Accounts.");
            }

            $intervals = $billingIntervalsByKey[$vgKey] ?? [];
            $rechnungenVgData = $this->entriesMatchingWithKeys($rechnungenData, 'Verwaltungsgruppe')[$vgKey] ?? [];

            $this->seedRechnungenForCostable(
                $vgData, $managementGroup, $invoiceIssuersByKey, $invoiceIssuerAccountsByKey, $intervals,
                $this->entriesMatchingWithKeys($rechnungenVgData, 'Rechnung')
            );

            $this->seedUmbuchungen($vgData, $accountsByKey[$vgKey] ?? []);

            foreach ($this->entriesMatchingWithKeys($vgData, 'Verwaltungseinheit') as $veKey => $veData) {
                $managementUnit = $managementUnitsByKey[$vgKey][$veKey] ?? null;

                if ($managementUnit === null) {
                    throw new \RuntimeException("Buchungen: \"Verwaltungsgruppe-{$vgKey}.Verwaltungseinheit-{$veKey}\" referenziert keine bestehende Verwaltungseinheit.");
                }

                $rechnungenVeData = $this->entriesMatchingWithKeys($rechnungenVgData, 'Verwaltungseinheit')[$veKey] ?? [];

                $this->seedRechnungenForCostable(
                    $veData, $managementUnit, $invoiceIssuersByKey, $invoiceIssuerAccountsByKey, $intervals,
                    $this->entriesMatchingWithKeys($rechnungenVeData, 'Rechnung')
                );

                foreach ($this->entriesMatchingWithKeys($veData, 'Mieteinheit') as $meKey => $meData) {
                    $rentalUnit = $rentalUnitsByKey[$vgKey][$veKey][$meKey] ?? null;

                    if ($rentalUnit === null) {
                        throw new \RuntimeException("Buchungen: \"Verwaltungsgruppe-{$vgKey}.Verwaltungseinheit-{$veKey}.Mieteinheit-{$meKey}\" referenziert keine bestehende Mieteinheit.");
                    }

                    $rechnungenMeData = $this->entriesMatchingWithKeys($rechnungenVeData, 'Mieteinheit')[$meKey] ?? [];

                    $this->seedRechnungenForCostable(
                        $meData, $rentalUnit, $invoiceIssuersByKey, $invoiceIssuerAccountsByKey, $intervals,
                        $this->entriesMatchingWithKeys($rechnungenMeData, 'Rechnung')
                    );

                    foreach ($this->entriesMatchingWithKeys($meData, 'Mietvertrag') as $mietvertragKey => $mietvertragData) {
                        $lease = $leasesByKey[$vgKey][$veKey][$meKey][$mietvertragKey] ?? null;

                        if ($lease === null) {
                            throw new \RuntimeException(
                                "Buchungen: \"Verwaltungsgruppe-{$vgKey}.Verwaltungseinheit-{$veKey}.Mieteinheit-{$meKey}.Mietvertrag-{$mietvertragKey}\" referenziert keinen bestehenden Mietvertrag."
                            );
                        }

                        $rechnungenMietvertragData = $this->entriesMatchingWithKeys($rechnungenMeData, 'Mietvertrag')[$mietvertragKey] ?? [];

                        $this->seedRechnungenForCostable(
                            $mietvertragData, $lease, $invoiceIssuersByKey, $invoiceIssuerAccountsByKey, $intervals,
                            $this->entriesMatchingWithKeys($rechnungenMietvertragData, 'Rechnung')
                        );
                        $this->seedZahlungen($mietvertragData['Zahlungen'] ?? null, $lease);
                        $this->seedForderungen($mietvertragData, $lease, $intervals);
                    }
                }
            }
        }
    }

    /**
     * Resolves an optional "from-File" wrapper on the "Buchungen" node: loads the
     * referenced YAML file(s) and uses them as the actual Buchungen structure instead.
     * "from-File" may be a single path or a list of paths (e.g. one file per Jahr –
     * "fn4-buchungen-2025.yaml"/"fn4-buchungen-2026.yaml"), all sharing the same
     * Verwaltungsgruppe/-einheit/Mieteinheit/Mietvertrag nesting; their contents are
     * merged (see mergeBuchungenData()) instead of the later file replacing the
     * earlier one. Entries may also live directly in the main file (no from-File
     * given) – both are valid ("Buchungen können sowohl in der Hauptdatei als auch in
     * der importierten Datei notiert sein"), mirroring how a Template's own From-File
     * works. Falls back to the given data unchanged if no "from-File" is set at all;
     * throws if a "from-File" is given but doesn't resolve to a real file (see
     * resolveLocalAssetPath()).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveBuchungenFromFile(array $data): array
    {
        $fromFiles = $this->fromFileValues($data);

        if ($fromFiles === []) {
            return $data;
        }

        $merged = null;

        foreach ($fromFiles as $fromFile) {
            $resolvedPath = $this->resolveLocalAssetPath($fromFile);
            $contents = file_get_contents($resolvedPath);
            $fileData = $contents !== false ? Yaml::parse($contents) : null;

            if (! is_array($fileData)) {
                continue;
            }

            $this->assertNoLiteralNSuffixKeys($fileData);
            $fileData = $this->unwrapBuchungenFile($fileData);

            $merged = $merged === null ? $fileData : $this->mergeBuchungenData($merged, $fileData);
        }

        return $merged ?? $data;
    }

    /**
     * Reads a "from-File" reference that may be given as either a single path
     * (string) or a list of paths (e.g. one file per Jahr), accepting the same
     * case/spelling variants as fromFileValue(). Empty/blank entries are dropped.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function fromFileValues(array $data): array
    {
        foreach ($data as $key => $value) {
            if (! is_string($key) || strtolower($key) !== 'from-file') {
                continue;
            }

            $paths = is_array($value) ? $value : [$value];

            return array_values(array_filter(array_map(
                fn ($path) => trim((string) $path),
                $paths
            ), fn ($path) => $path !== ''));
        }

        return [];
    }

    /**
     * Recursively merges two Buchungen structures that share the same
     * Verwaltungsgruppe/-einheit/Mieteinheit/Mietvertrag nesting (e.g. one file per
     * Jahr): nested arrays (associative, keyed by e.g. "Verwaltungsgruppe-1") are
     * merged key-by-key instead of the second file's value replacing the first's;
     * a scalar (or list, e.g. "Zahlungen") on both sides is instead concatenated so
     * neither file's entries are lost, since both commonly appear under the same
     * Mietvertrag for different periods. "Rechnung-*"-Schlüssel bleiben dabei bewusst
     * schlüsselbasiert (dieselbe Nummer über mehrere Jahresdateien hinweg = dieselbe
     * Rechnung, siehe seedRechnungenForCostable()'s $fallbackRechnungenByKey) –
     * *außer* beide Seiten definieren dieselbe Rechnung-* komplett eigenständig (siehe
     * separateSelfContainedRechnungCollisions()) – anders als eine Rechnung selbst haben
     * ihre "Buchung-*"-Kindeinträge UND ein Mietvertrags "Forderung-*" aber keine
     * schlüsselstabile Bedeutung über Dateigrenzen hinweg (jede Jahresdatei nummeriert
     * ihre eigenen Einträge unabhängig meist wieder bei 0/1), siehe mergeKeyFamily().
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private function mergeBuchungenData(array $a, array $b): array
    {
        [$a, $b, $mergedBuchungFamily] = $this->mergeKeyFamily($a, $b, 'Buchung');
        [$a, $b, $mergedForderungFamily] = $this->mergeKeyFamily($a, $b, 'Forderung');
        [$a, $b] = $this->separateSelfContainedRechnungCollisions($a, $b);

        foreach ($b as $key => $value) {
            if (is_array($value) && isset($a[$key]) && is_array($a[$key])) {
                $a[$key] = array_is_list($value) && array_is_list($a[$key])
                    ? array_merge($a[$key], $value)
                    : $this->mergeBuchungenData($a[$key], $value);

                continue;
            }

            // "Abrechnungsintervall" (Konzept.md "### Rechnung") pins ALL of a Rechnung's
            // cost rows to one single Abrechnungsintervall. If two Jahresdateien contribute
            // "Buchung-*" entries to the SAME Rechnung (already safely combined into one
            // stream above via mergeKeyFamily()) and each sets a DIFFERENT
            // "Abrechnungsintervall" – presumably assuming it only applies to that file's
            // own Buchungen – the generic key-for-key take-over below would let whichever
            // file merges last silently win, pinning EVERY merged Buchung (including the
            // other file's) onto that one Intervall. Found via Nutzerreport: Grundsteuer-
            // Buchungen aus 2025 tauchten im Abrechnungsintervall 2026 auf, weil
            // fn4-buchungen-2026.yaml zuletzt gemergt wurde und dessen "Intervall-0" (2026)
            // das "Intervall-1" (2025) aus fn4-buchungen-2025.yaml überschrieb.
            if ($key === 'Abrechnungsintervall' && isset($a[$key]) && $a[$key] !== $value) {
                throw new \RuntimeException(
                    "Buchungen: widersprüchliches \"Abrechnungsintervall\" (\"{$a[$key]}\" vs. \"{$value}\") für dieselbe Rechnung über mehrere Jahresdateien hinweg. \"Abrechnungsintervall\" pinnt ALLE Buchungen dieser Rechnung auf ein einziges Intervall, nicht nur die der jeweiligen Datei – entweder in nur einer Datei setzen, oder in allen Dateien denselben Wert verwenden."
                );
            }

            $a[$key] = $value;
        }

        return array_merge($a, $mergedBuchungFamily ?? [], $mergedForderungFamily ?? []);
    }

    /**
     * "Nur wenn die Rechnungen in der seeddata.yaml enthalten sind, können auf diese in
     * den importierten Dateien referenziert werden. Werden die Rechnungen in den
     * importierten Dateien definiert (Rechnungssteller, Account, …), dann werden diese
     * (auch wenn die Nummer gleich ist) als separate Rechnungen verwendet." A
     * "Rechnung-*"'s numeric suffix is only a stable cross-Jahresdatei identity when at
     * least one side merely *references* it (no own "Rechnungssteller", relying on
     * inheriting the other side's – or seeddata.yaml's – Stammdaten, e.g. only
     * "Buchung-*" entries). When BOTH sides are fully self-contained (each declares its
     * own "Rechnungssteller"), they are independent Rechnungen from the Nutzer's
     * perspective that merely happened to reuse the same free-standing number (e.g. two
     * unrelated Einmalrechnungen from different Jahre) – merging them key-by-key would
     * silently overwrite one with the other's fields (as happened: a "monatlich"
     * Rechnung's "Wiederholung" got overwritten to "einmalig" by the colliding entry,
     * which then made seedInvoice() skip its own "Buchung-*" entries entirely, since a
     * "once" Rechnung never reads them). Instead, $b's colliding entry is moved to a
     * fresh, non-colliding key first, so the later generic merge loop treats both as
     * entirely separate Rechnung-* entries.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function separateSelfContainedRechnungCollisions(array $a, array $b): array
    {
        $isRechnungKey = fn ($key) => is_string($key) && ($key === 'Rechnung' || str_starts_with($key, 'Rechnung-'));
        $isSelfContained = fn ($value) => is_array($value) && trim((string) ($value['Rechnungssteller'] ?? '')) !== '';

        foreach ($b as $key => $value) {
            if (
                $isRechnungKey($key) && $isSelfContained($value)
                && isset($a[$key]) && $isSelfContained($a[$key])
            ) {
                $freshKey = $key.'-dup';
                $n = 2;
                while (isset($a[$freshKey]) || isset($b[$freshKey])) {
                    $freshKey = $key.'-dup'.$n;
                    $n++;
                }

                $b[$freshKey] = $value;
                unset($b[$key]);
            }
        }

        return [$a, $b];
    }

    /**
     * A Rechnung-*'s own "Buchung-*" entries (one per actual booking, e.g. a Kredit's
     * monatliche Rate) – and, likewise, a Mietvertrag's own "Forderung-*" entries (eine
     * Betriebskostennachzahlung/-rückzahlung) – are numbered independently by each
     * Jahresdatei: both commonly start again at "-0"/"-1". Merging them like every other
     * key (same suffix = same entry, second file's value wins) would silently drop one
     * file's entries wherever their suffixes happen to collide, and – since a merged key
     * keeps whichever position it FIRST appeared at – scramble the surviving entries'
     * order, which for "Buchung-*" corrupts the positional Nth-occurrence matching
     * seedExplicitBuchungEntries() relies on (entry N = the invoice's Nth occurrence).
     * Both "Buchung-*" symptoms were reported by the Nutzer ("Die Zinsbuchungen von 2024
     * sehe ich nicht" – lost entries – *and*, once traced, later years' own bookings
     * landing on the wrong month); the analogous "Forderung-*" collision (two Jahresdateien
     * each starting at "Forderung-0" for the same Mietvertrag, one silently overwriting
     * the other's Nachzahlung/Rückzahlung – "Die Rück-/Nachzahlung 2024 von Mieter A stimmt
     * nicht") turned up later, once "Forderung-*" existed in more than one Jahresdatei
     * for the same Mietvertrag. The fix, shared by both: extract every "{$prefix}-*"/
     * "{$prefix}" entry out of $a and $b *before* the generic per-key merge below (so it
     * never runs on them at all) and instead concatenate them in file-processing order
     * (chronological, since "from-File" lists years ascending) under fresh,
     * collision-free "{$prefix}-0", "{$prefix}-1", … keys. Safe for "Forderung-*" too,
     * since its own de-duplication across reseeds keys on (Mietvertrag, Abrechnungsintervall,
     * Datum) – not on this YAML suffix, see seedForderungen().
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>|null}
     *                                                                                               $a and $b with their "{$prefix}-*" keys removed, plus the combined family
     *                                                                                               (null if neither side had one, so the caller can skip re-merging it in)
     */
    private function mergeKeyFamily(array $a, array $b, string $prefix): array
    {
        $isMatchingKey = fn ($key) => is_string($key) && ($key === $prefix || str_starts_with($key, "{$prefix}-"));

        $aKeys = array_filter(array_keys($a), $isMatchingKey);
        $bKeys = array_filter(array_keys($b), $isMatchingKey);

        if ($aKeys === [] && $bKeys === []) {
            return [$a, $b, null];
        }

        $combined = [];
        $n = 0;

        foreach ($aKeys as $key) {
            $combined["{$prefix}-{$n}"] = $a[$key];
            unset($a[$key]);
            $n++;
        }

        foreach ($bKeys as $key) {
            $combined["{$prefix}-{$n}"] = $b[$key];
            unset($b[$key]);
            $n++;
        }

        return [$a, $b, $combined];
    }

    /**
     * A Buchungen-Datei may hold the Verwaltungsgruppe/-einheit/… structure directly,
     * or wrap it in a single top-level "Buchungen" key (matching how the inline block
     * itself looks, which is how a Buchungen-Datei is naturally written by hand) –
     * both are accepted, mirroring the same tolerance already built for a Template's
     * "From-File" (unwrapTemplateFile()).
     *
     * @param  array<string, mixed>  $fileData
     * @return array<string, mixed>
     */
    private function unwrapBuchungenFile(array $fileData): array
    {
        return isset($fileData['Buchungen']) && is_array($fileData['Buchungen'])
            ? $fileData['Buchungen']
            : $fileData;
    }

    /**
     * Books already-generated (RentPaymentGenerator) monthly Mietzahlungen for a
     * Mietvertrag: each date sets the booking_date of whichever month's RentPayment it
     * belongs to (see rentPaymentPeriodForDate() for which month that is) – unlike a
     * Rechnung's "Buchungen", a rent payment has no varying periodicity to position
     * against, so matching by (adjusted) calendar month is the simpler and more
     * natural fit.
     *
     * Konzept.md "### Mietvertrag": "Genierie bitte Fehlermeldungen, wenn Buchungen auf
     * ein referenzierenden Mietvertrag gebucht werden." – a coupled ("referenzierender")
     * Mietvertrag (`coupled_to_lease_id !== null`) may not declare its own "Zahlungen";
     * the tenant only transfers rent once, booked against the referenced Mietvertrag.
     *
     * A date that doesn't fall into any of this Mietvertrag's Mietintervalle (e.g. it
     * was meant for a different, neighbouring Mietvertrag – a Vorvertrag/Nachfolger
     * boundary being the usual cause) is deliberately left unbooked rather than
     * aborting the whole seed run: unlike a Rechnung that vanishes without a trace,
     * the affected RentPayment simply stays "offen" and surfaces in the "Detailbild
     * offene Zahlungen"-Übersicht, so it remains discoverable via the Oberfläche.
     *
     * @param  array<int, mixed>|null  $zahlungen
     *
     * @throws \RuntimeException if $lease is coupled to another Mietvertrag and still
     *                           declares its own "Zahlungen", or a listed date doesn't
     *                           parse
     */
    private function seedZahlungen(?array $zahlungen, Lease $lease): void
    {
        if ($zahlungen === null || $zahlungen === []) {
            return;
        }

        if ($lease->coupled_to_lease_id !== null) {
            throw new \RuntimeException(
                "Mietvertrag \"{$lease->rentalUnit->label()}\" (Id {$lease->id}) ist per Mietvertrag-Referenz an ".
                'einen anderen Mietvertrag gekoppelt und darf daher keine eigenen "Zahlungen" haben – der Mieter '.
                'überweist nur einmal Miete, die Buchung erfolgt beim referenzierten Mietvertrag.'
            );
        }

        $dates = [];

        foreach ($zahlungen as $rawDate) {
            $date = $this->parseDate($rawDate);

            if ($date === null) {
                throw new \RuntimeException(
                    "Mietvertrag \"{$lease->rentalUnit->label()}\" (Id {$lease->id}): ein Eintrag in 'Zahlungen' ist kein gültiges Datum (\"{$rawDate}\")."
                );
            }

            $dates[] = $date;
        }

        // Bewusste Ausnahme von "niemals stillschweigend überspringen"
        // (Nutzerklarstellung): eine nicht zuordenbare Zahlung bleibt nicht spurlos –
        // das zugehörige RentPayment bleibt "offen" und taucht in der "Detailbild offene
        // Zahlungen"-Übersicht auf (siehe RentPaymentBooker::bookAll()).
        app(RentPaymentBooker::class)->bookAll($lease, $dates);
    }

    /**
     * Seeds a Mietvertrag's "Forderung-*" entries (Betriebskostennachzahlung/
     * -rückzahlung, positiver Betrag = Nachzahlung, negativer Betrag = Rückzahlung).
     * "Abrechnungsintervall" ist Pflicht, damit sich die zugrunde liegenden Nebenkosten
     * dieses Abrechnungszeitraums später nachvollziehen lassen – wie Datum und Betrag
     * (Konzept.md "### Buchungen": "Abrechnungsintervall: Intervall-* # Pflichtangabe").
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, BillingInterval>  $billingIntervalsByKey
     */
    private function seedForderungen(array $data, Lease $lease, array $billingIntervalsByKey): void
    {
        foreach ($this->entriesMatchingWithKeys($data, 'Forderung') as $key => $forderungData) {
            $intervalRef = trim((string) ($forderungData['Abrechnungsintervall'] ?? ''));
            $date = $this->parseDate($forderungData['Datum'] ?? null);
            $amount = $this->parseDecimal($forderungData['Betrag'] ?? null);

            if ($intervalRef === '' || $date === null || $amount === null) {
                throw new \RuntimeException(
                    "Forderung-{$key} des Mietvertrags \"{$lease->rentalUnit->label()}\" (Id {$lease->id}) hat kein ".
                    'Abrechnungsintervall, kein (gültiges) Datum oder keinen (gültigen) Betrag – alle drei sind Pflichtangaben.'
                );
            }

            $intervalKey = $this->normalizeReferenceKey($intervalRef, 'Intervall-');
            $billingInterval = $billingIntervalsByKey[$intervalKey] ?? null;

            if ($billingInterval === null) {
                throw new \RuntimeException(
                    "Forderung-{$key} des Mietvertrags \"{$lease->rentalUnit->label()}\" (Id {$lease->id}): ".
                    "\"{$intervalRef}\" referenziert kein bestehendes Abrechnungsintervall dieser Verwaltungsgruppe."
                );
            }

            $values = [
                'amount' => $amount,
                'booking_date' => $this->parseDate($forderungData['Buchungsdatum'] ?? null),
                'notes' => $forderungData['Notiz'] ?? null,
            ];

            // Match by DATE, not exact value: a 'date'-cast attribute can round-trip
            // through Carbon with an added time component, so an exact-string
            // firstOrCreate/updateOrCreate on it would miss an already-seeded row and
            // create a duplicate on every reseed (same pitfall as BillingInterval's own
            // seeding, see seedBillingIntervals()).
            $claim = SettlementClaim::where('lease_id', $lease->id)
                ->where('billing_interval_id', $billingInterval->id)
                ->whereDate('date', $date)
                ->first();

            if ($claim === null) {
                SettlementClaim::create([
                    'lease_id' => $lease->id,
                    'billing_interval_id' => $billingInterval->id,
                    'date' => $date,
                    ...$values,
                ]);
            } else {
                $claim->update($values);
            }
        }
    }

    /**
     * Seeds a Verwaltungsgruppe's "Umbuchung-*" entries (booking-neutral transfers
     * between two of its own Konten, see AccountTransfer). "Von"/"Nach" reference a
     * "Konto-*" of this same Verwaltungsgruppe (Konzept.md: "'Von' und 'Nach'
     * referenzieren je ein in der Verwaltungsgruppe definiertes Konto-*; beide müssen
     * zu dieser Verwaltungsgruppe gehören und dürfen nicht dasselbe Konto sein. Datum
     * und Betrag sind Pflichtangaben.").
     *
     * @param  array<string, mixed>  $vgData
     * @param  array<string, Account>  $accountsForThisVg  keyed by "Konto-*" YAML suffix
     */
    private function seedUmbuchungen(array $vgData, array $accountsForThisVg): void
    {
        foreach ($this->entriesMatchingWithKeys($vgData, 'Umbuchung') as $key => $umbuchungData) {
            $vonRef = trim((string) ($umbuchungData['Von'] ?? ''));
            $nachRef = trim((string) ($umbuchungData['Nach'] ?? ''));
            $date = $this->parseDate($umbuchungData['Datum'] ?? null);
            $amount = $this->parseDecimal($umbuchungData['Betrag'] ?? null);

            if ($vonRef === '' || $nachRef === '' || $date === null || $amount === null) {
                throw new \RuntimeException("Umbuchung-{$key}: 'Von', 'Nach', 'Datum' und 'Betrag' sind alle Pflichtangaben.");
            }

            $fromAccount = $accountsForThisVg[$this->normalizeReferenceKey($vonRef, 'Konto-')] ?? null;
            $toAccount = $accountsForThisVg[$this->normalizeReferenceKey($nachRef, 'Konto-')] ?? null;

            if ($fromAccount === null || $toAccount === null) {
                throw new \RuntimeException("Umbuchung-{$key}: \"{$vonRef}\"/\"{$nachRef}\" referenziert kein bestehendes Konto dieser Verwaltungsgruppe.");
            }

            if ($fromAccount->id === $toAccount->id) {
                throw new \RuntimeException("Umbuchung-{$key}: 'Von' und 'Nach' dürfen nicht dasselbe Konto sein.");
            }

            $values = [
                'amount' => $amount,
                'comment' => $umbuchungData['Notiz'] ?? null,
            ];

            // Match by DATE, not exact value, for the same round-tripping reason as
            // seedForderungen()'s SettlementClaim lookup above.
            $transfer = AccountTransfer::where('from_account_id', $fromAccount->id)
                ->where('to_account_id', $toAccount->id)
                ->where('amount', $amount)
                ->whereDate('transfer_date', $date)
                ->first();

            if ($transfer === null) {
                AccountTransfer::create([
                    'from_account_id' => $fromAccount->id,
                    'to_account_id' => $toAccount->id,
                    'transfer_date' => $date,
                    ...$values,
                ]);
            } else {
                $transfer->update($values);
            }
        }
    }
}
