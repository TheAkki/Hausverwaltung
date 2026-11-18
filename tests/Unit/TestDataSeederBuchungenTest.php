<?php

namespace Tests\Unit;

use App\Models\AccountTransfer;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\RentPayment;
use App\Models\SettlementClaim;
use Database\Seeders\CostCategorySeeder;
use Database\Seeders\TestDataSeeder;

/**
 * Covers SeedsBuchungen: the "Buchungen" block, a parallel entry point to
 * "Rechnungen" that additionally supports "Zahlungen" and "Forderung-*" on a
 * Mietvertrag, plus its own "from-File" externalization.
 */
class TestDataSeederBuchungenTest extends TestDataSeederTestCase
{
    /**
     * Indents every line of a Buchungen-YAML snippet by 4 spaces, so it nests as a
     * child of "Account-1:" once interpolated into the fixture below (heredoc
     * interpolation inserts a variable's value verbatim, it doesn't re-indent it).
     */
    private function indented(string $yaml): string
    {
        return implode("\n", array_map(
            fn ($line) => $line === '' ? '' : "    {$line}",
            explode("\n", trim($yaml))
        ));
    }

    private function seedFixture(string $buchungenYaml): Lease
    {
        $this->seed(CostCategorySeeder::class);
        $buchungenYaml = $this->indented($buchungenYaml);

        file_put_contents($this->path, <<<YAML
        lang: "de"

        Account-1:
            Name: "Buchungen User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Buchungen Gruppe"

                Intervall-0:
                    Start: 1.1.2026
                    Ende: 31.12.2026

                Verwaltungseinheit-1:
                    Straße: "Buchungsweg 1"
                    PLZ: 11111
                    Stadt: "Buchungsstadt"

                    Mieteinheit-1:
                        Typ: "Wohnung"
                        Größe: 60

                        Mietvertrag-1:
                            MieterId: Mieter-1
                            Beginn: 1.1.2026
                            Mietpreistyp: "Fix"
                            Kaltmiete: 500
                            Nebenkosten: 100

                            Intervall-0:
                                Personen: 1

            Mieter-1:
                Vorname: "Max"
                Name: "Mustermann"
                Straße: "A"
                PLZ: 1
                Ort: "B"

            Rechnungssteller-1:
                Firma: "Buchungen Versorger"
                Ort: "Buchungsstadt"

        $buchungenYaml
        YAML);

        $this->seed(TestDataSeeder::class);

        return Lease::first();
    }

    /**
     * Like seedFixture(), but additionally injects a "Rechnungen:" sibling block –
     * for testing that "Buchungen.Rechnung-N" inherits missing fields from the
     * same-keyed "Rechnungen.Rechnung-N".
     */
    private function seedFixtureWithRechnungenAndBuchungen(string $rechnungenYaml, string $buchungenYaml): Lease
    {
        $this->seed(CostCategorySeeder::class);
        $rechnungenYaml = $this->indented($rechnungenYaml);
        $buchungenYaml = $this->indented($buchungenYaml);

        file_put_contents($this->path, <<<YAML
        lang: "de"

        Account-1:
            Name: "Buchungen User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Buchungen Gruppe"

                Intervall-0:
                    Start: 1.1.2026
                    Ende: 31.12.2026

                Verwaltungseinheit-1:
                    Straße: "Buchungsweg 1"
                    PLZ: 11111
                    Stadt: "Buchungsstadt"

                    Mieteinheit-1:
                        Typ: "Wohnung"
                        Größe: 60

                        Mietvertrag-1:
                            MieterId: Mieter-1
                            Beginn: 1.1.2026
                            Mietpreistyp: "Fix"
                            Kaltmiete: 500
                            Nebenkosten: 100

                            Intervall-0:
                                Personen: 1

            Mieter-1:
                Vorname: "Max"
                Name: "Mustermann"
                Straße: "A"
                PLZ: 1
                Ort: "B"

            Rechnungssteller-1:
                Firma: "Buchungen Versorger"
                Ort: "Buchungsstadt"

        $rechnungenYaml

        $buchungenYaml
        YAML);

        $this->seed(TestDataSeeder::class);

        return Lease::first();
    }

    public function test_a_rechnung_under_buchungen_at_mietvertrag_level_is_seeded_like_under_rechnungen(): void
    {
        $lease = $this->seedFixture(<<<'YAML'
        Buchungen:
            Verwaltungsgruppe-1:
                Verwaltungseinheit-1:
                    Mieteinheit-1:
                        Mietvertrag-1:
                            Rechnung-0:
                                Rechnungssteller: Rechnungssteller-1
                                Rechnungsdatum: 2026-03-01
                                Buchungsdatum: 2026-03-01
                                Betrag: 50
                                Wiederholung: "einmalig"
                                Zuordnung:
                                    Kategorie: "Wasserversorgung"
        YAML);

        $cost = $lease->costs()->first();
        $this->assertNotNull($cost);
        $this->assertSame(50.0, (float) $cost->amount);
    }

    public function test_a_buchungen_from_file_reference_is_loaded_and_used(): void
    {
        $externalPath = $this->fixturePath('buchungen-test.yaml');
        file_put_contents($externalPath, <<<'YAML'
        Verwaltungsgruppe-1:
            Verwaltungseinheit-1:
                Mieteinheit-1:
                    Mietvertrag-1:
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 2026-03-01
                            Buchungsdatum: 2026-03-01
                            Betrag: 75
                            Wiederholung: "einmalig"
                            Zuordnung:
                                Kategorie: "Wasserversorgung"
        YAML);

        try {
            $lease = $this->seedFixture(<<<'YAML'
            Buchungen:
                from-File: "buchungen-test.yaml"
            YAML);

            $cost = $lease->costs()->first();
            $this->assertNotNull($cost);
            $this->assertSame(75.0, (float) $cost->amount);
        } finally {
            @unlink($externalPath);
        }
    }

    /**
     * "from-File" may reference several files at once (e.g. one per Jahr), sharing
     * the same Verwaltungsgruppe/-einheit/Mieteinheit/Mietvertrag nesting – their
     * contents are merged, not replaced: a "Zahlungen" list under the same
     * Mietvertrag in both files ends up with entries from both.
     */
    public function test_a_buchungen_from_file_list_merges_all_referenced_files(): void
    {
        $pathA = $this->fixturePath('buchungen-list-a-test.yaml');
        $pathB = $this->fixturePath('buchungen-list-b-test.yaml');
        file_put_contents($pathA, <<<'YAML'
        Verwaltungsgruppe-1:
            Verwaltungseinheit-1:
                Mieteinheit-1:
                    Mietvertrag-1:
                        Zahlungen:
                            - 3.1.2026
                            - 5.2.2026
        YAML);
        file_put_contents($pathB, <<<'YAML'
        Verwaltungsgruppe-1:
            Verwaltungseinheit-1:
                Mieteinheit-1:
                    Mietvertrag-1:
                        Zahlungen:
                            - 4.3.2026
        YAML);

        try {
            $lease = $this->seedFixture(<<<'YAML'
            Buchungen:
                from-File:
                    - "buchungen-list-a-test.yaml"
                    - "buchungen-list-b-test.yaml"
            YAML);

            $interval = $lease->leaseIntervals->first();
            $january = RentPayment::where('lease_interval_id', $interval->id)->whereDate('period_date', '2026-01-01')->first();
            $february = RentPayment::where('lease_interval_id', $interval->id)->whereDate('period_date', '2026-02-01')->first();
            $march = RentPayment::where('lease_interval_id', $interval->id)->whereDate('period_date', '2026-03-01')->first();

            $this->assertSame('2026-01-03', $january->booking_date->toDateString());
            $this->assertSame('2026-02-05', $february->booking_date->toDateString());
            $this->assertSame('2026-03-04', $march->booking_date->toDateString());
        } finally {
            @unlink($pathA);
            @unlink($pathB);
        }
    }

    /**
     * Two Jahresdateien commonly number a Rechnung's own "Buchung-*" entries
     * independently, each starting again at "Buchung-0"/"Buchung-1" – merging them like
     * every other key (same suffix = same entry, later file wins) would silently drop
     * whichever entries collide, losing real bookings. Each entry is matched to its own
     * calendar-nearest occurrence regardless of merge order (seedExplicitBuchungEntries()/
     * nearestOccurrenceIndex()), but that only helps if the entry itself survives the
     * merge in the first place – both files' colliding entries must be kept, concatenated
     * under fresh keys instead of overwriting one another.
     */
    public function test_colliding_buchung_suffixes_across_from_file_years_are_concatenated_not_overwritten(): void
    {
        $pathA = $this->fixturePath('buchungen-family-a-test.yaml');
        $pathB = $this->fixturePath('buchungen-family-b-test.yaml');
        file_put_contents($pathA, <<<'YAML'
        Verwaltungsgruppe-1:
            Rechnung-1:
                Buchung-0:
                    Datum: 2.1.2025
                    Betrag: 100
                Buchung-1:
                    Datum: 2.2.2025
                    Betrag: 101
        YAML);
        file_put_contents($pathB, <<<'YAML'
        Verwaltungsgruppe-1:
            Rechnung-1:
                Buchung-0:
                    Datum: 2.1.2026
                    Betrag: 200
                Buchung-1:
                    Datum: 2.2.2026
                    Betrag: 201
        YAML);

        try {
            $this->seed(CostCategorySeeder::class);

            file_put_contents($this->path, <<<'YAML'
            lang: "de"

            Account-1:
                Name: "Buchungen User"
                Passwort: "geheim123"

                Verwaltungsgruppe-1:
                    Beschreibung: "Buchungen Gruppe"

                Rechnungssteller-1:
                    Firma: "Buchungen Versorger"
                    Ort: "Buchungsstadt"

                Rechnungen:
                    Verwaltungsgruppe-1:
                        Rechnung-1:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 2.1.2025
                            Ende: 28.2.2026
                            Betrag: 100
                            Wiederholung: "monatlich"
                            Zuordnung:
                                Kategorie: "Wasserversorgung"

                Buchungen:
                    from-File:
                        - "buchungen-family-a-test.yaml"
                        - "buchungen-family-b-test.yaml"
            YAML);

            $this->seed(TestDataSeeder::class);

            $managementGroup = ManagementGroup::where('description', 'Buchungen Gruppe')->first();
            $invoice = Invoice::where('costable_type', $managementGroup->getMorphClass())
                ->where('costable_id', $managementGroup->id)->first();
            $this->assertNotNull($invoice);

            // Vier Buchungen (Jan/Feb 2025 aus Datei A, Jan/Feb 2026 aus Datei B), jede mit
            // ihrem eigenen Betrag und Buchungsdatum – keine durch die kollidierenden
            // "Buchung-0"/"Buchung-1"-Schlüssel verloren, und jede ihrem eigenen,
            // kalendarisch nächstgelegenen Vorkommen zugeordnet (nicht positional in vier
            // aufeinanderfolgende Monate gequetscht – Datei B liegt real ein Jahr nach Datei
            // A). Die dazwischenliegenden Monate (März 2025 bis Dezember 2025) bleiben
            // offene Prognosezeilen.
            $costs = $invoice->costs()->whereNotNull('booking_date')->orderBy('period_date')->get();
            $this->assertCount(4, $costs);
            $this->assertSame(
                ['2025-01-02', '2025-02-02', '2026-01-02', '2026-02-02'],
                $costs->map(fn ($c) => $c->period_date->toDateString())->all()
            );
            $this->assertSame([100.0, 101.0, 200.0, 201.0], $costs->map(fn ($c) => (float) $c->amount)->all());
            $this->assertSame(
                ['2025-01-02', '2025-02-02', '2026-01-02', '2026-02-02'],
                $costs->map(fn ($c) => $c->booking_date->toDateString())->all()
            );
        } finally {
            @unlink($pathA);
            @unlink($pathB);
        }
    }

    /**
     * Regressionstest für "Warum ist bei Rechnung-4 der 1.8.2026 noch offen? Da ist eine
     * Buchung zum 31.7." – die reale Musterbank-Rechnung hat zwischen dem letzten
     * 2023er und dem ersten 2024er "Buchung-*"-Eintrag eine Lücke von 62 Tagen statt der
     * sonst überall üblichen ~30 Tage (ein Monat wurde nie eingetragen), wodurch jede
     * spätere Buchung positional einen Monat zu früh landete. Hier nachgebildet mit einer
     * noch größeren, zwei Monate umfassenden Lücke (>60 Tage) über zwei from-File-Jahre
     * hinweg: der übersprungene Zeitraum bleibt eine offene Prognosezeile, die erste
     * Buchung danach springt auf ihr eigenes, kalendarisch nächstgelegenes Vorkommen
     * (nearestOccurrenceIndex()) statt stur an der letzten bekannten Position
     * weiterzuzählen – dieselbe Selbstheilung, die auch die reale Lücke behebt.
     */
    public function test_a_buchung_more_than_60_days_after_the_previous_one_snaps_to_its_own_true_occurrence(): void
    {
        $pathA = $this->fixturePath('buchungen-gap-a-test.yaml');
        $pathB = $this->fixturePath('buchungen-gap-b-test.yaml');
        file_put_contents($pathA, <<<'YAML'
        Verwaltungsgruppe-1:
            Rechnung-1:
                Buchung-0:
                    Datum: 2.1.2025
                    Betrag: 100
                Buchung-1:
                    Datum: 2.2.2025
                    Betrag: 101
        YAML);
        // Kein Eintrag für März oder April – die Lücke zwischen 2.2.2025 und dem nächsten
        // echten Buchungstermin (2.5.2025, Datei B) beträgt 89 Tage, deutlich über den
        // sonst üblichen ~30.
        file_put_contents($pathB, <<<'YAML'
        Verwaltungsgruppe-1:
            Rechnung-1:
                Buchung-0:
                    Datum: 2.5.2025
                    Betrag: 103
        YAML);

        try {
            $this->seed(CostCategorySeeder::class);

            file_put_contents($this->path, <<<'YAML'
            lang: "de"

            Account-1:
                Name: "Buchungen User"
                Passwort: "geheim123"

                Verwaltungsgruppe-1:
                    Beschreibung: "Buchungen Gruppe"

                Rechnungssteller-1:
                    Firma: "Buchungen Versorger"
                    Ort: "Buchungsstadt"

                Rechnungen:
                    Verwaltungsgruppe-1:
                        Rechnung-1:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 2.1.2025
                            Ende: 30.6.2025
                            Betrag: 100
                            Wiederholung: "monatlich"
                            Zuordnung:
                                Kategorie: "Wasserversorgung"

                Buchungen:
                    from-File:
                        - "buchungen-gap-a-test.yaml"
                        - "buchungen-gap-b-test.yaml"
            YAML);

            $this->seed(TestDataSeeder::class);

            $managementGroup = ManagementGroup::where('description', 'Buchungen Gruppe')->first();
            $invoice = Invoice::where('costable_type', $managementGroup->getMorphClass())
                ->where('costable_id', $managementGroup->id)->first();
            $this->assertNotNull($invoice);

            $costs = $invoice->costs()->orderBy('period_date')->get();
            $this->assertSame(
                ['2025-01-02', '2025-02-02', '2025-03-02', '2025-04-02', '2025-05-02', '2025-06-02'],
                $costs->map(fn ($c) => $c->period_date->toDateString())->all()
            );
            [$jan, $feb, $mar, $apr, $may, $jun] = $costs->all();

            $this->assertFalse($jan->isOpen());
            $this->assertSame('2025-01-02', $jan->booking_date->toDateString());
            $this->assertSame(100.0, (float) $jan->amount);

            $this->assertFalse($feb->isOpen());
            $this->assertSame('2025-02-02', $feb->booking_date->toDateString());
            $this->assertSame(101.0, (float) $feb->amount);

            // März und April wurden nie gebucht – bleiben offene Prognosezeilen statt
            // fälschlich die Mai-Buchung "vorzuziehen".
            $this->assertTrue($mar->isOpen());
            $this->assertSame(100.0, (float) $mar->amount);
            $this->assertTrue($apr->isOpen());
            $this->assertSame(100.0, (float) $apr->amount);

            // Die Mai-Buchung (89 Tage nach Februar) landet auf ihrem eigenen,
            // kalendarisch nächstgelegenen Vorkommen (Mai) – nicht positional auf März
            // (dem 3. Eintrag in Reihenfolge), wie es die alte, rein positionale
            // Zuordnung getan hätte.
            $this->assertFalse($may->isOpen());
            $this->assertSame('2025-05-02', $may->booking_date->toDateString());
            $this->assertSame(103.0, (float) $may->amount);

            // Juni liegt nach der letzten echten Buchung (Mai) und übernimmt deren Betrag
            // als Prognose, statt beim nominalen Rechnungsbetrag (100) zu bleiben.
            $this->assertTrue($jun->isOpen());
            $this->assertSame(103.0, (float) $jun->amount);
        } finally {
            @unlink($pathA);
            @unlink($pathB);
        }
    }

    /**
     * Analog zum "Buchung-*"-Fall oben ("Die Rück-/Nachzahlung 2024 von Mieter A stimmt nicht"):
     * ein Mietvertrags eigene "Forderung-*"-Einträge werden ebenfalls von jeder
     * Jahresdatei unabhängig nummeriert – zwei Dateien, die beide bei "Forderung-0"
     * für denselben Mietvertrag anfangen, dürfen sich nicht gegenseitig überschreiben.
     */
    public function test_colliding_forderung_suffixes_across_from_file_years_are_concatenated_not_overwritten(): void
    {
        $pathA = $this->fixturePath('buchungen-forderung-a-test.yaml');
        $pathB = $this->fixturePath('buchungen-forderung-b-test.yaml');
        file_put_contents($pathA, <<<'YAML'
        Verwaltungsgruppe-1:
            Verwaltungseinheit-1:
                Mieteinheit-1:
                    Mietvertrag-1:
                        Forderung-0:
                            Abrechnungsintervall: Intervall-0
                            Datum: 27.9.2024
                            Betrag: 265,75
                            Buchungsdatum: 28.10.2024
                            Notiz: "Betriebskostenabrechnung 2023"
        YAML);
        file_put_contents($pathB, <<<'YAML'
        Verwaltungsgruppe-1:
            Verwaltungseinheit-1:
                Mieteinheit-1:
                    Mietvertrag-1:
                        Forderung-0:
                            Abrechnungsintervall: Intervall-0
                            Datum: 9.10.2025
                            Betrag: 908,66
                            Buchungsdatum: 10.11.2025
                            Notiz: "Betriebskostenabrechnung 2024"
        YAML);

        try {
            $lease = $this->seedFixture(<<<'YAML'
            Buchungen:
                from-File:
                    - "buchungen-forderung-a-test.yaml"
                    - "buchungen-forderung-b-test.yaml"
            YAML);

            $claims = SettlementClaim::where('lease_id', $lease->id)->orderBy('date')->get();

            $this->assertCount(2, $claims);
            $this->assertSame(265.75, (float) $claims[0]->amount);
            $this->assertSame(908.66, (float) $claims[1]->amount);
        } finally {
            @unlink($pathA);
            @unlink($pathB);
        }
    }

    /**
     * Nutzer-Klarstellung: zwei "Rechnung-*" gleicher Nummer aus unterschiedlichen
     * from-File-Jahresdateien sind nur DANN dieselbe Rechnung (schlüsselbasiert gemerged),
     * wenn mindestens eine Seite auf die andere/auf seeddata.yaml angewiesen ist (kein
     * eigener Rechnungssteller). Definieren BEIDE Dateien ihre eigenen Stammdaten
     * (Rechnungssteller, Account, …), sind es zwei unabhängige Rechnungen, auch bei
     * gleicher Nummer – ein blindes Key-Merge hätte sonst z. B. eine "monatlich"
     * laufende Rechnung auf "einmalig" umgeschrieben und dadurch deren eigene
     * "Buchung-*"-Historie komplett zum Verschwinden gebracht (seedInvoice() liest
     * "Buchung-*" nie für eine "einmalig"-Rechnung).
     */
    public function test_two_self_contained_rechnungen_with_the_same_number_across_years_stay_separate(): void
    {
        $pathA = $this->fixturePath('buchungen-rechnung-collision-a-test.yaml');
        $pathB = $this->fixturePath('buchungen-rechnung-collision-b-test.yaml');
        file_put_contents($pathA, <<<'YAML'
        Verwaltungsgruppe-1:
            Rechnung-10:
                Rechnungssteller: Rechnungssteller-1
                Rechnungsdatum: 1.1.2022
                Betrag: 560
                Beschreibung: "Zinsen"
                Wiederholung: "monatlich"
                Zuordnung:
                    Kategorie: "Zinsen"
                Buchung-0:
                    Datum: 1.1.2022
                    Betrag: 524,45
        YAML);
        file_put_contents($pathB, <<<'YAML'
        Verwaltungsgruppe-1:
            Rechnung-10:
                Rechnungssteller: Rechnungssteller-1
                Rechnungsdatum: 31.1.2024
                Betrag: 198,85
                Beschreibung: "KFW - Zinszahlung"
                Wiederholung: "einmalig"
                Zuordnung:
                    Kategorie: "Zinsen"
        YAML);

        try {
            $this->seed(CostCategorySeeder::class);

            file_put_contents($this->path, <<<'YAML'
            lang: "de"

            Account-1:
                Name: "Buchungen User"
                Passwort: "geheim123"

                Verwaltungsgruppe-1:
                    Beschreibung: "Buchungen Gruppe"

                Rechnungssteller-1:
                    Firma: "Buchungen Versorger"
                    Ort: "Buchungsstadt"

                Buchungen:
                    from-File:
                        - "buchungen-rechnung-collision-a-test.yaml"
                        - "buchungen-rechnung-collision-b-test.yaml"
            YAML);

            $this->seed(TestDataSeeder::class);

            $managementGroup = ManagementGroup::where('description', 'Buchungen Gruppe')->first();
            $invoices = Invoice::where('costable_type', $managementGroup->getMorphClass())
                ->where('costable_id', $managementGroup->id)->get()->keyBy('description');

            // Beide Rechnungen existieren unabhängig voneinander, keine hat die Felder
            // der anderen übernommen.
            $this->assertCount(2, $invoices);
            $this->assertNotNull($invoices['Zinsen'] ?? null);
            $this->assertSame(560.0, (float) $invoices['Zinsen']->amount);
            $this->assertSame('monthly', $invoices['Zinsen']->periodicity);
            $this->assertNotNull($invoices['KFW - Zinszahlung'] ?? null);
            $this->assertSame(198.85, (float) $invoices['KFW - Zinszahlung']->amount);

            // Die "Zinsen"-Rechnung ist weiterhin "monatlich" und ihr eigener
            // Buchung-0-Termin wurde nicht durch die einmalige Rechnung verschluckt.
            $cost = $invoices['Zinsen']->costs()->whereNotNull('booking_date')->first();
            $this->assertNotNull($cost);
            $this->assertSame(524.45, (float) $cost->amount);
        } finally {
            @unlink($pathA);
            @unlink($pathB);
        }
    }

    /**
     * Nutzerreport: Grundsteuer-Buchungen aus 2025 tauchten in der Buchungsübersicht des
     * Abrechnungsintervalls 2026 auf. Ursache: fn4-buchungen-2025.yaml und
     * fn4-buchungen-2026.yaml setzten für dieselbe Rechnung (Rechnung-0, per
     * "Buchung-*"-Familie sicher zusammengeführt) je ein eigenes "Abrechnungsintervall" –
     * in der (falschen) Annahme, es gelte nur für die eigenen Buchung-*-Einträge dieser
     * Datei. Da "Abrechnungsintervall" ALLE Buchungen der Rechnung auf ein Intervall
     * pinnt, gewann beim generischen Key-Merge stillschweigend das zuletzt gemergte
     * (2026), wodurch auch die 2025er-Buchungen fälschlich dem 2026er-Intervall
     * zugeordnet wurden.
     */
    public function test_conflicting_abrechnungsintervall_for_the_same_rechnung_across_years_throws(): void
    {
        $pathA = $this->fixturePath('buchungen-ai-conflict-a-test.yaml');
        $pathB = $this->fixturePath('buchungen-ai-conflict-b-test.yaml');
        file_put_contents($pathA, <<<'YAML'
        Verwaltungsgruppe-1:
            Rechnung-0:
                Abrechnungsintervall: Intervall-1
                Buchung-0:
                    Datum: 17.2.2025
                    Betrag: 163,65
        YAML);
        file_put_contents($pathB, <<<'YAML'
        Verwaltungsgruppe-1:
            Rechnung-0:
                Abrechnungsintervall: Intervall-0
                Buchung-0:
                    Datum: 16.2.2026
                    Betrag: 163,65
        YAML);

        try {
            $this->seed(CostCategorySeeder::class);

            file_put_contents($this->path, <<<'YAML'
            lang: "de"

            Account-1:
                Name: "AI Konflikt User"
                Passwort: "geheim123"

                Verwaltungsgruppe-1:
                    Beschreibung: "AI Konflikt Gruppe"

                    Intervall-0:
                        Start: 1.1.2026
                        Ende: 31.12.2026
                    Intervall-1:
                        Start: 1.1.2025
                        Ende: 31.12.2025

                Rechnungssteller-1:
                    Firma: "Stadtverwaltung"
                    Ort: "Musterstadt"

                Rechnungen:
                    Verwaltungsgruppe-1:
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 15.2.2025
                            Betrag: 163,65
                            Beschreibung: "Grundsteuer"
                            Wiederholung: "quartal"
                            Betragsüberprüfung: false
                            Zuordnung:
                                Kategorie: "Öffentliche Lasten"

                Buchungen:
                    from-File:
                        - "buchungen-ai-conflict-a-test.yaml"
                        - "buchungen-ai-conflict-b-test.yaml"
            YAML);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/widersprüchliches "Abrechnungsintervall"/');

            $this->seed(TestDataSeeder::class);
        } finally {
            @unlink($pathA);
            @unlink($pathB);
        }
    }

    /**
     * A Buchungen-Datei written by hand naturally starts with its own top-level
     * "Buchungen:" key (mirroring how the inline block itself looks) rather than the
     * Verwaltungsgruppe-* structure directly – both must be accepted.
     */
    public function test_a_buchungen_from_file_wrapped_in_a_top_level_buchungen_key_is_unwrapped(): void
    {
        $externalPath = $this->fixturePath('buchungen-wrapped-test.yaml');
        file_put_contents($externalPath, <<<'YAML'
        Buchungen:
            Verwaltungsgruppe-1:
                Verwaltungseinheit-1:
                    Mieteinheit-1:
                        Mietvertrag-1:
                            Zahlungen:
                                - 3.1.2026
        YAML);

        try {
            $lease = $this->seedFixture(<<<'YAML'
            Buchungen:
                from-File: "buchungen-wrapped-test.yaml"
            YAML);

            $interval = $lease->leaseIntervals->first();
            $january = RentPayment::where('lease_interval_id', $interval->id)->whereDate('period_date', '2026-01-01')->first();

            $this->assertNotNull($january);
            $this->assertSame('2026-01-03', $january->booking_date->toDateString());
        } finally {
            @unlink($externalPath);
        }
    }

    /**
     * Regression: a From-File reference that doesn't resolve to a real file used to be
     * silently ignored – on Nutzerhinweis geändert: das ist eine Fehlkonfiguration
     * (z. B. ein Tippfehler im Dateinamen) und muss laut auffallen.
     */
    public function test_a_missing_buchungen_from_file_reference_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->seedFixture(<<<'YAML'
        Buchungen:
            from-File: "private-assets/does-not-exist.yaml"
        YAML);
    }

    public function test_zahlungen_books_the_matching_calendar_months_rent_payment(): void
    {
        $lease = $this->seedFixture(<<<'YAML'
        Buchungen:
            Verwaltungsgruppe-1:
                Verwaltungseinheit-1:
                    Mieteinheit-1:
                        Mietvertrag-1:
                            Zahlungen:
                                - 3.1.2026
                                - 5.2.2026
        YAML);

        $interval = $lease->leaseIntervals->first();
        $january = RentPayment::where('lease_interval_id', $interval->id)->whereDate('period_date', '2026-01-01')->first();
        $february = RentPayment::where('lease_interval_id', $interval->id)->whereDate('period_date', '2026-02-01')->first();

        $this->assertNotNull($january);
        $this->assertSame('2026-01-03', $january->booking_date->toDateString());
        $this->assertNotNull($february);
        $this->assertSame('2026-02-05', $february->booking_date->toDateString());
    }

    /**
     * Miete wird üblicherweise ein paar Tage im Voraus bezahlt: eine Buchung in der
     * letzten Monatshälfte (hier: 28.1., 4 Tage vor dem 1.2.) ist die Vorauszahlung
     * für den Folgemonat, nicht für den Monat, in dem tatsächlich gebucht wurde.
     */
    public function test_a_late_month_zahlung_books_the_following_months_rent_payment_as_an_advance(): void
    {
        $lease = $this->seedFixture(<<<'YAML'
        Buchungen:
            Verwaltungsgruppe-1:
                Verwaltungseinheit-1:
                    Mieteinheit-1:
                        Mietvertrag-1:
                            Zahlungen:
                                - 28.1.2026
        YAML);

        $interval = $lease->leaseIntervals->first();
        $january = RentPayment::where('lease_interval_id', $interval->id)->whereDate('period_date', '2026-01-01')->first();
        $february = RentPayment::where('lease_interval_id', $interval->id)->whereDate('period_date', '2026-02-01')->first();

        $this->assertNull($january->booking_date);
        $this->assertNotNull($february);
        $this->assertSame('2026-01-28', $february->booking_date->toDateString());
    }

    /**
     * Grenzfall des "nicht weiter als 1/2 Monat"-Fensters: 17.1.2026 liegt exakt 15
     * Tage vor dem 1.2. (noch innerhalb) und bucht Februar vor; 16.1.2026 liegt einen
     * Tag außerhalb und bucht noch Januar selbst.
     */
    public function test_the_half_month_advance_payment_window_boundary(): void
    {
        $lease = $this->seedFixture(<<<'YAML'
        Buchungen:
            Verwaltungsgruppe-1:
                Verwaltungseinheit-1:
                    Mieteinheit-1:
                        Mietvertrag-1:
                            Zahlungen:
                                - 17.1.2026
        YAML);

        $interval = $lease->leaseIntervals->first();
        $january = RentPayment::where('lease_interval_id', $interval->id)->whereDate('period_date', '2026-01-01')->first();
        $february = RentPayment::where('lease_interval_id', $interval->id)->whereDate('period_date', '2026-02-01')->first();

        $this->assertNull($january->booking_date);
        $this->assertSame('2026-01-17', $february->booking_date->toDateString());
    }

    /**
     * Regression guard: the "letzte Monatshälfte = Vorauszahlung für den Folgemonat"-
     * Fenster verglich den erwarteten Zahltag bislang immer hart gegen den 1. eines
     * Monats – bei einem mitten im Monat beginnenden Mietvertrag (hier: 10.) zählte
     * dadurch schon eine ganz normale erste Zahlung (20., zehn Tage nach Vertragsbeginn)
     * fälschlich als Vorauszahlung für den nächsten Monat, für den bei einem kurzen
     * Vorvertrag oft noch gar kein Mietintervall existiert (reales Beispiel: Mieter A,
     * Vorvertrag 10.–31.12., Zahlung 20.12. landete auf Januar statt Dezember).
     */
    public function test_a_lease_starting_mid_month_anchors_the_advance_payment_window_to_its_own_start_day(): void
    {
        $this->seed(CostCategorySeeder::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Mitten Im Monat User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Buchungen Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Buchungsweg 1"
                    PLZ: 11111
                    Stadt: "Buchungsstadt"

                    Mieteinheit-1:
                        Typ: "Wohnung"
                        Größe: 60

                        Mietvertrag-1:
                            MieterId: Mieter-1
                            Beginn: 10.1.2026
                            Mietpreistyp: "Fix"
                            Kaltmiete: 500
                            Nebenkosten: 100

                            Intervall-0:
                                Personen: 1

            Mieter-1:
                Vorname: "Max"
                Name: "Mustermann"
                Straße: "A"
                PLZ: 1
                Ort: "B"

            Buchungen:
                Verwaltungsgruppe-1:
                    Verwaltungseinheit-1:
                        Mieteinheit-1:
                            Mietvertrag-1:
                                Zahlungen:
                                    - 20.1.2026
                                    - 28.2.2026
        YAML);

        $this->seed(TestDataSeeder::class);

        $lease = Lease::first();
        $interval = $lease->leaseIntervals->first();
        $january = RentPayment::where('lease_interval_id', $interval->id)->whereDate('period_date', '2026-01-01')->first();
        $february = RentPayment::where('lease_interval_id', $interval->id)->whereDate('period_date', '2026-02-01')->first();
        $march = RentPayment::where('lease_interval_id', $interval->id)->whereDate('period_date', '2026-03-01')->first();

        // 20.1. is 10 Tage nach Vertragsbeginn (10.1.) – eindeutig die Januar-Zahlung,
        // nicht (fälschlich) eine Vorauszahlung für Februar (dessen erwarteter Zahltag
        // erst der 10.2. wäre, 21 Tage entfernt).
        $this->assertNotNull($january);
        $this->assertSame('2026-01-20', $january->booking_date->toDateString());
        $this->assertNull($february->booking_date);

        // 28.2. liegt dagegen nur noch 10 Tage vor dem erwarteten Zahltag im März
        // (10.3.) – innerhalb des 15-Tage-Fensters, also die März-Vorauszahlung.
        $this->assertNotNull($march);
        $this->assertSame('2026-02-28', $march->booking_date->toDateString());
    }

    public function test_zahlungen_does_not_book_a_month_that_was_not_listed(): void
    {
        $lease = $this->seedFixture(<<<'YAML'
        Buchungen:
            Verwaltungsgruppe-1:
                Verwaltungseinheit-1:
                    Mieteinheit-1:
                        Mietvertrag-1:
                            Zahlungen:
                                - 3.1.2026
        YAML);

        $interval = $lease->leaseIntervals->first();
        $february = RentPayment::where('lease_interval_id', $interval->id)->whereDate('period_date', '2026-02-01')->first();

        $this->assertNotNull($february);
        $this->assertNull($february->booking_date);
    }

    /**
     * Konzept.md "### Mietvertrag": "Genierie bitte Fehlermeldungen, wenn Buchungen auf
     * ein referenzierenden Mietvertrag gebucht werden." – a Mietvertrag coupled to
     * another one via "Mietvertrag: <ref>" (e.g. eine Garage gekoppelt an die Wohnung
     * desselben Mieters) may not declare its own "Zahlungen".
     */
    public function test_zahlungen_on_a_coupled_lease_throws(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Buchungen Kopplung User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Buchungen Kopplung Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Buchungsweg 2"
                    PLZ: 12345
                    Stadt: "Buchungsstadt"

                    Mieteinheit-1:
                        Typ: "Wohnung"
                        Abrechnungsrelevant: true

                        Mietvertrag-1:
                            MieterId: Mieter-1
                            Beginn: 2026-01-01
                            Mietpreistyp: "Fix"
                            Kaltmiete: 500

                    Mieteinheit-2:
                        Typ: "Garage"
                        Abrechnungsrelevant: false

                        Mietvertrag-1:
                            Mietvertrag: "Mieteinheit-1.Mietvertrag-1"
                            Beginn: 2026-01-01
                            Mietpreistyp: "Fix"
                            Kaltmiete: 30

            Mieter-1:
                Vorname: "Tom"
                Name: "Erste"
                Straße: "A-Weg 1"
                PLZ: 55555
                Ort: "A-Stadt"

            Buchungen:
                Verwaltungsgruppe-1:
                    Verwaltungseinheit-1:
                        Mieteinheit-2:
                            Mietvertrag-1:
                                Zahlungen:
                                    - 3.1.2026
        YAML);

        $this->expectException(\RuntimeException::class);
        $this->seed(TestDataSeeder::class);
    }

    /**
     * Bewusste Ausnahme von "niemals stillschweigend überspringen"
     * (Nutzerklarstellung): eine Zahlung, die in kein Mietintervall dieses
     * Mietvertrags fällt (typisch an einer Vorvertrag/Nachfolgevertrag-Grenze), bricht
     * das Seeden nicht ab – das Buchungsdatum an sich ist ja korrekt, es "fällt nur
     * durch das automatische Zuordnungsraster". Das betroffene RentPayment bleibt
     * "offen" und ist über die "Detailbild offene Zahlungen"-Übersicht auffindbar,
     * andere, zuordenbare Zahlungen desselben Mietvertrags werden trotzdem gebucht.
     */
    public function test_a_zahlung_outside_every_mietintervall_stays_open_instead_of_aborting_seeding(): void
    {
        $lease = $this->seedFixture(<<<'YAML'
        Buchungen:
            Verwaltungsgruppe-1:
                Verwaltungseinheit-1:
                    Mieteinheit-1:
                        Mietvertrag-1:
                            Zahlungen:
                                - 3.1.2026
                                - 3.6.2020
        YAML);

        $interval = $lease->leaseIntervals->first();
        $january = RentPayment::where('lease_interval_id', $interval->id)->whereDate('period_date', '2026-01-01')->first();

        $this->assertNotNull($january);
        $this->assertSame('2026-01-03', $january->booking_date->toDateString());
    }

    public function test_a_forderung_creates_a_settlement_claim_pinned_to_the_referenced_abrechnungsintervall(): void
    {
        $lease = $this->seedFixture(<<<'YAML'
        Buchungen:
            Verwaltungsgruppe-1:
                Verwaltungseinheit-1:
                    Mieteinheit-1:
                        Mietvertrag-1:
                            Forderung-0:
                                Abrechnungsintervall: Intervall-0
                                Datum: 2027-01-15
                                Betrag: 240,00
                                Buchungsdatum: 2027-02-01
                                Notiz: "Nachzahlung BKA 2026"
        YAML);

        $managementGroup = ManagementGroup::where('description', 'Buchungen Gruppe')->first();
        $billingInterval = $managementGroup->billingIntervals()->whereDate('start_date', '2026-01-01')->first();

        $claim = SettlementClaim::first();
        $this->assertNotNull($claim);
        $this->assertSame($lease->id, $claim->lease_id);
        $this->assertSame($billingInterval->id, $claim->billing_interval_id);
        $this->assertSame(240.0, (float) $claim->amount);
        $this->assertSame('2027-01-15', $claim->date->toDateString());
        $this->assertSame('2027-02-01', $claim->booking_date->toDateString());
        $this->assertSame('Nachzahlung BKA 2026', $claim->notes);
    }

    public function test_a_forderung_can_have_a_negative_amount_for_a_rueckzahlung(): void
    {
        $this->seedFixture(<<<'YAML'
        Buchungen:
            Verwaltungsgruppe-1:
                Verwaltungseinheit-1:
                    Mieteinheit-1:
                        Mietvertrag-1:
                            Forderung-0:
                                Abrechnungsintervall: Intervall-0
                                Datum: 2027-01-15
                                Betrag: -80,00
        YAML);

        $claim = SettlementClaim::first();
        $this->assertNotNull($claim);
        $this->assertSame(-80.0, (float) $claim->amount);
        $this->assertNull($claim->booking_date);
    }

    /**
     * Nutzerfeedback "Niemals stillschweigend Daten überspringen": eine unauflösbare
     * "Abrechnungsintervall"-Referenz auf einer Forderung wird jetzt ein Fehler beim
     * Seeden statt eines stillschweigend übersprungenen Eintrags.
     */
    public function test_a_forderung_without_a_valid_abrechnungsintervall_reference_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->seedFixture(<<<'YAML'
        Buchungen:
            Verwaltungsgruppe-1:
                Verwaltungseinheit-1:
                    Mieteinheit-1:
                        Mietvertrag-1:
                            Forderung-0:
                                Abrechnungsintervall: Intervall-9
                                Datum: 2027-01-15
                                Betrag: 100
        YAML);
    }

    /**
     * "-n" (e.g. "Kategorie-n") is only placeholder notation in the Konzept.md schema
     * docs, never a literal key – written literally, it fails to pair up with its
     * "Betrag-N" counterpart and an amount silently disappears (see
     * ParsesYamlValues::assertNoLiteralNSuffixKeys()).
     */
    public function test_a_literal_n_suffix_key_in_the_main_file_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->seedFixture(<<<'YAML'
        Buchungen:
            Verwaltungsgruppe-1:
                Verwaltungseinheit-1:
                    Mieteinheit-1:
                        Mietvertrag-1:
                            Rechnung-0:
                                Rechnungssteller: Rechnungssteller-1
                                Rechnungsdatum: 2026-03-01
                                Betrag: 50
                                Wiederholung: "einmalig"
                                Zuordnung:
                                    Betrag-1: 20
                                    Kategorie-1: "Wasserversorgung"
                                    Betrag-2: 30
                                    Kategorie-n: "Heizkosten"
        YAML);
    }

    public function test_a_literal_n_suffix_key_in_a_buchungen_from_file_reference_throws(): void
    {
        $externalPath = $this->fixturePath('buchungen-n-suffix-test.yaml');
        file_put_contents($externalPath, <<<'YAML'
        Verwaltungsgruppe-1:
            Verwaltungseinheit-1:
                Mieteinheit-1:
                    Mietvertrag-1:
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 2026-03-01
                            Betrag: 50
                            Wiederholung: "einmalig"
                            Zuordnung:
                                Betrag-1: 20
                                Kategorie-1: "Wasserversorgung"
                                Betrag-2: 30
                                Kategorie-n: "Heizkosten"
        YAML);

        try {
            $this->expectException(\RuntimeException::class);

            $this->seedFixture(<<<'YAML'
            Buchungen:
                from-File: "buchungen-n-suffix-test.yaml"
            YAML);
        } finally {
            @unlink($externalPath);
        }
    }

    public function test_reseeding_a_forderung_updates_it_in_place_without_duplicating(): void
    {
        $yaml = <<<'YAML'
        Buchungen:
            Verwaltungsgruppe-1:
                Verwaltungseinheit-1:
                    Mieteinheit-1:
                        Mietvertrag-1:
                            Forderung-0:
                                Abrechnungsintervall: Intervall-0
                                Datum: 2027-01-15
                                Betrag: 100
        YAML;
        $this->seedFixture($yaml);
        $this->assertSame(100.0, (float) SettlementClaim::first()->amount);

        // Zweiter Lauf mit demselben Datum/Intervall, aber geändertem Betrag.
        file_put_contents(
            $this->path,
            str_replace('Betrag: 100', 'Betrag: 150', file_get_contents($this->path))
        );
        $this->seed(TestDataSeeder::class);

        $this->assertSame(1, SettlementClaim::count());
        $this->assertSame(150.0, (float) SettlementClaim::first()->amount);
    }

    /**
     * A "Buchungen.Rechnung-N" that omits its own Rechnungssteller/Rechnungsdatum/
     * Betrag/Zuordnung inherits them from the same-keyed "Rechnungen.Rechnung-N" of
     * the same Ebene, so a Buchungen-Datei that only adds explicit Buchung-*-Termine
     * to an already fully-defined Rechnung doesn't need to repeat its Stammdaten.
     */
    public function test_a_buchungen_rechnung_inherits_missing_fields_from_the_same_keyed_rechnungen_entry(): void
    {
        $lease = $this->seedFixtureWithRechnungenAndBuchungen(
            rechnungenYaml: <<<'YAML'
            Rechnungen:
                Verwaltungsgruppe-1:
                    Rechnung-1:
                        Rechnungssteller: Rechnungssteller-1
                        Rechnungsdatum: 2026-01-01
                        Betrag: 500
                        Wiederholung: "monatlich"
                        Zuordnung:
                            Kategorie: "Wasserversorgung"
            YAML,
            buchungenYaml: <<<'YAML'
            Buchungen:
                Verwaltungsgruppe-1:
                    Rechnung-1:
                        Buchung-0:
                            Datum: 2026-01-03
                            Betrag: 495
            YAML
        );

        $managementGroup = ManagementGroup::where('description', 'Buchungen Gruppe')->first();
        $invoice = Invoice::where('costable_type', $managementGroup->getMorphClass())
            ->where('costable_id', $managementGroup->id)->first();

        $this->assertNotNull($invoice, 'Invoice was not created – Rechnungssteller was not inherited');
        $this->assertSame('Buchungen Versorger', $invoice->invoiceIssuer->name);
        $this->assertEquals(500.0, $invoice->amount);

        // Buchung-0 ist die erste Vorkommnis der monatlichen Rechnung: period_date =
        // die Periode selbst (Rechnungsdatum, geerbt), booking_date = das explizite
        // Buchungsdatum aus dem Buchungen-Block (positionale Zuordnung, siehe
        // seedExplicitBuchungEntries()).
        $cost = $invoice->costs()->whereDate('period_date', '2026-01-01')->first();
        $this->assertNotNull($cost);
        $this->assertEquals(495.0, $cost->amount);
        $this->assertSame('2026-01-03', $cost->booking_date->toDateString());
    }

    /**
     * The Buchungen entry's own fields still win over the inherited ones when a new
     * Invoice actually gets created from the merged data – here, a different Betrag
     * means "Buchungen.Rechnung-1" doesn't match the Invoice already created by
     * "Rechnungen.Rechnung-1" (amount is part of the lookup key, see seedInvoice()),
     * so a second Invoice is created for it, using the overridden Betrag.
     */
    public function test_a_buchungen_rechnungs_own_fields_override_the_inherited_ones(): void
    {
        $this->seedFixtureWithRechnungenAndBuchungen(
            rechnungenYaml: <<<'YAML'
            Rechnungen:
                Verwaltungsgruppe-1:
                    Rechnung-1:
                        Rechnungssteller: Rechnungssteller-1
                        Rechnungsdatum: 2026-01-01
                        Betrag: 500
                        Wiederholung: "einmalig"
                        Zuordnung:
                            Kategorie: "Wasserversorgung"
            YAML,
            buchungenYaml: <<<'YAML'
            Buchungen:
                Verwaltungsgruppe-1:
                    Rechnung-1:
                        Betrag: 600
            YAML
        );

        $managementGroup = ManagementGroup::where('description', 'Buchungen Gruppe')->first();
        $invoices = Invoice::where('costable_type', $managementGroup->getMorphClass())
            ->where('costable_id', $managementGroup->id)->pluck('amount')->map(fn ($a) => (float) $a)->sort()->values();

        $this->assertEquals([500.0, 600.0], $invoices->all());
    }

    /**
     * Like seedFixture(), but the Verwaltungsgruppe additionally declares two Konten
     * ("Konto-1"/"Konto-2"), for testing the Verwaltungsgruppe-level "Umbuchung-*" node.
     */
    private function seedFixtureWithKonten(string $buchungenYaml): ManagementGroup
    {
        $this->seed(CostCategorySeeder::class);
        $buchungenYaml = $this->indented($buchungenYaml);

        file_put_contents($this->path, <<<YAML
        lang: "de"

        Account-1:
            Name: "Buchungen User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Buchungen Gruppe"
                Kontosystem: true

                Konto-1:
                    Name: "Kasse"

                Konto-2:
                    Name: "Bank"

        $buchungenYaml
        YAML);

        $this->seed(TestDataSeeder::class);

        return ManagementGroup::where('description', 'Buchungen Gruppe')->first();
    }

    public function test_an_umbuchung_creates_an_account_transfer_between_the_referenced_konten(): void
    {
        $managementGroup = $this->seedFixtureWithKonten(<<<'YAML'
        Buchungen:
            Verwaltungsgruppe-1:
                Umbuchung-0:
                    Von: Konto-1
                    Nach: Konto-2
                    Datum: 2026-03-15
                    Betrag: 250,00
                    Notiz: "Bargeld eingezahlt"
        YAML);

        $kasse = $managementGroup->accounts()->where('name', 'Kasse')->first();
        $bank = $managementGroup->accounts()->where('name', 'Bank')->first();

        $transfer = AccountTransfer::first();
        $this->assertNotNull($transfer);
        $this->assertSame($kasse->id, $transfer->from_account_id);
        $this->assertSame($bank->id, $transfer->to_account_id);
        $this->assertEquals(250.0, $transfer->amount);
        $this->assertSame('2026-03-15', $transfer->transfer_date->toDateString());
        $this->assertSame('Bargeld eingezahlt', $transfer->comment);
    }

    /**
     * Nutzerfeedback "Niemals stillschweigend Daten überspringen": "Von"/"Nach" auf
     * demselben Konto ist jetzt ein Fehler beim Seeden statt eines stillschweigend
     * übersprungenen Eintrags (Konzept.md: "dürfen nicht dasselbe Konto sein").
     */
    public function test_an_umbuchung_with_the_same_von_and_nach_konto_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->seedFixtureWithKonten(<<<'YAML'
        Buchungen:
            Verwaltungsgruppe-1:
                Umbuchung-0:
                    Von: Konto-1
                    Nach: Konto-1
                    Datum: 2026-03-15
                    Betrag: 250,00
        YAML);
    }

    public function test_an_umbuchung_with_an_unresolvable_konto_reference_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->seedFixtureWithKonten(<<<'YAML'
        Buchungen:
            Verwaltungsgruppe-1:
                Umbuchung-0:
                    Von: Konto-1
                    Nach: Konto-99
                    Datum: 2026-03-15
                    Betrag: 250,00
        YAML);
    }

    public function test_reseeding_an_umbuchung_updates_it_in_place_without_duplicating(): void
    {
        $this->seedFixtureWithKonten(<<<'YAML'
        Buchungen:
            Verwaltungsgruppe-1:
                Umbuchung-0:
                    Von: Konto-1
                    Nach: Konto-2
                    Datum: 2026-03-15
                    Betrag: 250,00
                    Notiz: "Erste Notiz"
        YAML);
        $this->assertSame(1, AccountTransfer::count());
        $this->assertSame('Erste Notiz', AccountTransfer::first()->comment);

        file_put_contents(
            $this->path,
            str_replace('Erste Notiz', 'Zweite Notiz', file_get_contents($this->path))
        );
        $this->seed(TestDataSeeder::class);

        $this->assertSame(1, AccountTransfer::count());
        $this->assertSame('Zweite Notiz', AccountTransfer::first()->comment);
    }
}
