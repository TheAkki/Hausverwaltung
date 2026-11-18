<?php

namespace Database\Seeders\Concerns;

use App\Models\BillingInterval;
use App\Models\CostCategory;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\InvoiceIssuerAccount;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\ManagementUnit;
use App\Models\RentalUnit;
use App\Services\InvoiceReceipt;
use App\Services\InvoiceSeries\CostableManagementGroup;
use App\Services\InvoiceSeries\CostAllocation;
use App\Services\InvoiceSeries\CostSegment;
use App\Services\InvoiceSeries\ForecastRefresher;
use App\Services\InvoiceSeries\InvalidSeriesException;
use App\Services\InvoiceSeries\InvoiceBookingRecorder;
use App\Services\InvoiceSeries\InvoiceForecastWriter;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Seeds the "Rechnungen" block: Rechnungen (Invoices) and their resulting Kosten
 * (Costs), including the automatic monthly forecast from Buchungsdatum + Wiederholung,
 * explicit "Buchungen"/"Buchung-*" bookings that upgrade the matching forecast row in
 * place, and resolution of a Rechnung's pinned "Abrechnungsintervall".
 */
trait SeedsInvoicesAndCosts
{
    /**
     * When a Rechnung pins its costs to a specific "Abrechnungsintervall: Intervall-*",
     * this holds the resolved BillingInterval for the duration of that invoice's
     * seeding, so the per-date BillingInterval lookup uses it instead of resolving one
     * from each cost's period date. Reset to null for every invoice without a pin.
     */
    private ?BillingInterval $billingIntervalOverride = null;

    /**
     * The current Rechnung's Verwaltungsgruppe's full "Intervall-*"-Karte (Schlüssel =>
     * BillingInterval), so ein einzelner "Buchung-*"-Split-Eintrag ("Betrag-n"/
     * "Kategorie-n") sein EIGENES "Abrechnungsintervall-n" referenzieren kann (Konzept.md
     * "### Rechnung": "Das Splitten muss auch auf unterschliedliche Abrechnungsintervalle
     * sein.") – unabhängig vom rechnungsweiten $billingIntervalOverride, der ja gerade nur
     * EIN einziges Intervall für die ganze Rechnung erlaubt.
     *
     * @var array<string, BillingInterval>
     */
    private array $billingIntervalsByKeyForCurrentInvoice = [];

    /**
     * Walks the "Rechnungen" block, which mirrors the Verwaltungsgruppe/-einheit/
     * Mieteinheit/Mietvertrag structure: a "Rechnung-*" found at any level is booked
     * against whichever entity it is nested directly under (its "Wirkungsrahmen"). A
     * Rechnung nested under a Mietvertrag only actually contributes to that lease's
     * Betriebskostenabrechnung for "individuell" abgerechnete Kategorien (Konzept.md
     * "### Rechnungen"/"### Kostenzuweisung") – for any other Abrechnungsschlüssel it's
     * silently ignored at lease level, exactly like the other three levels ignore an
     * "individuell" cost booked there (see BillingCostAllocationService).
     *
     * @param  array<string, mixed>  $rechnungenData
     * @param  array<string, ManagementGroup>  $managementGroupsByKey
     * @param  array<string, array<string, ManagementUnit>>  $managementUnitsByKey
     * @param  array<string, array<string, array<string, RentalUnit>>>  $rentalUnitsByKey
     * @param  array<string, InvoiceIssuer>  $invoiceIssuersByKey
     * @param  array<string, array<string, InvoiceIssuerAccount>>  $invoiceIssuerAccountsByKey
     * @param  array<string, array<string, BillingInterval>>  $billingIntervalsByKey  keyed by [vgKey][intervalKey]
     * @param  array<string, array<string, array<string, array<string, Lease>>>>  $leasesByKey  keyed by [vgKey][veKey][meKey][mietvertragKey]
     */
    private function seedRechnungen(
        array $rechnungenData,
        array $managementGroupsByKey,
        array $managementUnitsByKey,
        array $rentalUnitsByKey,
        array $invoiceIssuersByKey,
        array $invoiceIssuerAccountsByKey,
        array $billingIntervalsByKey = [],
        array $leasesByKey = []
    ): void {
        foreach ($this->entriesMatchingWithKeys($rechnungenData, 'Verwaltungsgruppe') as $vgKey => $vgData) {
            $managementGroup = $managementGroupsByKey[$vgKey] ?? null;

            if ($managementGroup === null) {
                throw new \RuntimeException("Rechnungen: \"Verwaltungsgruppe-{$vgKey}\" referenziert keine bestehende Verwaltungsgruppe dieses Accounts.");
            }

            $intervals = $billingIntervalsByKey[$vgKey] ?? [];

            $this->seedRechnungenForCostable($vgData, $managementGroup, $invoiceIssuersByKey, $invoiceIssuerAccountsByKey, $intervals);

            foreach ($this->entriesMatchingWithKeys($vgData, 'Verwaltungseinheit') as $veKey => $veData) {
                $managementUnit = $managementUnitsByKey[$vgKey][$veKey] ?? null;

                if ($managementUnit === null) {
                    throw new \RuntimeException("Rechnungen: \"Verwaltungsgruppe-{$vgKey}.Verwaltungseinheit-{$veKey}\" referenziert keine bestehende Verwaltungseinheit.");
                }

                $this->seedRechnungenForCostable($veData, $managementUnit, $invoiceIssuersByKey, $invoiceIssuerAccountsByKey, $intervals);

                foreach ($this->entriesMatchingWithKeys($veData, 'Mieteinheit') as $meKey => $meData) {
                    $rentalUnit = $rentalUnitsByKey[$vgKey][$veKey][$meKey] ?? null;

                    if ($rentalUnit === null) {
                        throw new \RuntimeException("Rechnungen: \"Verwaltungsgruppe-{$vgKey}.Verwaltungseinheit-{$veKey}.Mieteinheit-{$meKey}\" referenziert keine bestehende Mieteinheit.");
                    }

                    $this->seedRechnungenForCostable($meData, $rentalUnit, $invoiceIssuersByKey, $invoiceIssuerAccountsByKey, $intervals);

                    foreach ($this->entriesMatchingWithKeys($meData, 'Mietvertrag') as $mietvertragKey => $mietvertragData) {
                        $lease = $leasesByKey[$vgKey][$veKey][$meKey][$mietvertragKey] ?? null;

                        if ($lease === null) {
                            throw new \RuntimeException(
                                "Rechnungen: \"Verwaltungsgruppe-{$vgKey}.Verwaltungseinheit-{$veKey}.Mieteinheit-{$meKey}.Mietvertrag-{$mietvertragKey}\" referenziert keinen bestehenden Mietvertrag."
                            );
                        }

                        $this->seedRechnungenForCostable($mietvertragData, $lease, $invoiceIssuersByKey, $invoiceIssuerAccountsByKey, $intervals);
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, InvoiceIssuer>  $invoiceIssuersByKey
     * @param  array<string, array<string, InvoiceIssuerAccount>>  $invoiceIssuerAccountsByKey
     * @param  array<string, BillingInterval>  $billingIntervalsByKey
     * @param  array<string, array<string, mixed>>  $fallbackRechnungenByKey  the same-Ebene
     *                                                                        "Rechnungen"-Knotens Rechnung-*-Einträge (Schlüssel = Suffix nach "Rechnung-"),
     *                                                                        genutzt nur vom "Buchungen"-Aufrufer (siehe SeedsBuchungen::seedBuchungen()):
     *                                                                        ein "Buchungen.Rechnung-N", dessen eigene Felder (Rechnungssteller/
     *                                                                        Rechnungsdatum/Betrag/…) fehlen, erbt sie vom gleichnamigen
     *                                                                        "Rechnungen.Rechnung-N" derselben Ebene, statt jedes Feld wiederholen zu
     *                                                                        müssen – die eigenen Felder (z. B. explizite Buchung-*-Termine) haben dabei
     *                                                                        weiterhin Vorrang.
     */
    private function seedRechnungenForCostable(
        array $data,
        Model $costable,
        array $invoiceIssuersByKey,
        array $invoiceIssuerAccountsByKey,
        array $billingIntervalsByKey = [],
        array $fallbackRechnungenByKey = []
    ): void {
        foreach ($this->entriesMatchingWithKeys($data, 'Rechnung') as $suffix => $rechnungData) {
            if (isset($fallbackRechnungenByKey[$suffix])) {
                $rechnungData = array_merge($fallbackRechnungenByKey[$suffix], $rechnungData);
            }

            $this->seedInvoice($rechnungData, $costable, $invoiceIssuersByKey, $invoiceIssuerAccountsByKey, $billingIntervalsByKey);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, InvoiceIssuer>  $invoiceIssuersByKey
     * @param  array<string, array<string, InvoiceIssuerAccount>>  $invoiceIssuerAccountsByKey
     * @param  array<string, BillingInterval>  $billingIntervalsByKey  the Verwaltungsgruppe's
     *                                                                 defined intervals, for
     *                                                                 resolving an
     *                                                                 "Abrechnungsintervall"
     *                                                                 reference
     */
    private function seedInvoice(
        array $data,
        Model $costable,
        array $invoiceIssuersByKey,
        array $invoiceIssuerAccountsByKey,
        array $billingIntervalsByKey = []
    ): void {
        $issuerKey = $this->normalizeReferenceKey((string) ($data['Rechnungssteller'] ?? ''), 'Rechnungssteller-');
        $issuer = $invoiceIssuersByKey[$issuerKey] ?? null;

        // A Rechnung may pin its costs to a specific Abrechnungsintervall via
        // "Abrechnungsintervall: Intervall-*"; when absent, each cost's BillingInterval
        // is resolved from its period date (BillingIntervalLocator), which for
        // explicitly defined intervals lands it in the covering one automatically.
        $this->billingIntervalOverride = null;
        $this->billingIntervalsByKeyForCurrentInvoice = $billingIntervalsByKey;
        $intervalRef = trim((string) ($data['Abrechnungsintervall'] ?? ''));
        if ($intervalRef !== '') {
            $intervalKey = $this->normalizeReferenceKey($intervalRef, 'Intervall-');
            $this->billingIntervalOverride = $billingIntervalsByKey[$intervalKey] ?? null;
        }

        $invoiceDate = $this->parseDate($data['Rechnungsdatum'] ?? null);

        // Kontext für Fehlermeldungen, bevor $issuer als aufgelöst gilt – ein falscher/
        // fehlender Rechnungssteller ist ja gerade einer der beiden möglichen Fehler hier.
        $rawContext = trim((string) ($data['Beschreibung'] ?? '')) !== ''
            ? "\"{$data['Beschreibung']}\""
            : "Rechnungssteller \"{$data['Rechnungssteller']}\", Rechnungsdatum \"{$data['Rechnungsdatum']}\"";

        if ($issuer === null) {
            throw new \RuntimeException(
                "Rechnung ({$rawContext}) referenziert einen unbekannten Rechnungssteller \"{$data['Rechnungssteller']}\" (oder das Feld fehlt)."
            );
        }

        if ($invoiceDate === null) {
            throw new \RuntimeException("Rechnung ({$rawContext}) hat kein (gültiges) Rechnungsdatum.");
        }

        $accountKey = $this->normalizeReferenceKey((string) ($data['Account'] ?? ''), 'Kundenaccount-');
        $account = $invoiceIssuerAccountsByKey[$issuerKey][$accountKey] ?? null;

        $context = trim((string) ($data['Beschreibung'] ?? '')) !== ''
            ? "\"{$data['Beschreibung']}\" (Rechnungssteller-ID {$issuer->id}, {$invoiceDate})"
            : "Rechnungssteller-ID {$issuer->id}, {$invoiceDate}";

        $rawZuordnung = $data['Zuordnung'] ?? null;
        $zuordnung = is_array($rawZuordnung) ? $rawZuordnung : null;

        // "Intervall-*" auf einer Rechnung (Konzept.md "### Rechnungen"): ändert Betrag
        // und/oder Zuordnung ab einem bestimmten Datum, ohne die Wiederholung selbst zu
        // unterbrechen – z. B. eine Lohnerhöhung innerhalb desselben Arbeitsvertrags.
        // [] wenn die Rechnung das gar nicht verwendet (der ganz überwiegende Regelfall).
        $costIntervals = $this->resolveCostIntervals($data, $zuordnung, $context);

        // Ohne Intervalle: der normale, direkt auf der Rechnung stehende Betrag. Mit
        // Intervallen steht dort keiner (siehe resolveCostIntervals()) – der erste
        // Intervall-Betrag dient stattdessen als der nominale Rechnungsbetrag. Ein
        // fehlender/leerer Betrag fiel früher stillschweigend auf 0,00 € zurück – die
        // Rechnung wurde dadurch trotzdem angelegt (inkl. jeder wiederkehrenden
        // Periode), nur eben dauerhaft mit Betrag 0 und ohne jede Buchung, ohne jede
        // Fehlermeldung.
        if ($costIntervals === []) {
            $parsedAmount = $this->parseDecimal($data['Betrag'] ?? null);

            if ($parsedAmount === null) {
                throw new \RuntimeException("Rechnung ({$context}) hat keinen (gültigen) Betrag.");
            }

            $amount = $parsedAmount;
        } else {
            $amount = $costIntervals[0]['betrag'];
        }

        // "Betragsüberprüfung" (default true when absent) is the negation of
        // "disable_amount_cap": true means the Zuordnung's Betrag-*/Kategorie-* split
        // must sum to the wirksamer Rechnungsbetrag (enforced below in
        // seedCostAllocations()/seedIntervalCostAllocations()), same as the live
        // Oberfläche's CostSumWithinInvoiceAmount rule reuses this very column for
        // manually-added Kosten.
        $disableAmountCap = ! (bool) ($data['Betragsüberprüfung'] ?? true);

        // Beschreibung ist Teil des "schon vorhanden"-Abgleichs: zwei rechnerisch
        // identische, aber inhaltlich verschiedene Rechnungen (gleicher Rechnungssteller/
        // gleiche Zuordnung/gleiches Datum/gleicher Betrag, z. B. "EC-Kartengebühr
        // Anna" und "... Bert" derselben Bank am selben Tag über denselben Betrag)
        // würden sonst beim Seeden zu EINER Rechnung verschmelzen – die zweite fände
        // "sich selbst" bereits vorhanden (die erste) und ihre eigenen Kosten würden nie
        // erzeugt, ohne jede Fehlermeldung.
        $description = $data['Beschreibung'] ?? null;

        $invoice = Invoice::where('invoice_issuer_id', $issuer->id)
            ->where('costable_type', $costable->getMorphClass())
            ->where('costable_id', $costable->getKey())
            ->whereDate('invoice_date', $invoiceDate)
            ->where('amount', $amount)
            ->where(fn ($q) => $description === null ? $q->whereNull('description') : $q->where('description', $description))
            ->first();

        if ($invoice === null) {
            $invoiceNumber = trim((string) ($data['Rechnungsnummer'] ?? ''));

            $invoice = Invoice::create([
                'invoice_issuer_id' => $issuer->id,
                'invoice_issuer_account_id' => $account?->id,
                'costable_type' => $costable->getMorphClass(),
                'costable_id' => $costable->getKey(),
                'invoice_date' => $invoiceDate,
                'booking_date' => $this->parseDate($data['Buchungsdatum'] ?? null),
                'invoice_number' => $invoiceNumber !== '' ? $invoiceNumber : null,
                'amount' => $amount,
                'description' => $data['Beschreibung'] ?? null,
                'periodicity' => $this->mapPeriodicity($data['Wiederholung'] ?? null),
                'disable_amount_cap' => $disableAmountCap,
            ]);
        }

        $this->attachReceipt($invoice, $data['Rechnung'] ?? null, $context);

        $isOnce = $invoice->periodicity === Invoice::PERIODICITY_ONCE;

        // Immer zuerst die normale, lückenlose Monatsprognose aus Buchungsdatum +
        // Wiederholung erzeugen ("Prognostizierte Ausgaben" sollen für jeden Monat einen
        // Wert zeigen, unabhängig davon, ob für diesen Monat bereits ein exaktes
        // Buchungsdatum bekannt ist). Explizite "Buchungen"/"Buchung-*"-Termine
        // aktualisieren die betroffenen Monate anschließend mit den echten Werten, statt
        // sie zu ersetzen – siehe seedExplicitBookings()/seedExplicitBuchungEntries().
        // Costs are booked on the Buchungsdatum (booking date), not the Rechnungsdatum: an
        // invoice dated in one year can still be booked/recognized in the next, and the
        // Ausgabenübersicht groups costs by the year of this booking date. This same anchor
        // also seeds the positional period_date for "Buchungen"/"Buchung-*" entries below.
        //
        // "Anfang" (nur bei periodischen Rechnungen sinnvoll, siehe Konzept.md
        // "### Rechnungen") überschreibt diesen Anker explizit, wenn die Periodizität
        // selbst zu einem anderen Datum beginnt als Rechnungs-/Buchungsdatum – z. B. ein
        // Kredit, dessen Rechnungsdatum nur das Vertragsdatum ist, dessen Ratenzahlungen
        // aber erst Monate später anlaufen. Mit "Intervall-*" entfallen "Anfang"/"Ende"
        // auf der Rechnung selbst (resolveCostIntervals() erzwingt das) – der Anker ist
        // dann das erste Intervall, das Ende der letzten Wiederholung dessen letztes.
        if ($costIntervals !== []) {
            $periodDate = $costIntervals[0]['anfang'];
            $endDate = end($costIntervals)['ende'];
        } else {
            $periodDate = $this->parseDate($data['Anfang'] ?? null)
                ?? $invoice->booking_date?->toDateString()
                ?? $invoiceDate;
            $endDate = $this->parseDate($data['Ende'] ?? null);
        }

        if ($costIntervals !== []) {
            $this->seedIntervalCostAllocations($costIntervals, $zuordnung, $invoice, $costable, $disableAmountCap, $context);
        } elseif ($zuordnung !== null) {
            $this->seedCostAllocations($zuordnung, $invoice, $costable, $periodDate, $endDate, $disableAmountCap, $context);
        }

        // "Der Knoten 'Buchungen' ist ungültig, wenn es eine einmalige Rechnung ist" – gilt
        // ebenso für "Buchung-*" (beide stehen für mehrere Vorkommen, die eine einmalige
        // Rechnung per Definition nicht hat).
        $rawBuchungen = $data['Buchungen'] ?? null;

        // "Buchungen: 3.1.2026" (ein einzelner Wert statt "Buchungen:\n  - 3.1.2026")
        // ist ein leicht zu übersehener YAML-Formatfehler – geparst als bloßer String
        // statt als Liste, würde er sonst genauso wie ein ganz fehlendes "Buchungen"
        // aussehen und die Rechnung dauerhaft unbestätigt ("offen") stehen lassen,
        // ohne dass die eigentlich vorhandenen Buchungstermine je angewendet werden.
        if (! $isOnce && $rawBuchungen !== null && ! is_array($rawBuchungen)) {
            throw new \RuntimeException(
                "Rechnung {$context}: 'Buchungen' muss eine Liste von Terminen sein (\"- Datum\" je Zeile), nicht ein einzelner Wert (\"{$rawBuchungen}\")."
            );
        }

        $buchungen = (! $isOnce && is_array($rawBuchungen)) ? $rawBuchungen : null;
        $buchungEntries = $isOnce ? [] : $this->entriesMatchingWithKeys($data, 'Buchung');

        // "Innerhalb einer Rechnung dürfen nicht unterschiedliche Buchungsschemas gemixt
        // werden" (Konzept.md "### Software-Test"): beide gleichzeitig sind ungültig.
        if ($buchungen !== null && $buchungEntries !== []) {
            throw new \RuntimeException("Rechnung {$context}: 'Buchungen' und 'Buchung-*' dürfen nicht gleichzeitig verwendet werden.");
        }

        if ($buchungen !== null) {
            $this->seedExplicitBookings($buchungen, $zuordnung, $invoice, $costable, $periodDate, $costIntervals, $context);
        } elseif ($buchungEntries !== []) {
            $this->seedExplicitBuchungEntries($buchungEntries, $zuordnung, $invoice, $costable, $periodDate, $costIntervals, $context);
        }
    }

    /**
     * "Rechnung" (Konzept.md "## Testdaten"): relativer Pfad zu einer Datei, die
     * hochgeladen und der Rechnung als Beleg zugeordnet wird – aufgelöst relativ zum
     * Projekt oder zum Ordner der Seed-Datei (resolveLocalAssetPath()). Dieselbe Logik
     * wie beim Hochladen im Web und über die API (InvoiceReceipt); ein erneuter Lauf mit
     * derselben Datei ändert nichts. Eine fehlende Datei oder ein unzulässiger Dateityp
     * bricht das Seeden mit einer Meldung ab.
     */
    private function attachReceipt(Invoice $invoice, mixed $reference, string $context): void
    {
        $reference = trim((string) ($reference ?? ''));

        if ($reference === '') {
            return;
        }

        try {
            app(InvoiceReceipt::class)->attachPath($invoice, $this->resolveLocalAssetPath($reference));
        } catch (\RuntimeException|\InvalidArgumentException $exception) {
            throw new \RuntimeException("Rechnung {$context}: {$exception->getMessage()}", previous: $exception);
        }
    }

    /**
     * Creates a Cost row per Betrag-N/Kategorie-N pair in a Rechnung's "Zuordnung" block.
     * Also accepts the shorthand form of a bare "Kategorie" with no number and no
     * Betrag, meaning the invoice's full amount is allocated to that one category.
     *
     * @param  array<string, mixed>  $zuordnung
     */
    private function seedCostAllocations(array $zuordnung, Invoice $invoice, Model $costable, string $periodDate, ?string $endDate, bool $disableAmountCap, string $context): void
    {
        $entries = $this->pairedEntries($zuordnung, 'Betrag', 'Kategorie');

        if ($entries === [] && ! empty($zuordnung['Kategorie'])) {
            $entries = [[$invoice->effectiveAmount(), (string) $zuordnung['Kategorie']]];
        }

        // Die Rechnung HAT eine "Zuordnung" (sonst wäre diese Methode gar nicht
        // aufgerufen worden) – ergibt sie trotzdem keine einzige [Betrag, Kategorie]-
        // Zuweisung, ist das kein bewusstes "keine Kategorisierung", sondern eine
        // fehlerhafte/unvollständige Zuordnung (z. B. Tippfehler bei den Schlüsseln).
        if ($entries === []) {
            throw new \RuntimeException("Rechnung {$context}: Die Zuordnung ergibt keine gültige [Betrag, Kategorie]-Zuweisung.");
        }

        if (! $disableAmountCap) {
            $this->assertZuordnungSumMatchesInvoiceAmount($zuordnung, $entries, $invoice);
        }

        // "Für jede Abbuchung müssen die entsprechenden Kostenpunkte angelegt werden" –
        // Termine, Abrechnungsintervalle und Prognose übernimmt der gemeinsame
        // InvoiceForecastWriter (dieselbe Logik wie die REST-API).
        $allocations = $this->costAllocations(CostableManagementGroup::of($costable), $entries, $context);
        $this->rejectInvalidSeries($context, fn () => app(InvoiceForecastWriter::class)->writeAllocations(
            $invoice, $costable, $periodDate, $endDate, $allocations, $this->billingIntervalOverride,
        ));
        app(ForecastRefresher::class)->refreshInvoice($invoice);
    }

    /**
     * Eine fachlich unstimmige Terminfolge (z. B. Ende vor Beginn) bricht das Seeden mit
     * einer Meldung ab, die die Rechnung nennt – wie die REST-API sie ablehnt.
     */
    private function rejectInvalidSeries(string $context, callable $write): void
    {
        try {
            $write();
        } catch (InvalidSeriesException $exception) {
            throw new \RuntimeException("Rechnung {$context}: {$exception->getMessage()}", previous: $exception);
        }
    }

    /**
     * Löst [Betrag, Kategorie(, Abrechnungsintervall)]-Einträge in CostAllocations auf –
     * nur gegen Kategorien, die diese Verwaltungsgruppe sehen darf.
     *
     * @param  array<int, array{0: float, 1: string, 2?: ?BillingInterval}>  $entries
     * @return list<CostAllocation>
     */
    private function costAllocations(ManagementGroup $managementGroup, array $entries, string $context): array
    {
        $allocations = [];

        foreach ($entries as $entry) {
            $category = $this->resolveCostCategoryForSeeding($managementGroup, $entry[1]);

            if ($category === null) {
                throw new \RuntimeException("Rechnung {$context}: unbekannte Kategorie \"{$entry[1]}\".");
            }

            $allocations[] = new CostAllocation((float) $entry[0], $category, $entry[2] ?? null);
        }

        return $allocations;
    }

    /**
     * Parses+validates a Rechnung's "Intervall-*" entries (Konzept.md "### Rechnungen":
     * "Ähnlich wie bei einen Mietvertrag können auch Intervalle definiert werden ...
     * Als Beispiel wäre hier ein Arbeitsvertrag zu nennen. So kann bei dem selben
     * Arbeitsvertrag eine Lohnerhöhung gezahlt werden.") – lets a recurring Rechnung's
     * Betrag and/or Kategorie-Zuordnung change at defined points in time without
     * interrupting its Wiederholung. Returns [] when the Rechnung doesn't use this at
     * all (the overwhelming common case), sorted chronologically by suffix otherwise.
     *
     * Enforced rules, all "Fehler beim Seeden" (never silently guessed):
     * - only for wiederkehrende Rechnungen (weekly…yearly – not "einmalig"/"händisch",
     *   which either have a single occurrence or their own "Buchung-*" mechanism for a
     *   changing Betrag already).
     * - "Wenn ein Intervall angegeben ist, darf kein Betrag innerhalb der Rechnung
     *   angegeben sein" – and likewise "Anfang"/"Ende" entfallen (derived from the
     *   first/last Intervall instead).
     * - every Intervall needs its own "Anfang" and "Betrag"; "Ende" is optional but
     *   only for the last one (an "offenes" Intervall followed by another is
     *   undefined).
     * - "Die Intervalle dürfen nicht lückend sein" – enforced as exact adjacency
     *   (Intervall[n+1].Anfang == Intervall[n].Ende + 1 Tag), like Mietintervalle.
     * - "Wenn in der Rechnung eine Zuordnung angegeben ist darf im Intervall keine
     *   Zuordnung erfolgen und umgekehrt" – and either every Intervall has its own, or
     *   none do (then the Rechnung's own Zuordnung applies to all of them uniformly).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $topLevelZuordnung
     * @return array<int, array{anfang: string, ende: ?string, betrag: float, notiz: ?string, zuordnung: ?array<string, mixed>}>
     */
    private function resolveCostIntervals(array $data, ?array $topLevelZuordnung, string $context): array
    {
        $entries = $this->entriesMatchingWithKeys($data, 'Intervall');

        if ($entries === []) {
            return [];
        }

        uksort($entries, fn ($a, $b) => (int) $a <=> (int) $b);

        if (array_key_exists('Betrag', $data)) {
            throw new \RuntimeException("Rechnung {$context}: Wenn Intervall-* verwendet wird, darf kein Betrag auf der Rechnung selbst stehen.");
        }

        if (array_key_exists('Anfang', $data) || array_key_exists('Ende', $data)) {
            throw new \RuntimeException("Rechnung {$context}: Anfang/Ende entfallen, wenn Intervall-* verwendet wird – sie ergeben sich aus dem ersten/letzten Intervall.");
        }

        $periodicRecurrences = [
            Invoice::PERIODICITY_WEEKLY, Invoice::PERIODICITY_BIWEEKLY, Invoice::PERIODICITY_MONTHLY,
            Invoice::PERIODICITY_QUARTERLY, Invoice::PERIODICITY_YEARLY,
        ];
        if (! in_array($this->mapPeriodicity($data['Wiederholung'] ?? null), $periodicRecurrences, true)) {
            throw new \RuntimeException("Rechnung {$context}: Intervall-* ist nur bei wiederkehrender Wiederholung erlaubt (nicht \"einmalig\"/\"händisch\").");
        }

        $parsed = [];
        $lastKey = array_key_last($entries);

        foreach ($entries as $key => $intervalData) {
            $anfang = $this->parseDate($intervalData['Anfang'] ?? null);
            $betrag = $this->parseDecimal($intervalData['Betrag'] ?? null);

            if ($anfang === null || $betrag === null) {
                throw new \RuntimeException("Intervall-{$key} der Rechnung {$context}: Anfang und Betrag sind Pflichtangaben.");
            }

            $ende = $this->parseDate($intervalData['Ende'] ?? null);

            if ($ende === null && $key !== $lastKey) {
                throw new \RuntimeException("Intervall-{$key} der Rechnung {$context}: nur das letzte Intervall darf ohne Ende (offen) sein.");
            }

            $rawIntervalZuordnung = $intervalData['Zuordnung'] ?? null;
            $intervalZuordnung = is_array($rawIntervalZuordnung) ? $rawIntervalZuordnung : null;

            if ($intervalZuordnung !== null && $topLevelZuordnung !== null) {
                throw new \RuntimeException("Intervall-{$key} der Rechnung {$context}: Wenn die Rechnung selbst eine Zuordnung hat, darf kein Intervall eine eigene haben (und umgekehrt).");
            }

            $parsed[] = [
                'anfang' => $anfang,
                'ende' => $ende,
                'betrag' => $betrag,
                'notiz' => $intervalData['Notiz'] ?? null,
                'zuordnung' => $intervalZuordnung,
            ];
        }

        for ($i = 1, $count = count($parsed); $i < $count; $i++) {
            $expectedStart = Carbon::parse($parsed[$i - 1]['ende'])->addDay()->toDateString();

            if ($parsed[$i]['anfang'] !== $expectedStart) {
                throw new \RuntimeException(
                    "Intervalle der Rechnung {$context} sind lückend/überlappend: ein Intervall endet am ".
                    "{$parsed[$i - 1]['ende']}, das nächste beginnt aber erst am {$parsed[$i]['anfang']} ".
                    "(erwartet: {$expectedStart})."
                );
            }
        }

        $withOwnZuordnung = collect($parsed)->filter(fn (array $i) => $i['zuordnung'] !== null)->count();

        if ($withOwnZuordnung > 0 && $withOwnZuordnung < count($parsed)) {
            throw new \RuntimeException("Intervalle der Rechnung {$context}: entweder hat jedes Intervall eine eigene Zuordnung, oder keins.");
        }

        if ($withOwnZuordnung === 0 && $topLevelZuordnung === null) {
            throw new \RuntimeException("Rechnung {$context} mit Intervall-*: es muss entweder auf der Rechnung selbst oder in jedem Intervall eine Zuordnung geben.");
        }

        return $parsed;
    }

    /**
     * Like seedCostAllocations(), but for a Rechnung using "Intervall-*": the same
     * single, continuous occurrence sequence is generated for the whole span (anchored
     * at the first Intervall's "Anfang", one continuous OccurrenceSchedule – so the
     * day-of-month cadence never resets at an Intervall boundary), but each
     * occurrence's Betrag/Kategorie-Zuordnung (and Notiz) comes from whichever
     * Intervall it actually falls into.
     *
     * @param  array<int, array{anfang: string, ende: ?string, betrag: float, notiz: ?string, zuordnung: ?array<string, mixed>}>  $costIntervals
     * @param  array<string, mixed>|null  $topLevelZuordnung
     */
    private function seedIntervalCostAllocations(
        array $costIntervals,
        ?array $topLevelZuordnung,
        Invoice $invoice,
        Model $costable,
        bool $disableAmountCap,
        string $context
    ): void {
        $managementGroup = CostableManagementGroup::of($costable);
        $segments = [];

        foreach ($costIntervals as $i => $interval) {
            // Guaranteed non-null by resolveCostIntervals(): either every Intervall has
            // its own Zuordnung, or none do and the Rechnung's own applies to all.
            $zuordnungForInterval = $interval['zuordnung'] ?? $topLevelZuordnung;
            $entries = $this->pairedEntries($zuordnungForInterval, 'Betrag', 'Kategorie');

            if ($entries === [] && ! empty($zuordnungForInterval['Kategorie'])) {
                $entries = [[$interval['betrag'], (string) $zuordnungForInterval['Kategorie']]];
            }

            if ($entries === []) {
                throw new \RuntimeException("Intervall-{$i} der Rechnung {$context}: Die Zuordnung ergibt keine gültige [Betrag, Kategorie]-Zuweisung.");
            }

            if (! $disableAmountCap) {
                $this->assertIntervalZuordnungSumMatchesAmount(
                    $zuordnungForInterval, $entries, $interval['betrag'], "Intervall-{$i} der Rechnung {$context}"
                );
            }

            $segments[] = new CostSegment(
                $interval['anfang'],
                $interval['ende'],
                $interval['betrag'],
                $interval['notiz'],
                $this->costAllocations($managementGroup, $entries, "{$context}, Intervall-{$i}"),
            );
        }

        // Dieselbe Intervall-Regel wie seedCostAllocations(), siehe InvoiceForecastWriter.
        $this->rejectInvalidSeries($context, fn () => app(InvoiceForecastWriter::class)->writeSegments(
            $invoice, $costable, $segments, $this->billingIntervalOverride,
        ));
    }

    /**
     * The parsed cost-Intervall (see resolveCostIntervals()) whose [Anfang, Ende] range
     * covers $date – null Ende means "offen" (the last, ongoing Intervall covers every
     * later date too).
     *
     * @param  array<int, array{anfang: string, ende: ?string}>  $costIntervals
     * @return array<string, mixed>|null
     */
    private function costIntervalCovering(array $costIntervals, string $date): ?array
    {
        foreach ($costIntervals as $interval) {
            if ($date >= $interval['anfang'] && ($interval['ende'] === null || $date <= $interval['ende'])) {
                return $interval;
            }
        }

        return null;
    }

    /**
     * Like assertZuordnungSumMatchesInvoiceAmount(), but checked against a single
     * Intervall's own Betrag instead of the Rechnung's overall (nominal, only-ever-the-
     * first-Intervall's) amount.
     *
     * @param  array<string, mixed>  $zuordnung
     * @param  array<int, array{0: float, 1: string}>  $entries
     */
    private function assertIntervalZuordnungSumMatchesAmount(array $zuordnung, array $entries, float $expectedAmount, string $context): void
    {
        $hasExplicitSplit = collect(array_keys($zuordnung))
            ->contains(fn ($key) => is_string($key) && str_starts_with($key, 'Betrag-'));

        if (! $hasExplicitSplit) {
            return;
        }

        $sum = round(array_sum(array_column($entries, 0)), 2);
        $expected = round($expectedAmount, 2);

        if ($sum !== $expected) {
            throw new \RuntimeException(sprintf(
                'Zuordnung-Summe (%s) des %s stimmt nicht mit dessen Betrag überein (%s). Mit "Betragsüberprüfung: false" auf der Rechnung deaktivierbar.',
                number_format($sum, 2, ',', '.'),
                $context,
                number_format($expected, 2, ',', '.')
            ));
        }
    }

    /**
     * "Betragsüberprüfung" (Konzept.md "### Rechnungen"): when a Zuordnung actually
     * attempts a quantified split (at least one "Betrag-*" given, as opposed to a bare
     * "Kategorie" shorthand or "Kategorie-*" entries deliberately left without their own
     * Betrag – see pairedEntries()'s "still book with amount 0" fallback, meant for splits
     * that are filled in later via "Buchung-*"), its Beträge must sum to exactly the
     * wirksamer Rechnungsbetrag. Guards against a typo'd/incomplete split silently
     * under- or over-allocating the invoice instead of throwing during seeding – disable
     * per-Rechnung via "Betragsüberprüfung: false" for a Kredit whose actual per-Buchung
     * Beträge legitimately never match its own nominal "Betrag" (e.g. a variable-rate
     * loan booked entirely under one Kategorie via Buchung-* overrides).
     *
     * @param  array<string, mixed>  $zuordnung
     * @param  array<int, array{0: float, 1: string}>  $entries
     */
    private function assertZuordnungSumMatchesInvoiceAmount(array $zuordnung, array $entries, Invoice $invoice): void
    {
        $hasExplicitSplit = collect(array_keys($zuordnung))
            ->contains(fn ($key) => is_string($key) && str_starts_with($key, 'Betrag-'));

        if (! $hasExplicitSplit) {
            return;
        }

        $sum = round(array_sum(array_column($entries, 0)), 2);
        $expected = round($invoice->effectiveAmount(), 2);

        if ($sum !== $expected) {
            throw new \RuntimeException(sprintf(
                'Zuordnung-Summe (%s) der Rechnung "%s" (Rechnungssteller-ID %d, Betrag %s) stimmt nicht mit dem wirksamen Rechnungsbetrag überein. Mit "Betragsüberprüfung: false" auf der Rechnung deaktivierbar.',
                number_format($sum, 2, ',', '.'),
                $invoice->description ?? '',
                $invoice->invoice_issuer_id,
                number_format($expected, 2, ',', '.')
            ));
        }
    }

    /**
     * Upgrades Cost rows for a recurring Rechnung's explicit "Buchungen" list: one Cost per
     * listed date, using the invoice's Zuordnung (if any) applied identically at every
     * date. Unlike the usual auto-generated occurrences above (which start "offen"/
     * unbooked since each must be confirmed individually), these dates represent bookings
     * that have already actually happened, so the resulting Cost rows end up already
     * booked (booking_date = the given date itself) – see upsertExplicitCostRows().
     *
     * Each date is matched to whichever occurrence it lies calendar-nearest to
     * (nearestOccurrenceIndex()) – exactly like a Mietvertrag's own "Zahlungen" pick their
     * rent payment's month (rentPaymentPeriodForDate()), rather than by the entry's mere
     * *position* in the YAML list. Positional matching (entry N = the invoice's Nth
     * occurrence) used to be the rule here specifically to tolerate a bank booking an
     * installment a few days before/after its "real" period – but it silently assumes the
     * list has no gaps: a single missing real-world booking (bank statement never entered)
     * permanently shifts every following entry by one whole period, with nothing to
     * detect or self-correct it. Calendar-nearest matching handles the same day-jitter
     * (an installment paid a few days early/late still resolves to its intended
     * occurrence, whichever is closer) while also recovering on its own once a Jahresdatei
     * has a gap, instead of compounding it forward forever.
     *
     * $costIntervals (see resolveCostIntervals()), when the Rechnung uses "Intervall-*",
     * makes each date's [Betrag, Kategorie] come from whichever Intervall actually
     * covers it, instead of the Rechnung's single invoice-wide Zuordnung – otherwise
     * every real booking would silently take on the FIRST Intervall's Betrag/Kategorie
     * forever (Invoice.amount/$zuordnung are only ever that first Intervall's), no
     * matter how much later the booking's own date actually falls.
     *
     * @param  array<int, mixed>  $buchungen
     * @param  array<string, mixed>|null  $zuordnung
     * @param  array<int, array{anfang: string, ende: ?string, betrag: float, notiz: ?string, zuordnung: ?array<string, mixed>}>  $costIntervals
     */
    private function seedExplicitBookings(array $buchungen, ?array $zuordnung, Invoice $invoice, Model $costable, string $anchorDate, array $costIntervals, string $context): void
    {
        $managementGroup = CostableManagementGroup::of($costable);
        $recorder = app(InvoiceBookingRecorder::class);

        foreach (array_values($buchungen) as $rawDate) {
            $date = $this->parseDate($rawDate);

            if ($date === null) {
                throw new \RuntimeException("Rechnung {$context}: ein Eintrag in 'Buchungen' ist kein gültiges Datum (\"{$rawDate}\").");
            }

            $entries = $this->resolveExplicitBookingEntries($zuordnung, $costIntervals, $date, $invoice, $context);

            // Weder rechnungsweit noch – bei Intervall-* – je Intervall überhaupt eine
            // Zuordnung vorhanden: eine gültige, bewusste Konfiguration (eine Rechnung
            // ohne jede Kategorisierung), keine unvollständige Angabe – die Buchung
            // bleibt dann bewusst ohne Kostenzeile (siehe resolveExplicitBookingEntries()).
            if ($entries === []) {
                continue;
            }

            $recorder->record(
                $invoice, $costable, $anchorDate, $date,
                $this->costAllocations($managementGroup, $entries, "{$context}, Buchung vom {$date}"),
                $this->billingIntervalOverride,
            );
        }

        // Die Prognose der offenen Termine (ForecastRefresher) würde einen bereits exakt
        // bekannten Intervall-Betrag überschreiben – bei Intervall-* daher nicht.
        if ($costIntervals === []) {
            app(ForecastRefresher::class)->refreshInvoice($invoice);
        }
    }

    /**
     * Resolves the [Betrag, Kategorie] entries an explicit real booking on $date should
     * use: for a Rechnung using cost-Intervalle, whichever Intervall covers $date (its
     * own Zuordnung, falling back to the Rechnung's shared one – exactly like
     * seedIntervalCostAllocations()); otherwise the Rechnung's single, invoice-wide
     * Zuordnung, unchanged from before "Intervall-*" existed.
     *
     * Returns [] ONLY for the one deliberate, tested case of no Zuordnung at all
     * (weder rechnungsweit noch je Intervall) – every other way of failing to resolve
     * a category (a malformed Zuordnung, or a Buchung dated outside every Intervall)
     * is an error, not a silent gap.
     *
     * @param  array<string, mixed>|null  $zuordnung
     * @param  array<int, array{anfang: string, ende: ?string, betrag: float, notiz: ?string, zuordnung: ?array<string, mixed>}>  $costIntervals
     * @return array<int, array{0: float, 1: string}>
     */
    private function resolveExplicitBookingEntries(?array $zuordnung, array $costIntervals, string $date, Invoice $invoice, string $context): array
    {
        if ($costIntervals !== []) {
            $interval = $this->costIntervalCovering($costIntervals, $date);

            if ($interval === null) {
                throw new \RuntimeException("Rechnung {$context}: Die Buchung vom {$date} liegt außerhalb aller Intervalle.");
            }

            $zuordnungForInterval = $interval['zuordnung'] ?? $zuordnung;
            $entries = $this->pairedEntries($zuordnungForInterval, 'Betrag', 'Kategorie');

            if ($entries === [] && ! empty($zuordnungForInterval['Kategorie'] ?? null)) {
                $entries = [[$interval['betrag'], (string) $zuordnungForInterval['Kategorie']]];
            }

            if ($entries === []) {
                throw new \RuntimeException(
                    "Rechnung {$context}: Die Zuordnung des Intervalls, das die Buchung vom {$date} abdeckt, ergibt keine gültige Kategorie."
                );
            }

            return $entries;
        }

        // Weder rechnungsweit noch je Intervall (s. o.) eine Zuordnung überhaupt: eine
        // gültige, bewusste Konfiguration (eine Rechnung ohne jede Kategorisierung) –
        // siehe Fallstrick "seeddata.yaml ist alleinige Quelle für die Zuordnung einer
        // Rechnung", getestet in test_buchungen_without_a_zuordnung_creates_no_costs().
        if ($zuordnung === null) {
            return [];
        }

        $entries = $this->pairedEntries($zuordnung, 'Betrag', 'Kategorie');

        if ($entries === [] && ! empty($zuordnung['Kategorie'] ?? null)) {
            $entries = [[$invoice->effectiveAmount(), (string) $zuordnung['Kategorie']]];
        }

        if ($entries === []) {
            throw new \RuntimeException("Rechnung {$context}: Die Zuordnung ergibt keine gültige Kategorie für die Buchung vom {$date}.");
        }

        return $entries;
    }

    /**
     * Upgrades Cost rows for a recurring Rechnung's explicit "Buchung-*" entries: one entry
     * per actual booking date, each with its own Betrag(-n) (e.g. a Kredit's im Zeitverlauf
     * wechselnde Zins-/Tilgungsaufteilung). Booked (not "offen"), like
     * seedExplicitBookings() – and, like it, matched to whichever occurrence its own
     * "Datum" lies calendar-nearest to (nearestOccurrenceIndex()), not by its bare
     * position in the YAML list; see seedExplicitBookings()'s docblock for why.
     *
     * @param  array<string, array<string, mixed>>  $buchungEntries
     * @param  array<string, mixed>|null  $zuordnung
     */
    private function seedExplicitBuchungEntries(array $buchungEntries, ?array $zuordnung, Invoice $invoice, Model $costable, string $anchorDate, array $costIntervals, string $context): void
    {
        $managementGroup = CostableManagementGroup::of($costable);
        $recorder = app(InvoiceBookingRecorder::class);

        foreach ($buchungEntries as $suffix => $buchungData) {
            $date = $this->parseDate($buchungData['Datum'] ?? null);

            if ($date === null) {
                throw new \RuntimeException("Rechnung {$context}: 'Buchung-{$suffix}' hat kein gültiges Datum.");
            }

            $entries = $this->buchungAllocationEntries($buchungData, $zuordnung, "{$context}, 'Buchung-{$suffix}'");

            if ($entries === []) {
                throw new \RuntimeException("Rechnung {$context}: 'Buchung-{$suffix}' (Datum {$date}) ergibt keine gültige [Betrag, Kategorie]-Zuweisung.");
            }

            $recorder->record(
                $invoice, $costable, $anchorDate, $date,
                $this->costAllocations($managementGroup, $entries, "{$context}, 'Buchung-{$suffix}'"),
                $this->billingIntervalOverride,
            );
        }

        // Siehe seedExplicitBookings(): ein Intervall-Betrag ist bereits exakt bekannt.
        if ($costIntervals === []) {
            app(ForecastRefresher::class)->refreshInvoice($invoice);
        }
    }

    /**
     * Resolves a Rechnung-Zuordnung's Kategorie name to a CostCategory this
     * ManagementGroup can actually see (Konzept.md/CostCategory::scopeVisibleTo(): the
     * systemweiten Kategorien aus CostCategorySeeder, plus welche, die dem Account
     * dieser Verwaltungsgruppe selbst gehören) – nicht per bloßem Namensabgleich über
     * alle Accounts hinweg, sonst könnte ein anderer Account zufällig gleichnamige
     * eigene Kategorie hier fälschlich landen. Null, wenn keine solche existiert (der
     * Aufrufer wirft dann statt den Eintrag stillschweigend zu überspringen).
     */
    private function resolveCostCategoryForSeeding(ManagementGroup $managementGroup, string $categoryName): ?CostCategory
    {
        return CostCategory::where('name', trim($categoryName))
            ->where(fn ($q) => $q->whereNull('user_group_id')->orWhere('user_group_id', $managementGroup->user_group_id))
            ->first();
    }

    /**
     * Resolves the [Betrag, Kategorie, Abrechnungsintervall] pairs for a single
     * "Buchung-*" entry. Either multiple "Betrag-n"/"[Kategorie-n]" pairs (Kategorie-n
     * optional, inherited positionally from "Zuordnung.Kategorie-n" of the same suffix
     * when omitted), or a bare "Betrag" applying to the Rechnung's single
     * Zuordnung-Kategorie. Each "Betrag-n" pair may additionally carry its own
     * "Abrechnungsintervall-n" (Konzept.md "### Rechnung": "Es muss möglich sein eine
     * Rechnung zu splitten. Das Splitten muss auch auf unterschliedliche
     * Abrechnungsintervalle sein.") – pins JUST that one Split-Eintrag to a specific
     * Intervall, independent of the date-based/rechnungsweiten Auflösung the others use.
     * Absent for the bare-"Betrag" case, since there is only ever one entry there.
     *
     * @param  array<string, mixed>  $buchungData
     * @param  array<string, mixed>|null  $zuordnung
     * @return array<int, array{0: float, 1: string, 2: ?BillingInterval}>
     */
    private function buchungAllocationEntries(array $buchungData, ?array $zuordnung, string $context): array
    {
        $zuordnungCategories = $this->positionalCategories($zuordnung);
        $pairs = [];

        foreach ($buchungData as $key => $value) {
            if (! is_string($key) || ! str_starts_with($key, 'Betrag-') || is_array($value)) {
                continue;
            }

            $suffix = substr($key, strlen('Betrag-'));
            $amount = $this->parseDecimal($value);
            // A lone "Betrag-1" (no "Betrag-2" sibling) commonly pairs with the Rechnung's
            // single, unsuffixed "Zuordnung.Kategorie" rather than a matching
            // "Kategorie-1" – falls back to singleZuordnungCategory() (the same helper the
            // bare-"Betrag" case below uses) instead of silently dropping the entry.
            $category = $buchungData["Kategorie-{$suffix}"] ?? $zuordnungCategories[$suffix] ?? $this->singleZuordnungCategory($zuordnung);
            $intervalRef = trim((string) ($buchungData["Abrechnungsintervall-{$suffix}"] ?? ''));
            $billingInterval = $intervalRef !== '' ? $this->resolveNamedBillingIntervalForSeeding($intervalRef, $context) : null;

            if ($amount !== null && $category !== null && $category !== '') {
                $pairs[] = [$amount, (string) $category, $billingInterval];
            }
        }

        if ($pairs !== []) {
            return $pairs;
        }

        if (isset($buchungData['Betrag']) && ! is_array($buchungData['Betrag'])) {
            $category = $this->singleZuordnungCategory($zuordnung);
            $amount = $this->parseDecimal($buchungData['Betrag']);

            if ($category !== null && $amount !== null) {
                return [[$amount, $category]];
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>|null  $zuordnung
     * @return array<string, string> Kategorie-n Werte, indiziert nach ihrem Zahlen-Suffix.
     */
    private function positionalCategories(?array $zuordnung): array
    {
        $categories = [];

        foreach ($zuordnung ?? [] as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'Kategorie-') && ! is_array($value) && $value !== '') {
                $categories[substr($key, strlen('Kategorie-'))] = (string) $value;
            }
        }

        return $categories;
    }

    /**
     * Die eine Kategorie, auf die sich ein "Betrag" ohne Suffix in einem Buchung-*-Eintrag
     * bezieht: entweder die bare "Kategorie" der Zuordnung, oder deren einzige
     * "Kategorie-n", falls genau eine vorhanden ist.
     */
    private function singleZuordnungCategory(?array $zuordnung): ?string
    {
        if ($zuordnung === null) {
            return null;
        }

        if (! empty($zuordnung['Kategorie']) && ! is_array($zuordnung['Kategorie'])) {
            return (string) $zuordnung['Kategorie'];
        }

        $positional = $this->positionalCategories($zuordnung);

        return count($positional) === 1 ? reset($positional) : null;
    }

    /**
     * Resolves an explicit "Intervall-*" reference (e.g. a "Buchung-*"-Split-Eintrags
     * eigenes "Abrechnungsintervall-n", Konzept.md "### Rechnung": Rechnung-Splitting
     * über Abrechnungsintervalle hinweg) against the current invoice's Verwaltungsgruppe
     * – unlike $billingIntervalOverride (rechnungsweit, aus dem Datum/der Auflösung
     * unabhängig), dies gilt nur für den einen Split-Eintrag, der es angibt.
     */
    private function resolveNamedBillingIntervalForSeeding(string $intervalRef, string $context): BillingInterval
    {
        $intervalKey = $this->normalizeReferenceKey($intervalRef, 'Intervall-');
        $interval = $this->billingIntervalsByKeyForCurrentInvoice[$intervalKey] ?? null;

        if ($interval === null) {
            throw new \RuntimeException("Rechnung {$context}: \"Abrechnungsintervall-*\" referenziert ein unbekanntes \"{$intervalRef}\".");
        }

        return $interval;
    }
}
