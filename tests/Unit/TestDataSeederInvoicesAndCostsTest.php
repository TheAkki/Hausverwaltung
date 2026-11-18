<?php

namespace Tests\Unit;

use App\Models\Cost;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\ManagementUnit;
use App\Services\BillingCostAllocationService;
use App\Services\BillingStatementLeaseResolver;
use Carbon\Carbon;
use Database\Seeders\CostCategorySeeder;
use Database\Seeders\TestDataSeeder;
use Illuminate\Support\Collection;

/**
 * Covers SeedsInvoicesAndCosts: Rechnungen, the automatic monthly cost forecast,
 * explicit "Buchungen"/"Buchung-*" bookings, and Abrechnungsintervall pinning.
 */
class TestDataSeederInvoicesAndCostsTest extends TestDataSeederTestCase
{
    public function test_it_books_a_rechnung_against_the_verwaltungseinheit_it_is_nested_under(): void
    {
        $this->seed(CostCategorySeeder::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Rechnung User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Rechnung Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Rechnungsweg 1"
                    PLZ: 11111
                    Stadt: "Rechnungsstadt"

            Rechnungssteller-2:
                Firma: "F&M Gebäudedienste GmbH"
                Straße: "Musterweg 5"
                PLZ: 09876
                Ort: "Musterstadt"
                Telefon: "+49 3581 411717"
                IBAN: "DE46 8505 0100 0232 0674 14"

                Kundenaccount-0:
                    Kundenummer: 10301

            Rechnungen:
                Verwaltungsgruppe-1:
                    Verwaltungseinheit-1:
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-2
                            Account: Kundenaccount-0
                            Rechnungsdatum: 12.01.2026
                            Buchungsdatum: 5.1.2026
                            Betrag: 368,84
                            Beschreibung: "Hausmeisterservice"
                            Wiederholung: "monatlich"

                            Zuordnung:
                                Betrag-1: 368,84
                                Kategorie-1: "Hauswart"
        YAML);

        $this->seed(TestDataSeeder::class);

        $managementUnit = ManagementUnit::where('street', 'Rechnungsweg 1')->first();
        $this->assertNotNull($managementUnit);

        $invoice = Invoice::whereHas(
            'invoiceIssuer',
            fn ($q) => $q->where('company_name', 'F&M Gebäudedienste GmbH')
        )->first();

        $this->assertNotNull($invoice);
        $this->assertSame('2026-01-12', $invoice->invoice_date->toDateString());
        $this->assertSame('2026-01-05', $invoice->booking_date->toDateString());
        $this->assertSame(368.84, (float) $invoice->amount);
        $this->assertSame('monthly', $invoice->periodicity);
        $this->assertSame('10301', $invoice->invoiceIssuerAccount->customer_number);

        // The invoice's own scope (Wirkungsrahmen) must be the Verwaltungseinheit it is
        // nested under in the Rechnungen block, not the enclosing Verwaltungsgruppe.
        $this->assertTrue($invoice->costable->is($managementUnit));

        $cost = Cost::where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($cost);
        $this->assertTrue($cost->costable->is($managementUnit));
    }

    /**
     * Regression guard: a Rechnung whose "Rechnungsdatum" is missing used to be
     * silently dropped entirely – seedInvoice() just `return`ed, with no error, no log,
     * nothing (reported by the Nutzer as "Rechnung 6 scheint nicht geseedet zu sein").
     * Konzept.md "Testdaten" doesn't mark "Rechnungsdatum" optional (no eckige
     * Klammern) – a missing one is now a Fehler beim Seeden instead
     * (Nutzerfeedback: "Niemals stillschweigend Daten überspringen").
     */
    public function test_a_rechnung_without_a_rechnungsdatum_throws_instead_of_being_silently_dropped(): void
    {
        $this->seed(CostCategorySeeder::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Ohne Rechnungsdatum User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Weg 1"
                    PLZ: 11111
                    Stadt: "Stadt"

            Rechnungssteller-1:
                Firma: "Knappschaft See"
                Straße: ""
                PLZ: 1
                Ort: "Stadt"

            Rechnungen:
                Verwaltungsgruppe-1:
                    Verwaltungseinheit-1:
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Buchungsdatum: 18.8.2025
                            Betrag: 37,58
                            Beschreibung: "SV-Beiträge"
                            Wiederholung: "monatlich"

                            Zuordnung:
                                Kategorie: "Hauswart"
        YAML);

        $this->expectException(\RuntimeException::class);

        $this->seed(TestDataSeeder::class);
    }

    public function test_a_rechnung_referencing_an_unknown_rechnungssteller_throws(): void
    {
        $this->seed(CostCategorySeeder::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Unbekannter Steller User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Weg 1"
                    PLZ: 11111
                    Stadt: "Stadt"

            Rechnungen:
                Verwaltungsgruppe-1:
                    Verwaltungseinheit-1:
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-99
                            Rechnungsdatum: 1.1.2026
                            Betrag: 100
                            Wiederholung: "einmalig"

                            Zuordnung:
                                Kategorie: "Hauswart"
        YAML);

        $this->expectException(\RuntimeException::class);

        $this->seed(TestDataSeeder::class);
    }

    /**
     * Regression guard: a Kategorie name that doesn't match any visible CostCategory
     * (typo, or belongs to another Account) used to make its Kostenzeile vanish
     * silently, both in the automatic forecast (createCostRows()) and in an explicit
     * Buchung (upsertExplicitCostRows()) – now a Fehler beim Seeden.
     */
    public function test_a_zuordnung_referencing_an_unknown_kategorie_throws(): void
    {
        $this->seed(CostCategorySeeder::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Unbekannte Kategorie User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Weg 1"
                    PLZ: 11111
                    Stadt: "Stadt"

            Rechnungssteller-1:
                Firma: "Versorger GmbH"
                Straße: ""
                PLZ: 1
                Ort: "Stadt"

            Rechnungen:
                Verwaltungsgruppe-1:
                    Verwaltungseinheit-1:
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.1.2026
                            Betrag: 100
                            Wiederholung: "einmalig"

                            Zuordnung:
                                Kategorie: "Gibtsnicht"
        YAML);

        $this->expectException(\RuntimeException::class);

        $this->seed(TestDataSeeder::class);
    }

    public function test_rechnungen_referencing_an_unknown_verwaltungsgruppe_throws(): void
    {
        $this->seed(CostCategorySeeder::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Unbekannte VG User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Gruppe"

            Rechnungssteller-1:
                Firma: "Versorger GmbH"
                Straße: ""
                PLZ: 1
                Ort: "Stadt"

            Rechnungen:
                Verwaltungsgruppe-2:
                    Rechnung-0:
                        Rechnungssteller: Rechnungssteller-1
                        Rechnungsdatum: 1.1.2026
                        Betrag: 100
                        Wiederholung: "einmalig"

                        Zuordnung:
                            Kategorie: "Hauswart"
        YAML);

        $this->expectException(\RuntimeException::class);

        $this->seed(TestDataSeeder::class);
    }

    /**
     * Regression guard (reales Beispiel: "EC-Kartengebühr Anna"/"... Bert",
     * derselbe Rechnungssteller, dieselbe Zuordnung, dasselbe Rechnungsdatum, derselbe
     * Betrag): der "schon vorhanden"-Abgleich beim Seeden verglich bislang nur
     * Rechnungssteller/Zuordnung/Rechnungsdatum/Betrag – zwei rechnerisch identische,
     * aber inhaltlich verschiedene Rechnungen (nur die Beschreibung unterscheidet sie)
     * verschmolzen dadurch zu einer einzigen; die zweite fand "sich selbst" (die
     * erste) bereits vorhanden und ihre eigenen Kosten wurden nie erzeugt.
     */
    public function test_two_rechnungen_with_identical_issuer_zuordnung_date_and_amount_but_different_beschreibung_stay_separate(): void
    {
        $this->seed(CostCategorySeeder::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Doppelte Signatur User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Gruppe"

            Rechnungssteller-1:
                Firma: "Sparkasse"
                Straße: ""
                PLZ: 1
                Ort: "Stadt"

            Rechnungen:
                Verwaltungsgruppe-1:
                    Rechnung-0:
                        Rechnungssteller: Rechnungssteller-1
                        Rechnungsdatum: 1.1.2021
                        Betrag: 6
                        Beschreibung: "EC-Kartengebühr Anna"
                        Wiederholung: "einmalig"
                        Zuordnung:
                            Kategorie: "Hauswart"

                    Rechnung-1:
                        Rechnungssteller: Rechnungssteller-1
                        Rechnungsdatum: 1.1.2021
                        Betrag: 6
                        Beschreibung: "EC-Kartengebühr Bert"
                        Wiederholung: "einmalig"
                        Zuordnung:
                            Kategorie: "Hauswart"
        YAML);

        $this->seed(TestDataSeeder::class);

        $this->assertSame(2, Invoice::whereDate('invoice_date', '2021-01-01')->where('amount', 6)->count());

        $anna = Invoice::where('description', 'EC-Kartengebühr Anna')->first();
        $bert = Invoice::where('description', 'EC-Kartengebühr Bert')->first();
        $this->assertNotNull($anna);
        $this->assertNotNull($bert);
        $this->assertNotSame($anna->id, $bert->id);
        $this->assertSame(1, $anna->costs()->count());
        $this->assertSame(1, $bert->costs()->count());
    }

    public function test_it_creates_costs_from_a_rechnungs_zuordnung(): void
    {
        $this->seed(CostCategorySeeder::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Zuordnung User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Zuordnung Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Zuordnungsweg 1"
                    PLZ: 22222
                    Stadt: "Zuordnungsstadt"

            Rechnungssteller-1:
                Firma: "Versorger GmbH"
                Straße: ""
                PLZ: 1
                Ort: "Stadt"

            Rechnungen:
                Verwaltungsgruppe-1:
                    Verwaltungseinheit-1:
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 2026-02-01
                            Betrag: 500
                            Wiederholung: "einmalig"

                            Zuordnung:
                                Betrag-1: 300
                                Kategorie-1: "Wasserversorgung"
                                Betrag-2: 200
                                Kategorie-2: "Entwässerung"
        YAML);

        $this->seed(TestDataSeeder::class);

        $invoice = Invoice::whereHas(
            'invoiceIssuer',
            fn ($q) => $q->where('company_name', 'Versorger GmbH')
        )->first();
        $this->assertNotNull($invoice);

        $costs = Cost::where('invoice_id', $invoice->id)->get();
        $this->assertCount(2, $costs);
        $this->assertSame(500.0, (float) $costs->sum('amount'));
    }

    public function test_a_zuordnung_split_that_does_not_sum_to_the_invoice_amount_throws(): void
    {
        $this->seed(CostCategorySeeder::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Betragsüberprüfung User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Weg 1"
                    PLZ: 22222
                    Stadt: "Stadt"

            Rechnungssteller-1:
                Firma: "Versorger GmbH"
                Straße: ""
                PLZ: 1
                Ort: "Stadt"

            Rechnungen:
                Verwaltungsgruppe-1:
                    Verwaltungseinheit-1:
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 2026-02-01
                            Betrag: 500
                            Wiederholung: "einmalig"

                            Zuordnung:
                                Betrag-1: 300
                                Kategorie-1: "Wasserversorgung"
                                Betrag-2: 150
                                Kategorie-2: "Entwässerung"
        YAML);

        $this->expectException(\RuntimeException::class);

        $this->seed(TestDataSeeder::class);
    }

    public function test_betragsueberpruefung_false_suppresses_the_sum_check_and_disables_the_amount_cap(): void
    {
        $this->seed(CostCategorySeeder::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Betragsüberprüfung Off User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Weg 1"
                    PLZ: 22222
                    Stadt: "Stadt"

            Rechnungssteller-1:
                Firma: "Versorger GmbH"
                Straße: ""
                PLZ: 1
                Ort: "Stadt"

            Rechnungen:
                Verwaltungsgruppe-1:
                    Verwaltungseinheit-1:
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 2026-02-01
                            Betragsüberprüfung: false
                            Betrag: 500
                            Wiederholung: "einmalig"

                            Zuordnung:
                                Betrag-1: 300
                                Kategorie-1: "Wasserversorgung"
                                Betrag-2: 150
                                Kategorie-2: "Entwässerung"
        YAML);

        $this->seed(TestDataSeeder::class);

        $invoice = Invoice::whereHas(
            'invoiceIssuer',
            fn ($q) => $q->where('company_name', 'Versorger GmbH')
        )->first();
        $this->assertNotNull($invoice);
        $this->assertTrue($invoice->disable_amount_cap);

        $costs = Cost::where('invoice_id', $invoice->id)->get();
        $this->assertCount(2, $costs);
        $this->assertSame(450.0, (float) $costs->sum('amount'));
    }

    public function test_a_kategorie_without_a_matching_betrag_still_gets_a_cost_row_with_amount_zero(): void
    {
        $this->seed(CostCategorySeeder::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Zuordnung Ohne Betrag User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Zuordnung Ohne Betrag Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Ohnebetragweg 1"
                    PLZ: 33333
                    Stadt: "Ohnebetragstadt"

            Rechnungssteller-1:
                Firma: "Kredit GmbH"
                Straße: ""
                PLZ: 1
                Ort: "Stadt"

            Rechnungen:
                Verwaltungsgruppe-1:
                    Verwaltungseinheit-1:
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 2026-02-01
                            Betrag: 300
                            Wiederholung: "einmalig"

                            Zuordnung:
                                Betrag-1: 300
                                Kategorie-1: "Zinsen"
                                Kategorie-2: "Tilgung"
        YAML);

        $this->seed(TestDataSeeder::class);

        $invoice = Invoice::whereHas(
            'invoiceIssuer',
            fn ($q) => $q->where('company_name', 'Kredit GmbH')
        )->first();
        $this->assertNotNull($invoice);

        $costs = Cost::where('invoice_id', $invoice->id)->get();
        $this->assertCount(2, $costs);

        $zinsen = $costs->first(fn ($c) => $c->costCategory->name === 'Zinsen');
        $tilgung = $costs->first(fn ($c) => $c->costCategory->name === 'Tilgung');

        $this->assertNotNull($zinsen);
        $this->assertNotNull($tilgung);
        $this->assertSame(300.0, (float) $zinsen->amount);
        $this->assertSame(0.0, (float) $tilgung->amount);
    }

    public function test_it_reads_the_rechnungsnummer_and_the_single_category_zuordnung_shorthand(): void
    {
        $this->seed(CostCategorySeeder::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Shorthand User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Shorthand Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Shorthandweg 1"
                    PLZ: 33333
                    Stadt: "Shorthandstadt"

            Rechnungssteller-1:
                Firma: "Schornsteinfeger GmbH"
                Straße: ""
                PLZ: 1
                Ort: "Stadt"

            Rechnungen:
                Verwaltungsgruppe-1:
                    Verwaltungseinheit-1:
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 24.04.2026
                            Rechnungsnummer: "1186/2026/26/01178"
                            Betrag: 51,98 €
                            Beschreibung: "Schornsteinfeger"
                            Wiederholung: "einmalig"

                            Zuordnung:
                                Kategorie: "Schornsteinreinigung"
        YAML);

        $this->seed(TestDataSeeder::class);

        $invoice = Invoice::whereHas(
            'invoiceIssuer',
            fn ($q) => $q->where('company_name', 'Schornsteinfeger GmbH')
        )->first();

        $this->assertNotNull($invoice);
        $this->assertSame('1186/2026/26/01178', $invoice->invoice_number);
        $this->assertSame(51.98, (float) $invoice->amount);

        $cost = Cost::where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($cost);
        $this->assertSame(51.98, (float) $cost->amount);
        $this->assertSame('Schornsteinreinigung', $cost->costCategory->name);
    }

    public function test_a_cost_is_booked_on_the_buchungsdatum_year_not_the_rechnungsdatum_year(): void
    {
        $this->seed(CostCategorySeeder::class);

        // Regression test: a Rechnung dated in one year but booked (Buchungsdatum) in
        // the next must show up in the Ausgabenübersicht for the booking year, since
        // that overview groups Cost rows by period_date's year, not the invoice date.
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Booking Year User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Booking Year Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Buchungsweg 1"
                    PLZ: 44444
                    Stadt: "Buchungsstadt"

            Rechnungssteller-1:
                Firma: "Versicherung GmbH"
                Straße: ""
                PLZ: 1
                Ort: "Stadt"

            Rechnungen:
                Verwaltungsgruppe-1:
                    Verwaltungseinheit-1:
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.11.2025
                            Buchungsdatum: 2.1.2026
                            Betrag: 3462,47
                            Wiederholung: "einmalig"

                            Zuordnung:
                                Kategorie: "Sach- und Haftpflichtversicherung"
        YAML);

        $this->seed(TestDataSeeder::class);

        $invoice = Invoice::whereHas(
            'invoiceIssuer',
            fn ($q) => $q->where('company_name', 'Versicherung GmbH')
        )->first();
        $this->assertNotNull($invoice);

        $cost = Cost::where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($cost);
        $this->assertSame('2026-01-02', $cost->period_date->toDateString());
        $this->assertSame(2026, $cost->billingInterval->start_date->year);

        // Regression test: a "once" Rechnung's single Cost row must inherit the
        // invoice's own Buchungsdatum so it shows up as "Gebucht" immediately,
        // consistent with InvoiceCostGenerator's behavior for UI-created invoices.
        $this->assertNotNull($cost->booking_date);
        $this->assertSame('2026-01-02', $cost->booking_date->toDateString());
        $this->assertFalse($cost->isOpen());
    }

    public function test_a_monatlich_rechnung_without_an_ende_forecasts_through_the_end_of_next_year(): void
    {
        $this->seed(CostCategorySeeder::class);

        Carbon::setTestNow('2026-03-15');

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Monthly User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Monthly Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Monatsweg 1"
                    PLZ: 55555
                    Stadt: "Monatsstadt"

            Rechnungssteller-1:
                Firma: "Hausmeister GmbH"
                Straße: ""
                PLZ: 1
                Ort: "Stadt"

            Rechnungen:
                Verwaltungsgruppe-1:
                    Verwaltungseinheit-1:
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 12.01.2026
                            Buchungsdatum: 5.1.2026
                            Betrag: 368,84
                            Wiederholung: "monatlich"

                            Zuordnung:
                                Kategorie: "Hauswart"
        YAML);

        $this->seed(TestDataSeeder::class);

        Carbon::setTestNow();

        $invoice = Invoice::whereHas(
            'invoiceIssuer',
            fn ($q) => $q->where('company_name', 'Hausmeister GmbH')
        )->first();
        $this->assertNotNull($invoice);

        // Without an "Ende", an open-ended Rechnung forecasts through the end of *next*
        // calendar year counted from "now" (15.3.2026, so through 31.12.2027), not just up
        // to "today" or even just the current year – otherwise next year's
        // "Ausgabenübersicht" would show no forecast at all until that year actually starts.
        // Buchungsdatum 5.1.2026 monthly through 31.12.2027 = 24 costs.
        $costs = Cost::where('invoice_id', $invoice->id)->orderBy('period_date')->get();
        $this->assertCount(24, $costs);
        $this->assertSame(
            ['2026-01-05', '2026-02-05', '2026-03-05', '2026-04-05', '2026-05-05', '2026-06-05',
                '2026-07-05', '2026-08-05', '2026-09-05', '2026-10-05', '2026-11-05', '2026-12-05',
                '2027-01-05', '2027-02-05', '2027-03-05', '2027-04-05', '2027-05-05', '2027-06-05',
                '2027-07-05', '2027-08-05', '2027-09-05', '2027-10-05', '2027-11-05', '2027-12-05'],
            $costs->map(fn ($c) => $c->period_date->toDateString())->all()
        );
        $this->assertEqualsWithDelta(368.84, (float) $costs->sum('amount') / 24, 0.0001);

        // Unlike a "once" Rechnung, each monthly occurrence must start unbooked so it
        // can be confirmed individually as it actually happens.
        $this->assertTrue($costs->every(fn ($c) => $c->isOpen()));
    }

    /**
     * Konzept.md "### Rechnungen": "Wenn die Periodizität über das aktuelle
     * Abrechnungsintervall hinausragt, muss ein neues Abrechnungsintervall angefügt
     * werden" – konkretisiert: das gilt genauso, wenn die Rechnung gar kein "Ende"
     * hat. Ohne "Ende" wird bis Ende des kommenden Kalenderjahres prognostiziert (s.
     * test_a_monatlich_rechnung_without_an_ende_forecasts_through_the_end_of_next_year),
     * wofür findOrCreateBillingInterval() für jede so erzeugte Abbuchung genauso ein
     * fehlendes Abrechnungsintervall automatisch anlegt wie bei einer Rechnung mit
     * explizitem "Ende" (siehe Rechnung-3 "Strom Heizung" in einer echten
     * fn4-buchungen-*.yaml, deren "Ende: 31.1.2027" genau deshalb ein 2027er-Intervall
     * erzeugt).
     */
    public function test_a_monatlich_rechnung_without_an_ende_auto_creates_the_billing_interval_it_forecasts_into(): void
    {
        $this->seed(CostCategorySeeder::class);

        Carbon::setTestNow('2026-03-15');

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Monthly User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Monthly Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Monatsweg 1"
                    PLZ: 55555
                    Stadt: "Monatsstadt"

            Rechnungssteller-1:
                Firma: "Hausmeister GmbH"
                Straße: ""
                PLZ: 1
                Ort: "Stadt"

            Rechnungen:
                Verwaltungsgruppe-1:
                    Verwaltungseinheit-1:
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 12.01.2026
                            Buchungsdatum: 5.1.2026
                            Betrag: 368,84
                            Wiederholung: "monatlich"

                            Zuordnung:
                                Kategorie: "Hauswart"
        YAML);

        $this->seed(TestDataSeeder::class);

        Carbon::setTestNow();

        $managementGroup = ManagementGroup::where('description', 'Monthly Gruppe')->first();
        $this->assertNotNull($managementGroup);

        // The ManagementGroup itself only ever auto-creates its own first (2026)
        // interval on creation (ManagementGroupObserver) – the 2027 one must come
        // solely from this open-ended Rechnung's forecast reaching into it.
        $intervals = $managementGroup->billingIntervals()->orderBy('start_date')->get();
        $this->assertSame([2026, 2027], $intervals->map(fn ($i) => $i->start_date->year)->all());

        $invoice = Invoice::whereHas(
            'invoiceIssuer',
            fn ($q) => $q->where('company_name', 'Hausmeister GmbH')
        )->first();
        $costsIn2027 = Cost::where('invoice_id', $invoice->id)
            ->whereYear('period_date', 2027)
            ->get();
        $this->assertNotEmpty($costsIn2027);
        $this->assertTrue($costsIn2027->every(
            fn (Cost $cost) => $cost->billingInterval->start_date->year === 2027
        ));
    }

    public function test_a_monatlich_rechnung_with_an_ende_in_the_past_stops_generating_there_instead_of_today(): void
    {
        $this->seed(CostCategorySeeder::class);

        Carbon::setTestNow('2026-03-15');

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Ende User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Ende Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Endeweg 1"
                    PLZ: 55555
                    Stadt: "Endestadt"

            Rechnungssteller-1:
                Firma: "Hausmeister GmbH"
                Straße: ""
                PLZ: 1
                Ort: "Stadt"

            Rechnungen:
                Verwaltungsgruppe-1:
                    Verwaltungseinheit-1:
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 5.1.2026
                            Betrag: 368,84
                            Ende: 31.1.2026
                            Wiederholung: "monatlich"

                            Zuordnung:
                                Kategorie: "Hauswart"
        YAML);

        $this->seed(TestDataSeeder::class);

        Carbon::setTestNow();

        $invoice = Invoice::whereHas(
            'invoiceIssuer',
            fn ($q) => $q->where('company_name', 'Hausmeister GmbH')
        )->first();
        $this->assertNotNull($invoice);

        // Without "Ende" this would run monthly through "now" (15.3.2026): Jan/Feb/Mar.
        // With "Ende: 31.1.2026" it must stop after the January occurrence.
        $costs = Cost::where('invoice_id', $invoice->id)->orderBy('period_date')->get();
        $this->assertSame(['2026-01-05'], $costs->map(fn ($c) => $c->period_date->toDateString())->all());
    }

    public function test_a_monatlich_rechnung_with_an_ende_in_the_future_forecasts_all_the_way_to_it(): void
    {
        $this->seed(CostCategorySeeder::class);

        Carbon::setTestNow('2026-03-15');

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Ende User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Ende Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Endeweg 1"
                    PLZ: 55555
                    Stadt: "Endestadt"

            Rechnungssteller-1:
                Firma: "Hausmeister GmbH"
                Straße: ""
                PLZ: 1
                Ort: "Stadt"

            Rechnungen:
                Verwaltungsgruppe-1:
                    Verwaltungseinheit-1:
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 5.1.2026
                            Betrag: 368,84
                            Ende: 31.5.2026
                            Wiederholung: "monatlich"

                            Zuordnung:
                                Kategorie: "Hauswart"
        YAML);

        $this->seed(TestDataSeeder::class);

        Carbon::setTestNow();

        $invoice = Invoice::whereHas(
            'invoiceIssuer',
            fn ($q) => $q->where('company_name', 'Hausmeister GmbH')
        )->first();
        $this->assertNotNull($invoice);

        // "now" is 15.3.2026, so without "Ende" this would stop after March. With
        // "Ende: 31.5.2026" (still ahead of "now") the forecast must already reach May,
        // since "prognostizierte Ausgaben" means projecting forward to the Rechnung's
        // known end, not just backfilling up to today.
        $costs = Cost::where('invoice_id', $invoice->id)->orderBy('period_date')->get();
        $this->assertSame(
            ['2026-01-05', '2026-02-05', '2026-03-05', '2026-04-05', '2026-05-05'],
            $costs->map(fn ($c) => $c->period_date->toDateString())->all()
        );
    }

    /**
     * The exact real-world shape that prompted this rule (a "Strom Heizung"-Rechnung
     * in echten Testdaten, monatlich ab März 2026, Ende 31.1.2027): its single
     * trailing occurrence in Januar 2027 must not spawn a near-empty 2027er
     * Abrechnungsintervall – Konzept.md "### Rechnung": "Wenn ein Enddatum gesetzt
     * ist, ... nehme das [ursprüngliche] Abrechnungsintervall [für alle Buchungen]."
     */
    public function test_a_monatlich_rechnung_whose_ende_pokes_into_the_next_year_stays_in_its_original_billing_interval(): void
    {
        $this->seed(CostCategorySeeder::class);

        Carbon::setTestNow('2026-08-17');

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Strom User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Strom Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Stromweg 1"
                    PLZ: 55555
                    Stadt: "Stromstadt"

            Rechnungssteller-1:
                Firma: "Stadtwerke Musterstadt"
                Straße: ""
                PLZ: 1
                Ort: "Stadt"

            Rechnungen:
                Verwaltungsgruppe-1:
                    Verwaltungseinheit-1:
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 25.02.2026
                            Buchungsdatum: 1.3.2026
                            Betrag: 28,00
                            Ende: 31.1.2027
                            Wiederholung: "monatlich"

                            Zuordnung:
                                Kategorie: "Heizkosten"
        YAML);

        $this->seed(TestDataSeeder::class);

        Carbon::setTestNow();

        $managementGroup = ManagementGroup::where('description', 'Strom Gruppe')->first();
        $this->assertNotNull($managementGroup);

        // Still just the one (2026) interval ManagementGroupObserver seeded on
        // creation – the Januar-2027-Abbuchung did not spawn a second one.
        $intervals = $managementGroup->billingIntervals()->get();
        $this->assertCount(1, $intervals);
        $this->assertSame(2026, $intervals->first()->start_date->year);

        $invoice = Invoice::whereHas(
            'invoiceIssuer',
            fn ($q) => $q->where('company_name', 'Stadtwerke Musterstadt')
        )->first();
        $costs = Cost::where('invoice_id', $invoice->id)->orderBy('period_date')->get();

        // Buchungsdatum 1.3.2026 monatlich bis 31.1.2027 = März 2026 … Januar 2027.
        $this->assertSame('2027-01-01', $costs->last()->period_date->toDateString());
        $this->assertTrue($costs->every(fn ($c) => $c->billing_interval_id === $intervals->first()->id));
    }

    /**
     * "Anfang" (Konzept.md "### Rechnungen": nur bei periodischen Rechnungen sinnvoll,
     * bestimmt zusammen mit "Ende" den Gültigkeitsbereich der Periodizität) überschreibt
     * den sonst aus Buchungsdatum/Rechnungsdatum abgeleiteten Anker – z. B. ein Kredit,
     * dessen Rechnungsdatum nur das Vertragsdatum ist, dessen Ratenzahlungen aber erst
     * Monate später anlaufen.
     */
    public function test_a_monatlich_rechnung_with_an_explicit_anfang_starts_generating_there_instead_of_rechnungsdatum(): void
    {
        $this->seed(CostCategorySeeder::class);

        Carbon::setTestNow('2026-06-15');

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Anfang User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Anfang Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Anfangsweg 1"
                    PLZ: 55555
                    Stadt: "Anfangsstadt"

            Rechnungssteller-1:
                Firma: "Kreditgeber GmbH"
                Straße: ""
                PLZ: 1
                Ort: "Stadt"

            Rechnungen:
                Verwaltungsgruppe-1:
                    Verwaltungseinheit-1:
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.1.2026
                            Anfang: 1.3.2026
                            Ende: 31.5.2026
                            Betrag: 100
                            Wiederholung: "monatlich"

                            Zuordnung:
                                Kategorie: "Zinsen"
        YAML);

        $this->seed(TestDataSeeder::class);

        Carbon::setTestNow();

        $invoice = Invoice::whereHas(
            'invoiceIssuer',
            fn ($q) => $q->where('company_name', 'Kreditgeber GmbH')
        )->first();
        $this->assertNotNull($invoice);
        // Das Rechnungsdatum selbst bleibt unverändert wie eingegeben.
        $this->assertSame('2026-01-01', $invoice->invoice_date->toDateString());

        // Die Monatsprognose beginnt bei "Anfang" (1.3.2026), nicht beim
        // Rechnungsdatum (1.1.2026) – Januar/Februar bekommen keine Kostenzeile.
        $costs = Cost::where('invoice_id', $invoice->id)->orderBy('period_date')->get();
        $this->assertSame(
            ['2026-03-01', '2026-04-01', '2026-05-01'],
            $costs->map(fn ($c) => $c->period_date->toDateString())->all()
        );
    }

    public function test_a_monatlich_rechnung_starting_on_the_31st_never_skips_february(): void
    {
        $this->seed(CostCategorySeeder::class);

        Carbon::setTestNow('2026-05-01');

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Monthly User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Monthly Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Monatsweg 1"
                    PLZ: 55555
                    Stadt: "Monatsstadt"

            Rechnungssteller-1:
                Firma: "Hausmeister GmbH"
                Straße: ""
                PLZ: 1
                Ort: "Stadt"

            Rechnungen:
                Verwaltungsgruppe-1:
                    Verwaltungseinheit-1:
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 12.01.2026
                            Buchungsdatum: 31.1.2026
                            Betrag: 100
                            Wiederholung: "monatlich"

                            Zuordnung:
                                Kategorie: "Hauswart"
        YAML);

        $this->seed(TestDataSeeder::class);

        Carbon::setTestNow();

        $invoice = Invoice::whereHas(
            'invoiceIssuer',
            fn ($q) => $q->where('company_name', 'Hausmeister GmbH')
        )->first();
        $this->assertNotNull($invoice);

        // Chaining Carbon's addMonth() from Jan 31 would overflow to Mar 3, skipping
        // February entirely and permanently shifting every later occurrence's day to 3.
        // Each occurrence must be computed from the original start date instead, so the
        // month is never skipped (Feb clamps to its last day) and the invoice returns
        // to day 31 as soon as the month is long enough again. Without an "Ende", the
        // forecast runs through the end of *next* year (31.12.2027), not just up to "now"
        // (1.5.2026) or even just the current year.
        $costs = Cost::where('invoice_id', $invoice->id)->orderBy('period_date')->get();
        $this->assertSame(
            ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30',
                '2026-07-31', '2026-08-31', '2026-09-30', '2026-10-31', '2026-11-30', '2026-12-31',
                '2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30', '2027-05-31', '2027-06-30',
                '2027-07-31', '2027-08-31', '2027-09-30', '2027-10-31', '2027-11-30', '2027-12-31'],
            $costs->map(fn ($c) => $c->period_date->toDateString())->all()
        );
    }

    private function seedRecurringRechnung(string $rechnungYaml): void
    {
        $this->seed(CostCategorySeeder::class);

        file_put_contents($this->path, <<<YAML
        lang: "de"

        Account-1:
            Name: "Buchung User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Buchung Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Buchungsweg 1"
                    PLZ: 55555
                    Stadt: "Buchungsstadt"

            Rechnungssteller-1:
                Firma: "Kreditgeber GmbH"
                Straße: ""
                PLZ: 1
                Ort: "Stadt"

            Rechnungen:
                Verwaltungsgruppe-1:
                    Verwaltungseinheit-1:
        $rechnungYaml
        YAML);

        $this->seed(TestDataSeeder::class);
    }

    private function firstInvoiceCosts(): Collection
    {
        $invoice = Invoice::whereHas(
            'invoiceIssuer',
            fn ($q) => $q->where('company_name', 'Kreditgeber GmbH')
        )->first();
        $this->assertNotNull($invoice);

        return Cost::where('invoice_id', $invoice->id)->orderBy('period_date')->get();
    }

    public function test_buchungen_list_upgrades_the_matching_months_forecast_row_to_already_booked(): void
    {
        Carbon::setTestNow('2026-03-15');

        $this->seedRecurringRechnung(<<<'YAML'
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.01.2026
                            Betrag: 100
                            Wiederholung: "monatlich"

                            Zuordnung:
                                Kategorie: "Hauswart"

                            Buchungen:
                                - 5.1.2026
                                - 5.2.2026
        YAML);

        Carbon::setTestNow();

        $costs = $this->firstInvoiceCosts();

        // Die automatische Monatsprognose deckt weiterhin lückenlos Jan-Dez ab (ohne
        // "Ende" bis zum Jahresende, nicht nur bis "heute" = 15.3.2026); Jan/Feb sind aber
        // durch die expliziten Buchungen-Termine "aufgewertet" (gebucht) – das period_date
        // bleibt dabei die reguläre Monatsprognose (1. des Monats), nur booking_date
        // übernimmt das echte, ggf. leicht abweichende Buchungsdatum. Die Liste wird
        // positional zugeordnet (1. Eintrag = 1. Vorkommen usw.), nicht nach dem
        // Kalendermonat des Datums.
        $this->assertCount(24, $costs);
        $this->assertSame(
            ['2026-01-01', '2026-02-01', '2026-03-01', '2026-04-01', '2026-05-01', '2026-06-01',
                '2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01', '2026-11-01', '2026-12-01',
                '2027-01-01', '2027-02-01', '2027-03-01', '2027-04-01', '2027-05-01', '2027-06-01',
                '2027-07-01', '2027-08-01', '2027-09-01', '2027-10-01', '2027-11-01', '2027-12-01'],
            $costs->map(fn ($c) => $c->period_date->toDateString())->all()
        );
        $this->assertTrue($costs->every(fn ($c) => $c->amount == 100));

        [$jan, $feb, $mar] = $costs->all();
        // Anders als die automatisch generierten Vorkommen sind explizite Buchungen bereits
        // gebucht, nicht "offen" – sie stehen für tatsächlich schon erfolgte Buchungen.
        $this->assertFalse($jan->isOpen());
        $this->assertFalse($feb->isOpen());
        $this->assertSame(['2026-01-05', '2026-02-05'], [$jan->booking_date->toDateString(), $feb->booking_date->toDateString()]);
        // März (und alle Folgemonate) haben keinen expliziten Termin: bleiben als offene
        // Prognose stehen (Lücken in "Prognostizierte Ausgaben" sollen so gerade vermieden
        // werden).
        $this->assertTrue($mar->isOpen());
        $this->assertTrue($costs->skip(2)->every(fn ($c) => $c->isOpen()));
    }

    public function test_buchungen_without_a_zuordnung_creates_no_costs(): void
    {
        // Wie bei "F&M": fehlt die Zuordnung, darf niemals automatisch eine Kategorie
        // ergänzt werden – die Buchungstermine bleiben dann ohne Kostenzeile.
        $this->seedRecurringRechnung(<<<'YAML'
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.01.2026
                            Betrag: 100
                            Wiederholung: "monatlich"

                            Buchungen:
                                - 5.1.2026
                                - 5.2.2026
        YAML);

        $this->assertCount(0, $this->firstInvoiceCosts());
    }

    /**
     * Regression guard (reales Beispiel: "Sparkasse Oberlausitz-Niederschlesien -
     * EC-Karte", in mehreren Jahresdateien gleich falsch geschrieben): "Buchungen:
     * 3.1.2026" (ein einzelner Wert statt einer YAML-Liste "Buchungen:\n  - 3.1.2026")
     * wurde bislang genauso behandelt wie ein ganz fehlendes "Buchungen" – die
     * automatische Monatsprognose blieb dauerhaft "offen", obwohl ein Buchungstermin
     * eigentlich angegeben war. Jetzt ein Fehler beim Seeden statt eines
     * stillschweigend ignorierten Formatfehlers.
     */
    public function test_a_scalar_buchungen_value_instead_of_a_list_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->seedRecurringRechnung(<<<'YAML'
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.01.2026
                            Betrag: 100
                            Wiederholung: "jährlich"

                            Zuordnung:
                                Kategorie: "Hauswart"

                            Buchungen: 3.1.2026
        YAML);
    }

    /**
     * Regression ("Warum sind so wenig Müllbuchungen für 2024?"): in
     * "fn4-buchungen-2024/2025.yaml" steht "Wiederholung: Quartal" (großgeschrieben,
     * wie im Deutschen für dieses Substantiv eigentlich korrekt) statt des von
     * mapPeriodicity() erwarteten kleingeschriebenen "quartal" – der bisherige
     * case-sensitive Vergleich fiel dadurch still auf "einmalig" zurück, sodass eine
     * eigentlich vierteljährliche Rechnung nur eine einzige Kostenzeile bekam statt
     * vier. "Wiederholung" wird jetzt unabhängig von Groß-/Kleinschreibung erkannt.
     */
    public function test_wiederholung_is_recognized_case_insensitively(): void
    {
        $this->seedRecurringRechnung(<<<'YAML'
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.01.2026
                            Betrag: 100
                            Wiederholung: "Quartal"

                            Zuordnung:
                                Kategorie: "Hauswart"

                            Buchungen:
                                - 1.1.2026
                                - 1.4.2026
        YAML);

        $invoice = Invoice::whereHas('invoiceIssuer', fn ($q) => $q->where('company_name', 'Kreditgeber GmbH'))->first();
        $this->assertNotNull($invoice);
        $this->assertSame('quarterly', $invoice->periodicity);

        $bookedCosts = $this->firstInvoiceCosts()->filter(fn ($cost) => $cost->booking_date !== null);
        $this->assertCount(2, $bookedCosts, 'both explicit "Buchungen" dates must produce their own booked cost row');
    }

    /**
     * "Niemals stillschweigend Daten überspringen": ein "Wiederholung"-Wert, der zu
     * keinem bekannten Schlüsselwort passt (z. B. ein Tippfehler), darf nicht
     * lautlos als "einmalig" durchgehen.
     */
    public function test_an_unrecognized_wiederholung_value_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->seedRecurringRechnung(<<<'YAML'
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.01.2026
                            Betrag: 100
                            Wiederholung: "Vierteljährlich"

                            Zuordnung:
                                Kategorie: "Hauswart"
        YAML);
    }

    public function test_buchungen_is_ignored_for_an_einmalig_rechnung(): void
    {
        $this->seedRecurringRechnung(<<<'YAML'
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.01.2026
                            Buchungsdatum: 5.1.2026
                            Betrag: 100
                            Wiederholung: "einmalig"

                            Zuordnung:
                                Kategorie: "Hauswart"

                            Buchungen:
                                - 5.1.2026
                                - 5.2.2026
        YAML);

        $costs = $this->firstInvoiceCosts();

        // Die Buchungen-Liste wird ignoriert; stattdessen die normale Einmalig-Logik: eine
        // einzelne, bereits gebuchte Kostenzeile zum Buchungsdatum der Rechnung.
        $this->assertCount(1, $costs);
        $this->assertSame('2026-01-05', $costs->first()->period_date->toDateString());
        $this->assertFalse($costs->first()->isOpen());
    }

    public function test_buchung_entries_with_a_single_category_use_their_own_varying_betrag(): void
    {
        Carbon::setTestNow('2026-01-31');

        // Zwei Buchung-*-Termine, wie bei den echten KFW-Zinszahlungen: die Bank bucht den
        // zweiten Termin schon Ende Januar (30.1.) statt erst Anfang Februar ab. Da die
        // Zuordnung zum Vorkommen *positional* erfolgt (Buchung-0 = 1. Vorkommen = Januar,
        // Buchung-1 = 2. Vorkommen = Februar), landen die beiden Termine trotzdem in
        // getrennten Monaten (period_date), statt beide in Januar gebündelt zu werden –
        // booking_date übernimmt dabei weiterhin das echte, abweichende Buchungsdatum.
        $this->seedRecurringRechnung(<<<'YAML'
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.01.2026
                            Betrag: 199,20
                            Wiederholung: "monatlich"

                            Zuordnung:
                                Kategorie: "Zinsen"

                            Buchung-0:
                                Datum: 2.1.2026
                                Betrag: 141,55
                            Buchung-1:
                                Datum: 30.1.2026
                                Betrag: 140,95
        YAML);

        Carbon::setTestNow();

        $costs = $this->firstInvoiceCosts();

        // Ohne "Ende" reicht die automatische Monatsprognose bis Ende nächsten Jahres (Dez
        // 2027) statt nur bis "heute" – Jan/Feb sind dabei durch die Buchung-*-Termine
        // aufgewertet (gebucht, eigener Betrag). Die Zuordnung hat keinen eigenen festen
        // "Betrag" (nur "Kategorie"), gilt also als "variabel" – März 2026 bis Dezember 2027
        // bleiben offene Prognose-Zeilen, übernehmen dafür aber den letzten bekannten
        // Buchungsbetrag (Februar, 140,95) statt des ungenaueren nominalen
        // Rechnungsbetrags (199,20).
        $this->assertCount(24, $costs);
        [$jan, $feb] = $costs->all();
        $rest = $costs->slice(2);
        $this->assertSame(['2026-01-01', '2026-02-01'], [$jan->period_date->toDateString(), $feb->period_date->toDateString()]);
        $this->assertSame(['2026-01-02', '2026-01-30'], [$jan->booking_date->toDateString(), $feb->booking_date->toDateString()]);
        $this->assertSame([141.55, 140.95], [(float) $jan->amount, (float) $feb->amount]);
        $this->assertFalse($jan->isOpen());
        $this->assertFalse($feb->isOpen());
        $this->assertTrue($costs->every(fn ($c) => $c->costCategory->name === 'Zinsen'));
        $this->assertTrue($rest->every(fn ($c) => $c->isOpen() && (float) $c->amount === 140.95));
    }

    /**
     * A "Buchung-*" entry may use a suffixed "Betrag-1" (no "Betrag-2" sibling) even
     * though its Rechnung's "Zuordnung" only declares a single, unsuffixed "Kategorie" –
     * the real KFW-Zinsen 2022er Daten do exactly this. Must still resolve to that one
     * category via singleZuordnungCategory(), not be silently dropped for lack of a
     * matching "Kategorie-1".
     */
    public function test_a_buchung_entrys_suffixed_betrag_falls_back_to_the_zuordnungs_single_unsuffixed_kategorie(): void
    {
        Carbon::setTestNow('2022-03-15');

        $this->seedRecurringRechnung(<<<'YAML'
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.1.2022
                            Ende: 31.12.2022
                            Betrag: 560
                            Wiederholung: "monatlich"

                            Zuordnung:
                                Kategorie: "Zinsen"

                            Buchung-0:
                                Datum: 1.1.2022
                                Betrag-1: 524,45
                            Buchung-1:
                                Datum: 5.2.2022
                                Betrag-1: 560,65
        YAML);

        Carbon::setTestNow();

        $costs = $this->firstInvoiceCosts();
        [$jan, $feb] = $costs->all();

        $this->assertSame([524.45, 560.65], [(float) $jan->amount, (float) $feb->amount]);
        $this->assertFalse($jan->isOpen());
        $this->assertFalse($feb->isOpen());
        $this->assertTrue($costs->every(fn ($c) => $c->costCategory->name === 'Zinsen'));
    }

    /**
     * A Rechnung's "Buchung-*" list is matched to its occurrences *positionally*
     * (entry 0 = the invoice's very first period, see positionalPeriodDate()) – but
     * only holds if entry 0 really is that first period. A real Kredit-Rechnung
     * ("Hauptfinanzierung Zinsen und Tilgung", Rechnungsdatum 1.4.2024) had no
     * "Buchung-*" entries at all for its first 9 months (never entered in the
     * corresponding Jahresdatei); its first real booking, months later, was then
     * wrongly treated as occurrence 0 and landed 9 periods too early – every later
     * booking inherited the same 9-period offset (e.g. a payment made 1.12.2025 ended
     * up on period_date 2025-03-01 instead of 2025-12-01). Regression test for the fix:
     * the first "Buchung-*" entry now snaps to its own true, calendar-nearest
     * occurrence (nearestOccurrenceIndex()); only entries *after* it stay purely
     * positional relative to that corrected start.
     */
    public function test_a_buchung_entry_list_starting_months_after_the_rechnungsdatum_snaps_to_its_true_occurrence(): void
    {
        Carbon::setTestNow('2026-01-01');

        $this->seedRecurringRechnung(<<<'YAML'
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.4.2024
                            Ende: 31.12.2025
                            Betrag: 999
                            Wiederholung: "monatlich"

                            Zuordnung:
                                Kategorie: "Zinsen"

                            Buchung-0:
                                Datum: 2.1.2025
                                Betrag: 111
                            Buchung-1:
                                Datum: 3.2.2025
                                Betrag: 112
                            Buchung-2:
                                Datum: 2.3.2025
                                Betrag: 113
        YAML);

        Carbon::setTestNow();

        $costs = $this->firstInvoiceCosts();

        // Die 9 Monate April 2024 bis Dezember 2024, für die keine Buchung existiert,
        // bleiben offene Prognosezeilen zum vollen Rechnungsbetrag – keine der drei
        // echten Buchungen darf hier hineinrutschen.
        $unbooked = $costs->filter(fn ($c) => $c->period_date->lt(Carbon::parse('2025-01-01')));
        $this->assertCount(9, $unbooked);
        $this->assertTrue($unbooked->every(fn ($c) => $c->isOpen() && (float) $c->amount === 999.0));

        // Die drei echten Buchungen landen stattdessen auf ihren tatsächlichen Perioden
        // (Januar/Februar/März 2025 – die 9./10./11. Vorkommnis ab dem Rechnungsdatum),
        // nicht auf den ersten drei Vorkommnissen (April/Mai/Juni 2024).
        $jan = $costs->first(fn ($c) => $c->period_date->toDateString() === '2025-01-01');
        $feb = $costs->first(fn ($c) => $c->period_date->toDateString() === '2025-02-01');
        $mar = $costs->first(fn ($c) => $c->period_date->toDateString() === '2025-03-01');
        $this->assertNotNull($jan);
        $this->assertNotNull($feb);
        $this->assertNotNull($mar);

        $this->assertSame(['2025-01-02', '2025-02-03', '2025-03-02'], [
            $jan->booking_date->toDateString(),
            $feb->booking_date->toDateString(),
            $mar->booking_date->toDateString(),
        ]);
        $this->assertSame([111.0, 112.0, 113.0], [(float) $jan->amount, (float) $feb->amount, (float) $mar->amount]);
        $this->assertFalse($jan->isOpen());
        $this->assertFalse($feb->isOpen());
        $this->assertFalse($mar->isOpen());
    }

    /**
     * Regression ("Ich finde die Grundsteuer nicht in der Übersicht von 2024"): eine
     * Rechnung, deren eigenes "Rechnungsdatum" (die 0. Vorkommnis) erst 2025 liegt,
     * aber per "Buchung-*" auch schon ältere, tatsächlich 2024 gezahlte Raten derselben
     * Steuer nachträgt (z. B. eine neue Steuerfestsetzung, die auch die davor schon
     * gezahlten Raten dokumentiert). nearestOccurrenceIndex() zählte bislang nur
     * vorwärts ab der 0. Vorkommnis – eine Buchung VOR dieser ersten Vorkommnis blieb
     * dadurch immer bei Index 0 hängen, sodass mehrere echte, Monate auseinander
     * liegende Buchungen alle auf dieselbe (falsche) Periode UND dasselbe (falsche,
     * ein Jahr zu späte) Abrechnungsintervall fielen, statt auf ihre eigenen,
     * unterschiedlichen früheren Perioden.
     */
    public function test_a_buchung_entry_before_the_rechnungsdatum_gets_its_own_earlier_period_instead_of_collapsing_into_the_first_occurrence(): void
    {
        $this->seedRecurringRechnung(<<<'YAML'
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.4.2025
                            Betrag: 999
                            Wiederholung: "monatlich"

                            Zuordnung:
                                Kategorie: "Zinsen"

                            Buchung-0:
                                Datum: 2.1.2024
                                Betrag: 111
                            Buchung-1:
                                Datum: 3.2.2024
                                Betrag: 112
                            Buchung-2:
                                Datum: 2.3.2024
                                Betrag: 113
        YAML);

        $costs = $this->firstInvoiceCosts();

        $jan = $costs->first(fn ($c) => $c->period_date->toDateString() === '2024-01-01');
        $feb = $costs->first(fn ($c) => $c->period_date->toDateString() === '2024-02-01');
        $mar = $costs->first(fn ($c) => $c->period_date->toDateString() === '2024-03-01');

        // Vor dem Fix landeten alle drei auf period_date = 2025-04-01 (der 0. Vorkommnis).
        $this->assertNotNull($jan, 'the earliest Buchung must not collapse into the invoice\'s first occurrence');
        $this->assertNotNull($feb);
        $this->assertNotNull($mar);
        $this->assertNotSame($jan->id, $feb->id);
        $this->assertNotSame($feb->id, $mar->id);

        $this->assertSame(['2024-01-02', '2024-02-03', '2024-03-02'], [
            $jan->booking_date->toDateString(),
            $feb->booking_date->toDateString(),
            $mar->booking_date->toDateString(),
        ]);
        $this->assertSame([111.0, 112.0, 113.0], [(float) $jan->amount, (float) $feb->amount, (float) $mar->amount]);

        // Und damit auch im richtigen (2024er) statt im falschen (2025er) Abrechnungs
        // intervall verbucht.
        $this->assertSame(2024, $jan->billingInterval->start_date->year);
        $this->assertSame(2024, $feb->billingInterval->start_date->year);
        $this->assertSame(2024, $mar->billingInterval->start_date->year);
    }

    public function test_adding_a_buchung_entry_upgrades_the_still_open_forecast_row_of_the_same_month_in_place(): void
    {
        Carbon::setTestNow('2026-02-15');

        // Erster Lauf ohne explizite Buchungen: die normale automatische Monatsgenerierung
        // legt unbestätigte ("offene") Kostenzeilen zum 1. jedes Monats an.
        $this->seedRecurringRechnung(<<<'YAML'
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.01.2026
                            Buchungsdatum: 1.1.2026
                            Betrag: 199,20
                            Wiederholung: "monatlich"

                            Zuordnung:
                                Kategorie: "Zinsen"
        YAML);

        // Ohne "Ende" reicht die automatische Monatsprognose bis Ende nächsten Jahres (Dez
        // 2027), nicht nur bis "heute" (15.2.2026) oder nur bis Ende des aktuellen Jahres.
        $this->assertCount(24, $this->firstInvoiceCosts());
        $costIdBefore = $this->firstInvoiceCosts()->first()->id;

        // Zweiter Lauf: seeddata.yaml bekommt jetzt einen expliziten Buchung-*-Termin für
        // Januar (z. B. weil der Nutzer die tatsächliche KFW-Buchung ergänzt hat).
        $this->seedRecurringRechnung(<<<'YAML'
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.01.2026
                            Buchungsdatum: 1.1.2026
                            Betrag: 199,20
                            Wiederholung: "monatlich"

                            Zuordnung:
                                Kategorie: "Zinsen"

                            Buchung-0:
                                Datum: 2.1.2026
                                Betrag: 141,55
        YAML);

        Carbon::setTestNow();

        $costs = $this->firstInvoiceCosts();

        // Kein zusätzlicher, neuer Datensatz: die bisherige Januar-Prognosezeile wurde in
        // derselben Datenbankzeile auf den exakten Betrag "aufgewertet" und ist jetzt
        // gebucht (booking_date = das echte Buchungsdatum); ihr period_date bleibt der
        // reguläre Monatserste, Februar bleibt unverändert als offene Prognose stehen.
        $this->assertCount(24, $costs);
        $jan = $costs->first(fn ($c) => $c->period_date->toDateString() === '2026-01-01');
        $feb = $costs->first(fn ($c) => $c->period_date->toDateString() === '2026-02-01');
        $this->assertNotNull($jan);
        $this->assertNotNull($feb);
        $this->assertSame($costIdBefore, $jan->id);
        $this->assertSame(141.55, (float) $jan->amount);
        $this->assertSame('2026-01-02', $jan->booking_date->toDateString());
        $this->assertFalse($jan->isOpen());
        $this->assertTrue($feb->isOpen());
    }

    public function test_a_buchung_entry_never_overwrites_an_already_confirmed_cost_row(): void
    {
        Carbon::setTestNow('2026-01-31');

        $this->seedRecurringRechnung(<<<'YAML'
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.01.2026
                            Buchungsdatum: 1.1.2026
                            Betrag: 199,20
                            Wiederholung: "monatlich"

                            Zuordnung:
                                Kategorie: "Zinsen"
        YAML);

        // Ein Nutzer bestätigt die Januar-Prognosezeile manuell über die Oberfläche, bevor
        // der exakte Buchungstermin in seeddata.yaml ergänzt wird.
        $confirmed = $this->firstInvoiceCosts()->first();
        $confirmed->update(['booking_date' => '2026-01-03']);

        $this->seedRecurringRechnung(<<<'YAML'
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.01.2026
                            Buchungsdatum: 1.1.2026
                            Betrag: 199,20
                            Wiederholung: "monatlich"

                            Zuordnung:
                                Kategorie: "Zinsen"

                            Buchung-0:
                                Datum: 2.1.2026
                                Betrag: 141,55
        YAML);

        Carbon::setTestNow();

        $costs = $this->firstInvoiceCosts();

        // Die bereits bestätigte Zeile bleibt unangetastet; der Buchungstermin legt
        // stattdessen eine eigene, neue Zeile für dasselbe Vorkommen an, statt sie zu
        // überschreiben. Ohne "Ende" deckt die automatische Monatsprognose weiterhin
        // Jan 2026-Dez 2027 ab (24 Zeilen), plus die eine zusätzliche Januar-Zeile aus dem
        // Buchung-*-Termin = 25.
        $this->assertCount(25, $costs);
        $confirmed->refresh();
        $this->assertSame('2026-01-01', $confirmed->period_date->toDateString());
        $this->assertSame(199.2, (float) $confirmed->amount);
        $this->assertSame('2026-01-03', $confirmed->booking_date->toDateString());

        $newRow = $costs->first(fn ($c) => $c->id !== $confirmed->id && $c->period_date->toDateString() === '2026-01-01');
        $this->assertSame('2026-01-02', $newRow->booking_date->toDateString());
        $this->assertSame(141.55, (float) $newRow->amount);
    }

    public function test_a_buchung_a_few_days_late_still_lands_on_its_intended_months_forecast_row(): void
    {
        Carbon::setTestNow('2026-04-15');

        // Wie bei den echten KFW-Zinszahlungen: der zweite Buchungstermin wird von der Bank
        // erst am 3.2. gebucht statt am 1.2., liegt damit aber immer noch klar näher an
        // Februar als an Januar oder März – die Februar-Prognosezeile wird aufgewertet,
        // nicht eine benachbarte.
        $this->seedRecurringRechnung(<<<'YAML'
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.01.2026
                            Betrag: 199,20
                            Wiederholung: "monatlich"

                            Zuordnung:
                                Kategorie: "Zinsen"

                            Buchung-0:
                                Datum: 2.1.2026
                                Betrag: 141,55
                            Buchung-1:
                                Datum: 3.2.2026
                                Betrag: 140,35
        YAML);

        Carbon::setTestNow();

        $costs = $this->firstInvoiceCosts();

        // Ohne "Ende" reicht die Monatsprognose bis Ende nächsten Jahres (Dez 2027), nicht
        // nur bis "heute" (15.4.2026) oder nur bis Ende des aktuellen Jahres.
        $this->assertSame(
            ['2026-01-01', '2026-02-01', '2026-03-01', '2026-04-01', '2026-05-01', '2026-06-01',
                '2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01', '2026-11-01', '2026-12-01',
                '2027-01-01', '2027-02-01', '2027-03-01', '2027-04-01', '2027-05-01', '2027-06-01',
                '2027-07-01', '2027-08-01', '2027-09-01', '2027-10-01', '2027-11-01', '2027-12-01'],
            $costs->map(fn ($c) => $c->period_date->toDateString())->all()
        );
        [$jan, $feb, $mar] = $costs->all();
        $rest = $costs->slice(3);
        $this->assertFalse($jan->isOpen());
        $this->assertSame('2026-01-02', $jan->booking_date->toDateString());
        $this->assertFalse($feb->isOpen());
        $this->assertSame('2026-02-03', $feb->booking_date->toDateString());
        $this->assertSame(140.35, (float) $feb->amount);

        // März und alle folgenden Monate bleiben offen, übernehmen aber Februars zuletzt
        // gebuchten Betrag (140,35) als Prognose statt des nominalen Rechnungsbetrags
        // (199,20) – eine reale, aktuelle Buchung ist die bessere Schätzung.
        $this->assertTrue($mar->isOpen());
        $this->assertSame(140.35, (float) $mar->amount);
        $this->assertTrue($rest->every(fn ($c) => $c->isOpen() && (float) $c->amount === 140.35));
    }

    /**
     * A Buchung more than half a period late (here: booked on the 2nd of the month
     * *after* the one it would nominally belong to, e.g. paid two months instead of one
     * after the previous booking) is closer, calendar-wise, to the *following* occurrence
     * than to its nominally expected one – it lands there instead, leaving the skipped-over
     * month an open forecast row. This is the flip side of self-healing a missing booking
     * (see test_a_buchung_more_than_60_days_after_the_previous_one_snaps_to_its_own_true_occurrence()
     * in TestDataSeederBuchungenTest): the same per-entry, calendar-nearest matching that
     * lets a later Jahresdatei recover from an earlier gap also means an individual booking
     * genuinely late by close to a full period is attributed to whichever occurrence it
     * actually happened nearest to, not to the one a human would have "meant" it for.
     */
    public function test_a_buchung_over_a_month_late_lands_on_the_following_occurrence_leaving_the_skipped_one_open(): void
    {
        Carbon::setTestNow('2026-04-15');

        $this->seedRecurringRechnung(<<<'YAML'
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.01.2026
                            Betrag: 199,20
                            Wiederholung: "monatlich"

                            Zuordnung:
                                Kategorie: "Zinsen"

                            Buchung-0:
                                Datum: 2.1.2026
                                Betrag: 141,55
                            Buchung-1:
                                Datum: 2.3.2026
                                Betrag: 140,35
        YAML);

        Carbon::setTestNow();

        $costs = $this->firstInvoiceCosts();
        [$jan, $feb, $mar] = $costs->all();

        $this->assertFalse($jan->isOpen());
        $this->assertSame('2026-01-02', $jan->booking_date->toDateString());

        // Februar bekommt keine echte Buchung zugeordnet (2.3. liegt näher an März als an
        // Februar) und bleibt offen – statt fälschlich die März-Zahlung zu "leihen".
        $this->assertTrue($feb->isOpen());
        $this->assertSame(199.2, (float) $feb->amount);

        $this->assertFalse($mar->isOpen());
        $this->assertSame('2026-03-02', $mar->booking_date->toDateString());
        $this->assertSame(140.35, (float) $mar->amount);
    }

    /**
     * Nutzeranfrage: *"Wenn eine Buchung variabel ist (z.B. Rechnung 4) dann schreibe die
     * letzte Buchung in die Zukunft fort."* – eine Kredit-Rechnung wie Rechnung-4
     * (Musterbank) hat für ihre Zuordnung keinen eigenen festen "Betrag-N" (ihr
     * Zins-/Tilgungsanteil verschiebt sich jeden Monat leicht, siehe pairedEntries()' "still
     * book with amount 0" fallback), ist also "variabel". Für Monate ohne eigene Buchung
     * soll die Prognose trotzdem einen sinnvollen Betrag zeigen: den zuletzt tatsächlich
     * gebuchten, nicht 0.
     */
    public function test_a_variable_rechnungs_still_open_forecast_rows_carry_forward_the_latest_booked_amount(): void
    {
        Carbon::setTestNow('2026-04-15');

        $this->seedRecurringRechnung(<<<'YAML'
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.01.2026
                            Betrag: 270,42
                            Wiederholung: "monatlich"

                            Zuordnung:
                                Kategorie-1: "Tilgung"
                                Kategorie-2: "Zinsen"

                            Buchung-0:
                                Datum: 2.1.2026
                                Betrag-1: 136,51
                                Betrag-2: 133,91
                            Buchung-1:
                                Datum: 31.1.2026
                                Betrag-1: 136,90
                                Betrag-2: 133,52
                            Buchung-2:
                                Datum: 28.2.2026
                                Betrag-1: 137,30
                                Betrag-2: 133,12
        YAML);

        Carbon::setTestNow();

        $costs = $this->firstInvoiceCosts()->groupBy(fn ($c) => $c->period_date->toDateString());

        // Jan/Feb/März sind gebucht, mit ihren jeweils eigenen, leicht wachsenden bzw.
        // sinkenden Beträgen.
        $mar = $costs['2026-03-01']->keyBy(fn ($c) => $c->costCategory->name);
        $this->assertFalse($mar['Tilgung']->isOpen());
        $this->assertSame(137.30, (float) $mar['Tilgung']->amount);
        $this->assertSame(133.12, (float) $mar['Zinsen']->amount);

        // April 2026 hat keine eigene Buchung – bleibt offen, übernimmt aber den zuletzt
        // gebuchten (März-)Betrag statt 0.
        $apr = $costs['2026-04-01']->keyBy(fn ($c) => $c->costCategory->name);
        $this->assertTrue($apr['Tilgung']->isOpen());
        $this->assertSame(137.30, (float) $apr['Tilgung']->amount);
        $this->assertTrue($apr['Zinsen']->isOpen());
        $this->assertSame(133.12, (float) $apr['Zinsen']->amount);

        // Dasselbe gilt noch für Dezember 2027, den letzten Monat der (jetzt bis Ende
        // nächsten Jahres reichenden) Prognose.
        $dec2027 = $costs['2027-12-01']->keyBy(fn ($c) => $c->costCategory->name);
        $this->assertTrue($dec2027['Tilgung']->isOpen());
        $this->assertSame(137.30, (float) $dec2027['Tilgung']->amount);
        $this->assertTrue($dec2027['Zinsen']->isOpen());
        $this->assertSame(133.12, (float) $dec2027['Zinsen']->amount);
    }

    public function test_buchung_entries_with_multiple_categories_inherit_kategorie_from_zuordnung_positionally(): void
    {
        Carbon::setTestNow('2026-01-31');

        $this->seedRecurringRechnung(<<<'YAML'
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.01.2026
                            Betrag: 270,42
                            Wiederholung: "monatlich"

                            Zuordnung:
                                Kategorie-1: "Tilgung"
                                Kategorie-2: "Zinsen"

                            Buchung-0:
                                Datum: 2.1.2026
                                Betrag-1: 141,35
                                Betrag-2: 129,07
        YAML);

        Carbon::setTestNow();

        $allCosts = $this->firstInvoiceCosts();

        // "Kategorie-N ohne Betrag-N" bucht die Kategorie trotzdem (zunächst mit Betrag 0)
        // an jedem Monatsvorkommen der ohne "Ende" bis Ende nächsten Jahres reichenden
        // Prognose (Jan 2026-Dez 2027 x 2 Kategorien = 48 Zeilen); nur der
        // Januar-Vorkommen wird durch Buchung-0 auf den echten Betrag/Termin aufgewertet.
        $this->assertCount(48, $allCosts);
        $costs = $allCosts->filter(fn ($c) => $c->period_date->toDateString() === '2026-01-01')->keyBy(fn ($c) => $c->costCategory->name);

        $this->assertCount(2, $costs);
        $this->assertSame(141.35, (float) $costs['Tilgung']->amount);
        $this->assertSame(129.07, (float) $costs['Zinsen']->amount);
        $this->assertSame('2026-01-01', $costs['Tilgung']->period_date->toDateString());
        $this->assertSame('2026-01-02', $costs['Tilgung']->booking_date->toDateString());
        $this->assertFalse($costs['Tilgung']->isOpen());
    }

    /**
     * Konzept.md "### Software-Test": "Innerhalb einer Rechnung dürfen nicht
     * unterschiedliche Buchungsschemas gemixt werden können." – erzwungen als
     * Seeding-Fehler statt eines stillen Fallbacks auf die normale automatische
     * Monatsgenerierung (Nutzerfeedback: "Niemals stillschweigend Daten überspringen").
     */
    public function test_mixing_buchungen_and_buchung_star_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->seedRecurringRechnung(<<<'YAML'
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.01.2026
                            Buchungsdatum: 5.1.2026
                            Betrag: 100
                            Wiederholung: "monatlich"

                            Zuordnung:
                                Kategorie: "Hauswart"

                            Buchungen:
                                - 20.1.2026
                            Buchung-0:
                                Datum: 20.2.2026
                                Betrag: 100
        YAML);
    }

    public function test_a_rechnung_can_pin_its_costs_to_a_referenced_abrechnungsintervall(): void
    {
        $this->seed(CostCategorySeeder::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Pin Owner"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Pin VG"

                Intervall-0:
                    Start: 1.1.2026
                    Ende: 31.12.2026
                Intervall-1:
                    Start: 1.1.2025
                    Ende: 31.12.2025

                Verwaltungseinheit-1:
                    Straße: "Pinstraße 1"
                    PLZ: 12345
                    Stadt: "Pinstadt"

            Rechnungssteller-1:
                Firma: "Pin Versorger"
                Ort: "Pinstadt"

            Rechnungen:
                Verwaltungsgruppe-1:
                    Verwaltungseinheit-1:
                        Rechnung-1:
                            Rechnungssteller: Rechnungssteller-1
                            Abrechnungsintervall: Intervall-1
                            Rechnungsdatum: 15.6.2026
                            Buchungsdatum: 15.6.2026
                            Betrag: 120,00
                            Wiederholung: "einmalig"
                            Zuordnung:
                                Kategorie: "Heizkosten"
        YAML);

        $this->seed(TestDataSeeder::class);

        $managementGroup = ManagementGroup::where('description', 'Pin VG')->first();
        $interval2025 = $managementGroup->billingIntervals()->whereYear('start_date', 2025)->first();
        $cost = Cost::first();

        $this->assertNotNull($cost);
        // Despite a 2026 Rechnungs-/Buchungsdatum, the cost is pinned to the referenced
        // 2025 interval, not the one covering its date.
        $this->assertSame($interval2025->id, $cost->billing_interval_id);
    }

    /**
     * Konzept.md "### Rechnung": "Es muss möglich sein eine Rechnung zu splitten. Das
     * Splitten muss auch auf unterschliedliche Abrechnungsintervalle sein." – a single
     * real "Buchung-*" can split its amount across "Betrag-n"/"Kategorie-n" pairs that
     * each additionally carry their own "Abrechnungsintervall-n", pinning just that one
     * split part to a specific Intervall independent of its shared Buchungsdatum.
     */
    public function test_a_buchung_can_split_its_amount_across_different_abrechnungsintervalle(): void
    {
        // "Buchung-*" entries are only evaluated for a RECURRING Rechnung (a "einmalige"
        // one has no "Buchung-*" mechanism at all, see seedInvoice()'s "$isOnce ? [] :
        // ..."), so this uses "quartal" – matching the real-world Grundsteuer scenario
        // that motivated this feature. Frozen "today" keeps the forecast horizon short,
        // so the only two Cost rows this Verwaltungsgruppe ends up with are this one
        // Buchung's two split parts.
        Carbon::setTestNow('2026-01-05');

        $this->seed(CostCategorySeeder::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Split Owner"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Split VG"

                Intervall-0:
                    Start: 1.1.2026
                    Ende: 31.12.2026
                Intervall-1:
                    Start: 1.1.2025
                    Ende: 31.12.2025

            Rechnungssteller-1:
                Firma: "Stadtverwaltung"
                Ort: "Splitstadt"

            Rechnungen:
                Verwaltungsgruppe-1:
                    Rechnung-1:
                        Rechnungssteller: Rechnungssteller-1
                        Rechnungsdatum: 31.12.2025
                        Betrag: 1000
                        Beschreibung: "Grundsteuer Jahreswechsel"
                        Wiederholung: "quartal"
                        Betragsüberprüfung: false
                        Zuordnung:
                            Kategorie: "Öffentliche Lasten"
                        Buchung-0:
                            Datum: 31.12.2025
                            Betrag-0: 600
                            Kategorie-0: "Öffentliche Lasten"
                            Abrechnungsintervall-0: Intervall-1
                            Betrag-1: 400
                            Kategorie-1: "Öffentliche Lasten"
                            Abrechnungsintervall-1: Intervall-0
        YAML);

        $this->seed(TestDataSeeder::class);

        Carbon::setTestNow();

        $managementGroup = ManagementGroup::where('description', 'Split VG')->first();
        $interval2025 = $managementGroup->billingIntervals()->whereYear('start_date', 2025)->first();
        $interval2026 = $managementGroup->billingIntervals()->whereYear('start_date', 2026)->first();

        $costs = Cost::where('costable_type', $managementGroup->getMorphClass())
            ->where('costable_id', $managementGroup->id)
            ->whereDate('period_date', '2025-12-31')
            ->get();

        $this->assertCount(2, $costs);

        $partIn2025 = $costs->firstWhere('billing_interval_id', $interval2025->id);
        $partIn2026 = $costs->firstWhere('billing_interval_id', $interval2026->id);

        $this->assertNotNull($partIn2025);
        $this->assertNotNull($partIn2026);
        $this->assertSame(600.0, (float) $partIn2025->amount);
        $this->assertSame(400.0, (float) $partIn2026->amount);
        $this->assertSame('2025-12-31', $partIn2025->booking_date->toDateString());
        $this->assertSame('2025-12-31', $partIn2026->booking_date->toDateString());
        $this->assertSame(1000.0, (float) $costs->sum('amount'));
    }

    public function test_a_rechnung_can_be_booked_directly_against_a_mietvertrag(): void
    {
        $this->seed(CostCategorySeeder::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Individual Owner"
            Passwort: "geheim123"

            Kategorien:
                Kategorie-0:
                    Name: "Wasserversorgung"
                    Abrechnungsrelevant: true
                    Abrechnungsschlüssel: "Individuell"

            Mieter-1:
                Vorname: "Max"
                Name: "Mustermann"
                Straße: "A"
                PLZ: 1
                Ort: "B"

            Verwaltungsgruppe-1:
                Beschreibung: "Individual VG"

                Verwaltungseinheit-1:
                    Straße: "Individualstraße 1"
                    PLZ: 12345
                    Stadt: "Individualstadt"

                    Mieteinheit-1:
                        Typ: "Wohnung"
                        Größe: 50

                        Mietvertrag-1:
                            MieterId: Mieter-1
                            Beginn: 1.1.2026
                            Mietpreistyp: "Fix"
                            Kaltmiete: 500
                            Nebenkosten: 100

                            Intervall-0:
                                Personen: 1

            Rechnungssteller-1:
                Firma: "Wasserwerk"
                Ort: "Individualstadt"

            Rechnungen:
                Verwaltungsgruppe-1:
                    Verwaltungseinheit-1:
                        Mieteinheit-1:
                            Mietvertrag-1:
                                Rechnung-0:
                                    Rechnungssteller: Rechnungssteller-1
                                    Rechnungsdatum: 1.1.2026
                                    Buchungsdatum: 1.1.2026
                                    Betrag: 240
                                    Wiederholung: "einmalig"
                                    Zuordnung:
                                        Kategorie: "Wasserversorgung"
        YAML);

        $this->seed(TestDataSeeder::class);

        $lease = Lease::first();
        $this->assertNotNull($lease);

        $cost = $lease->costs()->first();
        $this->assertNotNull($cost);
        $this->assertSame(240.0, (float) $cost->amount);
        $this->assertSame('Wasserversorgung', $cost->costCategory->name);
        $this->assertTrue($cost->costable->is($lease));

        // End-to-end: an "individuell" cost booked directly at Mietvertrags-Ebene must
        // actually count toward that lease's own Betriebskostenabrechnung – unlike the
        // same category booked at any of the other three levels, which is ignored there.
        $managementGroup = ManagementGroup::where('description', 'Individual VG')->first();
        $managementUnit = $managementGroup->managementUnits()->first();
        $billingInterval = $managementGroup->billingIntervals()->first();
        $entry = app(BillingStatementLeaseResolver::class)
            ->forInterval($managementUnit, $billingInterval)
            ->first();

        $result = app(BillingCostAllocationService::class)->allocateForLease(
            $managementGroup, $managementUnit, $entry['rentalUnit'], $entry['lease'], $billingInterval,
            $entry['periodStart'], $entry['periodEnd']
        );

        $this->assertSame(240.0, $result['byStage']['lease']['total']);
        $this->assertEqualsWithDelta(240.0, $result['total'], 0.01);
    }

    /**
     * Konzept.md "### Rechnungen": "Ähnlich wie bei einen Mietvertrag können auch
     * Intervalle definiert werden. Als Beispiel wäre hier ein Arbeitsvertrag zu
     * nennen. So kann bei dem selben Arbeitsvertrag eine Lohnerhöhung gezahlt
     * werden." – a monthly Rechnung whose Betrag changes mid-series, sharing the
     * Rechnung's own top-level Zuordnung throughout (no per-Intervall Zuordnung).
     */
    public function test_a_rechnungs_intervalle_change_the_betrag_from_a_given_date_on(): void
    {
        $this->seed(CostCategorySeeder::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Intervall User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Intervall Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Weg 1"
                    PLZ: 11111
                    Stadt: "Stadt"

            Rechnungssteller-1:
                Firma: "Hausmeister GmbH"
                Straße: ""
                PLZ: 1
                Ort: "Stadt"

            Rechnungen:
                Verwaltungsgruppe-1:
                    Verwaltungseinheit-1:
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.1.2026
                            Wiederholung: "monatlich"

                            Zuordnung:
                                Kategorie: "Hauswart"

                            Intervall-0:
                                Anfang: 1.1.2026
                                Ende: 31.3.2026
                                Betrag: 1000
                            Intervall-1:
                                Anfang: 1.4.2026
                                Ende: 30.6.2026
                                Betrag: 1200
                                Notiz: "Lohnerhöhung"
        YAML);

        $this->seed(TestDataSeeder::class);

        $invoice = Invoice::whereHas(
            'invoiceIssuer',
            fn ($q) => $q->where('company_name', 'Hausmeister GmbH')
        )->first();
        $this->assertNotNull($invoice);
        $this->assertSame(1000.0, (float) $invoice->amount);

        $costs = Cost::where('invoice_id', $invoice->id)->orderBy('period_date')->get();
        $this->assertSame(
            ['2026-01-01', '2026-02-01', '2026-03-01', '2026-04-01', '2026-05-01', '2026-06-01'],
            $costs->map(fn ($c) => $c->period_date->toDateString())->all()
        );
        $this->assertSame([1000.0, 1000.0, 1000.0, 1200.0, 1200.0, 1200.0], $costs->map(fn ($c) => (float) $c->amount)->all());
        $this->assertTrue($costs->every(fn ($c) => $c->costCategory->name === 'Hauswart'));
    }

    public function test_an_intervalls_notiz_is_stored_on_its_generated_cost_rows(): void
    {
        $this->seed(CostCategorySeeder::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Notiz User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Notiz Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Weg 1"
                    PLZ: 11111
                    Stadt: "Stadt"

            Rechnungssteller-1:
                Firma: "Hausmeister GmbH"
                Straße: ""
                PLZ: 1
                Ort: "Stadt"

            Rechnungen:
                Verwaltungsgruppe-1:
                    Verwaltungseinheit-1:
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.1.2026
                            Wiederholung: "monatlich"

                            Zuordnung:
                                Kategorie: "Hauswart"

                            Intervall-0:
                                Anfang: 1.1.2026
                                Ende: 28.2.2026
                                Betrag: 1000
                            Intervall-1:
                                Anfang: 1.3.2026
                                Ende: 31.3.2026
                                Betrag: 1200
                                Notiz: "Lohnerhöhung"
        YAML);

        $this->seed(TestDataSeeder::class);

        $costs = Cost::whereHas('invoice.invoiceIssuer', fn ($q) => $q->where('company_name', 'Hausmeister GmbH'))
            ->orderBy('period_date')->get();

        $this->assertNull($costs[0]->note);
        $this->assertNull($costs[1]->note);
        $this->assertSame('Lohnerhöhung', $costs[2]->note);
    }

    /**
     * Regression guard: real bookings via the plain "Buchungen: [Datum, ...]" list used
     * to always take the invoice-wide (only-ever-the-first-Intervall's) Betrag, no
     * matter how far into a later Intervall their own date actually fell – reported
     * against real Testdaten ("Muster, Erika - Minijob"), where every real Buchung from
     * a Lohnerhöhung onward stayed frozen at the OLD Betrag instead of the new one, and
     * even every later still-open forecast month was dragged back down to it by
     * carryForwardLatestBookingIntoFutureForecast().
     */
    public function test_a_buchungen_list_uses_the_betrag_of_whichever_intervall_each_date_falls_into(): void
    {
        $this->seed(CostCategorySeeder::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Intervall Buchungen User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Intervall Buchungen Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Weg 1"
                    PLZ: 11111
                    Stadt: "Stadt"

            Rechnungssteller-1:
                Firma: "Hausmeister GmbH"
                Straße: ""
                PLZ: 1
                Ort: "Stadt"

            Rechnungen:
                Verwaltungsgruppe-1:
                    Verwaltungseinheit-1:
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.1.2026
                            Wiederholung: "monatlich"

                            Zuordnung:
                                Kategorie: "Hauswart"

                            Intervall-0:
                                Anfang: 1.1.2026
                                Ende: 28.2.2026
                                Betrag: 1000
                            Intervall-1:
                                Anfang: 1.3.2026
                                Betrag: 1200
                                Notiz: "Lohnerhöhung"

                            Buchungen:
                                - 5.1.2026
                                - 5.2.2026
                                - 5.3.2026
                                - 5.4.2026
        YAML);

        $this->seed(TestDataSeeder::class);

        $costsByInvoice = Cost::whereHas('invoice.invoiceIssuer', fn ($q) => $q->where('company_name', 'Hausmeister GmbH'))
            ->orderBy('period_date')->get()->keyBy(fn ($c) => $c->period_date->toDateString());

        // The four real Buchungen (5.1./5.2./5.3./5.4.2026) upgrade the matching
        // (day-of-month-1-anchored) forecast row in place – each with the Betrag of
        // whichever Intervall its own date actually falls into, not the invoice-wide
        // (only-ever-the-first-Intervall's) one.
        $this->assertSame(1000.0, (float) $costsByInvoice['2026-01-01']->amount);
        $this->assertSame(1000.0, (float) $costsByInvoice['2026-02-01']->amount);
        $this->assertSame(1200.0, (float) $costsByInvoice['2026-03-01']->amount);
        $this->assertSame(1200.0, (float) $costsByInvoice['2026-04-01']->amount);
        $this->assertTrue($costsByInvoice['2026-01-01']->isOpen() === false);
        $this->assertTrue($costsByInvoice['2026-04-01']->isOpen() === false);

        // Später (nie gebuchte) Prognose-Monate dürfen nicht durch das carryForward auf
        // den zuletzt gebuchten Betrag zurückfallen, sondern bleiben beim jeweils
        // korrekten Intervall-Betrag.
        $this->assertSame(1200.0, (float) $costsByInvoice['2026-05-01']->amount);
        $this->assertTrue($costsByInvoice['2026-05-01']->isOpen());
    }

    /**
     * A per-Intervall "Zuordnung" can also change the Kategorie itself, not just the
     * Betrag – e.g. a Lohnerhöhung that shifts part of the wage into a different
     * Kategorie.
     */
    public function test_each_intervalls_own_zuordnung_can_use_a_different_kategorie(): void
    {
        $this->seed(CostCategorySeeder::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Eigene Zuordnung User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Eigene Zuordnung Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Weg 1"
                    PLZ: 11111
                    Stadt: "Stadt"

            Rechnungssteller-1:
                Firma: "Hausmeister GmbH"
                Straße: ""
                PLZ: 1
                Ort: "Stadt"

            Rechnungen:
                Verwaltungsgruppe-1:
                    Verwaltungseinheit-1:
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.1.2026
                            Wiederholung: "monatlich"

                            Intervall-0:
                                Anfang: 1.1.2026
                                Ende: 31.1.2026
                                Betrag: 1000
                                Zuordnung:
                                    Kategorie: "Hauswart"
                            Intervall-1:
                                Anfang: 1.2.2026
                                Ende: 28.2.2026
                                Betrag: 1200
                                Zuordnung:
                                    Kategorie: "Gartenpflege"
        YAML);

        $this->seed(TestDataSeeder::class);

        $costs = Cost::whereHas('invoice.invoiceIssuer', fn ($q) => $q->where('company_name', 'Hausmeister GmbH'))
            ->orderBy('period_date')->get();

        $this->assertSame(['Hauswart', 'Gartenpflege'], $costs->map(fn ($c) => $c->costCategory->name)->all());
    }

    /**
     * Konzept.md "### Rechnungen": "Wenn ein Enddatum gesetzt ist, ... nehme das
     * [ursprüngliche] Abrechnungsintervall [für alle Buchungen]." – applies to an
     * Intervall-* Rechnung the same way as a plain one: the last Intervall having an
     * Ende means the whole Rechnung (even a Januar-Vorkommen im Folgejahr) stays in
     * the one Abrechnungsintervall active when the erste Intervall begann.
     */
    public function test_intervalle_whose_last_ende_pokes_into_the_next_year_stay_in_the_original_billing_interval(): void
    {
        $this->seed(CostCategorySeeder::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Intervall Jahr User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Intervall Jahr Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Weg 1"
                    PLZ: 11111
                    Stadt: "Stadt"

            Rechnungssteller-1:
                Firma: "Hausmeister GmbH"
                Straße: ""
                PLZ: 1
                Ort: "Stadt"

            Rechnungen:
                Verwaltungsgruppe-1:
                    Verwaltungseinheit-1:
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.11.2026
                            Wiederholung: "monatlich"

                            Zuordnung:
                                Kategorie: "Hauswart"

                            Intervall-0:
                                Anfang: 1.11.2026
                                Ende: 31.1.2027
                                Betrag: 1000
        YAML);

        $this->seed(TestDataSeeder::class);

        $managementGroup = ManagementGroup::where('description', 'Intervall Jahr Gruppe')->first();
        $this->assertNotNull($managementGroup);
        $intervals = $managementGroup->billingIntervals()->get();
        $this->assertCount(1, $intervals);
        $this->assertSame(2026, $intervals->first()->start_date->year);

        $costs = Cost::whereHas('invoice.invoiceIssuer', fn ($q) => $q->where('company_name', 'Hausmeister GmbH'))->get();
        $this->assertSame('2027-01-01', $costs->max(fn ($c) => $c->period_date->toDateString()));
        $this->assertTrue($costs->every(fn ($c) => $c->billing_interval_id === $intervals->first()->id));
    }

    public function test_a_gap_between_intervalle_is_rejected(): void
    {
        $this->seed(CostCategorySeeder::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Lücke User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Lücke Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Weg 1"
                    PLZ: 11111
                    Stadt: "Stadt"

            Rechnungssteller-1:
                Firma: "Hausmeister GmbH"
                Straße: ""
                PLZ: 1
                Ort: "Stadt"

            Rechnungen:
                Verwaltungsgruppe-1:
                    Verwaltungseinheit-1:
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.1.2026
                            Wiederholung: "monatlich"

                            Zuordnung:
                                Kategorie: "Hauswart"

                            Intervall-0:
                                Anfang: 1.1.2026
                                Ende: 31.1.2026
                                Betrag: 1000
                            Intervall-1:
                                Anfang: 15.2.2026
                                Ende: 31.3.2026
                                Betrag: 1200
        YAML);

        $this->expectException(\RuntimeException::class);

        $this->seed(TestDataSeeder::class);
    }

    public function test_a_betrag_on_the_rechnung_itself_together_with_intervalle_is_rejected(): void
    {
        $this->seed(CostCategorySeeder::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Doppelt Betrag User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Doppelt Betrag Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Weg 1"
                    PLZ: 11111
                    Stadt: "Stadt"

            Rechnungssteller-1:
                Firma: "Hausmeister GmbH"
                Straße: ""
                PLZ: 1
                Ort: "Stadt"

            Rechnungen:
                Verwaltungsgruppe-1:
                    Verwaltungseinheit-1:
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.1.2026
                            Betrag: 1000
                            Wiederholung: "monatlich"

                            Zuordnung:
                                Kategorie: "Hauswart"

                            Intervall-0:
                                Anfang: 1.1.2026
                                Betrag: 1000
        YAML);

        $this->expectException(\RuntimeException::class);

        $this->seed(TestDataSeeder::class);
    }

    public function test_a_zuordnung_on_both_the_rechnung_and_an_intervall_is_rejected(): void
    {
        $this->seed(CostCategorySeeder::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Doppelte Zuordnung User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Doppelte Zuordnung Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Weg 1"
                    PLZ: 11111
                    Stadt: "Stadt"

            Rechnungssteller-1:
                Firma: "Hausmeister GmbH"
                Straße: ""
                PLZ: 1
                Ort: "Stadt"

            Rechnungen:
                Verwaltungsgruppe-1:
                    Verwaltungseinheit-1:
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.1.2026
                            Wiederholung: "monatlich"

                            Zuordnung:
                                Kategorie: "Hauswart"

                            Intervall-0:
                                Anfang: 1.1.2026
                                Betrag: 1000
                                Zuordnung:
                                    Kategorie: "Gartenpflege"
        YAML);

        $this->expectException(\RuntimeException::class);

        $this->seed(TestDataSeeder::class);
    }

    public function test_intervalle_without_any_zuordnung_at_all_is_rejected(): void
    {
        $this->seed(CostCategorySeeder::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Keine Zuordnung User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Keine Zuordnung Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Weg 1"
                    PLZ: 11111
                    Stadt: "Stadt"

            Rechnungssteller-1:
                Firma: "Hausmeister GmbH"
                Straße: ""
                PLZ: 1
                Ort: "Stadt"

            Rechnungen:
                Verwaltungsgruppe-1:
                    Verwaltungseinheit-1:
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.1.2026
                            Wiederholung: "monatlich"

                            Intervall-0:
                                Anfang: 1.1.2026
                                Betrag: 1000
        YAML);

        $this->expectException(\RuntimeException::class);

        $this->seed(TestDataSeeder::class);
    }

    public function test_intervalle_are_rejected_for_an_einmalig_rechnung(): void
    {
        $this->seed(CostCategorySeeder::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Einmalig Intervall User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Einmalig Intervall Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Weg 1"
                    PLZ: 11111
                    Stadt: "Stadt"

            Rechnungssteller-1:
                Firma: "Hausmeister GmbH"
                Straße: ""
                PLZ: 1
                Ort: "Stadt"

            Rechnungen:
                Verwaltungsgruppe-1:
                    Verwaltungseinheit-1:
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.1.2026
                            Wiederholung: "einmalig"

                            Zuordnung:
                                Kategorie: "Hauswart"

                            Intervall-0:
                                Anfang: 1.1.2026
                                Betrag: 1000
        YAML);

        $this->expectException(\RuntimeException::class);

        $this->seed(TestDataSeeder::class);
    }

    // KI-generiert
    public function test_an_ende_before_the_anfang_is_rejected_with_the_invoice_in_the_message(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/"Strom 2025".*Das Ende der Wiederholung \(1026-01-31\) liegt vor ihrem Beginn \(2025-03-01\)/');

        $this->seedRecurringRechnung(<<<'YAML'
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 6.2.2025
                            Anfang: 1.3.2025
                            Ende: 31.1.1026
                            Betrag: 28
                            Beschreibung: "Strom 2025"
                            Wiederholung: "monatlich"
                            Zuordnung:
                                Kategorie: "Heizkosten"
        YAML);
    }

    // KI-generiert
    public function test_intervals_ending_before_they_start_are_rejected_too(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('liegt vor ihrem Beginn');

        $this->seedRecurringRechnung(<<<'YAML'
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.1.2026
                            Wiederholung: "monatlich"
                            Zuordnung:
                                Kategorie: "Heizkosten"
                            Intervall-0:
                                Anfang: 1.3.2026
                                Ende: 1.1.2026
                                Betrag: 10
        YAML);
    }
}
