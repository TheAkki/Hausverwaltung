<?php

namespace Tests\Unit;

use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\Landlord;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\Tenant;
use Database\Seeders\CostCategorySeeder;
use Database\Seeders\TestDataSeeder;

/**
 * Covers SeedsContacts: Mieter, Rechnungssteller and Vermieter (account-level and
 * Verwaltungsgruppen-Override) seeding.
 */
class TestDataSeederContactsTest extends TestDataSeederTestCase
{
    public function test_it_reads_the_additional_tenant_and_lease_contact_fields(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Contact Fields User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Contact Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Kontaktweg 1"
                    PLZ: 13579
                    Stadt: "Kontaktstadt"

                    Mieteinheit-1:
                        Typ: "Wohnung"
                        Größe: 55
                        Beschreibung: "Whg D"
                        Abrechnungsrelevant: true

                        Mietvertrag-1:
                            MieterId: Mieter-1
                            Beginn: 2026-01-01
                            Mietpreistyp: "Fix"
                            Kaltmiete: 500
                            Nebenkosten: 100
                            Kaution: 1000 €
                            MinLaufzeit: 12
                            Notiz: "Haustier erlaubt"
                            Kundigungsfrist: "3 Monate zum Quartalsende"

            Mieter-1:
                Firma: "Musterfirma GmbH"
                Vorname: "Peter"
                Name: "Fünfte"
                Straße: "E-Weg 5"
                PLZ: 24680
                Ort: "E-Stadt"
                Email: peter.fuenfte@example.com
                Mobilfunk: "+49 170 1234567"
        YAML);

        $this->seed(TestDataSeeder::class);

        $tenant = Tenant::where('last_name', 'Fünfte')->first();
        $this->assertNotNull($tenant);
        $this->assertSame('Musterfirma GmbH', $tenant->company_name);
        $this->assertSame('peter.fuenfte@example.com', $tenant->email);
        $this->assertSame('+49 170 1234567', $tenant->mobile_phone);

        $lease = Lease::whereHas('rentalUnit', fn ($q) => $q->where('description', 'Whg D'))->first();
        $this->assertNotNull($lease);
        $this->assertSame(1000.0, (float) $lease->deposit_amount);
        $this->assertSame(12, $lease->min_term_months);
        $this->assertSame('Haustier erlaubt', $lease->notes);
        $this->assertSame('3 Monate zum Quartalsende', $lease->notice_period);
    }

    /**
     * Konzept.md documents "Mieter"/"Rechnungssteller" as an optional grouping wrapper
     * around the individual "Mieter-*"/"Rechnungssteller-*" entries ("[Mieter]: [Mieter-*]:
     * …"), not just the flat "Mieter-*" directly under Account – both forms must seed
     * identically.
     */
    public function test_mieter_and_rechnungssteller_wrapped_in_their_own_grouping_key_are_seeded(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Wrapped Contacts User"
            Passwort: "geheim123"

            Mieter:
                Mieter-1:
                    Vorname: "Emil"
                    Name: "Muster"
                    Straße: "Wrappedweg 1"
                    PLZ: 11111
                    Ort: "Wrappedstadt"

            Rechnungssteller:
                Rechnungssteller-1:
                    Firma: "Wrapped Versorger GmbH"
                    Straße: "Wrappedweg 2"
                    PLZ: 11111
                    Ort: "Wrappedstadt"
        YAML);

        $this->seed(TestDataSeeder::class);

        $tenant = Tenant::where('last_name', 'Muster')->first();
        $this->assertNotNull($tenant);
        $this->assertSame('Emil', $tenant->first_name);

        $issuer = InvoiceIssuer::where('name', 'Wrapped Versorger GmbH')->first();
        $this->assertNotNull($issuer);
    }

    public function test_it_imports_a_tenant_without_a_vorname(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "No Vorname User"
            Passwort: "geheim123"

            Mieter-1:
                Name: "Exempel"
                Straße: "Ohne-Vorname-Weg 6"
                PLZ: 22222
                Ort: "Musterstadt"
        YAML);

        $this->seed(TestDataSeeder::class);

        $tenant = Tenant::where('last_name', 'Exempel')->first();
        $this->assertNotNull($tenant);
        $this->assertNull($tenant->first_name);
        $this->assertSame('Exempel', $tenant->fullName());
    }

    public function test_it_imports_a_rechnungssteller_identified_only_by_firma(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Issuer User"
            Passwort: "geheim123"

            Rechnungssteller-1:
                Firma: "Concordia Wohngebäudeversicherung"
                Straße: ""
                PLZ: 30621
                Ort: Hannover

                Kundenaccount-0:
                    Kundenummer: "FW MA/27315/000/2575098-5"
                    Lastschrift: true
        YAML);

        $this->seed(TestDataSeeder::class);

        $issuer = InvoiceIssuer::where('company_name', 'Concordia Wohngebäudeversicherung')->first();
        $this->assertNotNull($issuer);
        $this->assertSame('Concordia Wohngebäudeversicherung', $issuer->name);
        $this->assertSame('Hannover', $issuer->city);

        $account = $issuer->accounts()->first();
        $this->assertNotNull($account);
        $this->assertSame('FW MA/27315/000/2575098-5', $account->customer_number);
        $this->assertTrue($account->direct_debit);
    }

    public function test_a_kundenaccounts_notizen_field_is_seeded_as_notes(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Notizen User"
            Passwort: "geheim123"

            Rechnungssteller-1:
                Firma: "Beispielbank"
                PLZ: 63438
                Ort: "Hanau"

                Kundenaccount-0:
                    Kundenummer: "7822216 04"
                    Beschreibung: "KFW"
                    Notizen: "IBAN: DE25 8207 0324 0415 820001"
        YAML);

        $this->seed(TestDataSeeder::class);

        $account = InvoiceIssuer::where('company_name', 'Beispielbank')->first()?->accounts->first();
        $this->assertNotNull($account);
        $this->assertSame('IBAN: DE25 8207 0324 0415 820001', $account->notes);
    }

    public function test_a_rechnungssteller_without_an_ort_is_still_seeded_and_its_rechnung_is_not_dropped(): void
    {
        $this->seed(CostCategorySeeder::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Issuer Without City User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Weg 1"
                    PLZ: 11111
                    Stadt: "Stadt"

            Rechnungssteller-1:
                Firma: "Sparkasse Testbank"

                Kundenaccount-0:
                    Kundenummer: 0
                    Beschreibung: "Kredit"
                    Lastschrift: true

            Rechnungen:
                Verwaltungsgruppe-1:
                    Verwaltungseinheit-1:
                        Rechnung-0:
                            Rechnungssteller: Rechnungssteller-1
                            Rechnungsdatum: 1.01.2026
                            Buchungsdatum: 1.1.2026
                            Betrag: 270,42
                            Wiederholung: "monatlich"
        YAML);

        $this->seed(TestDataSeeder::class);

        $issuer = InvoiceIssuer::where('company_name', 'Sparkasse Testbank')->first();
        $this->assertNotNull($issuer);
        $this->assertSame('', $issuer->city);

        $invoice = Invoice::whereHas(
            'invoiceIssuer',
            fn ($q) => $q->where('company_name', 'Sparkasse Testbank')
        )->first();
        $this->assertNotNull($invoice);
        $this->assertSame(270.42, (float) $invoice->amount);
    }

    public function test_an_account_level_vermieter_and_a_verwaltungsgruppe_override_are_seeded(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Vermieter Owner"
            Passwort: "geheim123"

            Vermieter:
                Anrede: "Herr"
                Vorname: "Erwin"
                Name: "Eigentümer"
                Straße: "Verwalterweg 1"
                PLZ: 12345
                Ort: "Musterstadt"

            Verwaltungsgruppe-1:
                Beschreibung: "Vermieter VG ohne Override"

            Verwaltungsgruppe-2:
                Beschreibung: "Vermieter VG mit Override"
                Vermieter:
                    Firma: "Sonder-GbR"
        YAML);

        $this->seed(TestDataSeeder::class);

        $vgDefault = ManagementGroup::where('description', 'Vermieter VG ohne Override')->first();
        $vgOverride = ManagementGroup::where('description', 'Vermieter VG mit Override')->first();

        // Account-level Vermieter applies to the group without its own override.
        $this->assertSame('Erwin Eigentümer', $vgDefault->effectiveLandlord()->fullName());
        $this->assertSame('Herr', $vgDefault->effectiveLandlord()->salutation);

        // The other group's own override takes precedence (Firma as name).
        $this->assertSame('Sonder-GbR', $vgOverride->effectiveLandlord()->name);
        $this->assertSame($vgOverride->id, $vgOverride->effectiveLandlord()->management_group_id);

        // Exactly one account-level + one override landlord seeded.
        $this->assertSame(1, Landlord::whereNotNull('user_group_id')->count());
        $this->assertSame(1, Landlord::whereNotNull('management_group_id')->count());
    }

    /**
     * Regression test: the real seeddata.yaml declares the Vermieter as "Vermieter-0"
     * (keyed like "Konto-*"/"Template-*", per the Konzept.md-Schema "Vermieter-*"), not a
     * bare "Vermieter" key. seedAccount()/seedManagementGroup() used to only check
     * isset($data['Vermieter']), silently seeding no Landlord at all for this format.
     */
    public function test_a_vermieter_declared_with_the_keyed_vermieter_dash_n_form_is_seeded(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Vermieter Keyed Owner"
            Passwort: "geheim123"

            Vermieter-0:
                Name: "Keyed Eigentümer"
                Straße: "Verwalterweg 1"
                PLZ: 12345
                Ort: "Musterstadt"

            Verwaltungsgruppe-1:
                Beschreibung: "Vermieter Keyed VG"
                Vermieter-0:
                    Firma: "Keyed-GbR"
        YAML);

        $this->seed(TestDataSeeder::class);

        $managementGroup = ManagementGroup::where('description', 'Vermieter Keyed VG')->first();

        $this->assertSame(1, Landlord::whereNotNull('user_group_id')->count());
        $this->assertSame('Keyed-GbR', $managementGroup->effectiveLandlord()->name);
    }

    /**
     * Regression guards for Nutzerfeedback "Niemals stillschweigend Daten
     * überspringen" – a Mieter/Rechnungssteller/Vermieter/Kundenaccount entry missing
     * its Pflichtangaben used to be silently dropped instead of aborting the seed run.
     */
    public function test_a_mieter_without_a_strasse_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Mieter Ohne Strasse User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Gruppe"

            Mieter-1:
                Name: "Nachname"
                PLZ: 11111
                Ort: "Stadt"
        YAML);

        $this->seed(TestDataSeeder::class);
    }

    public function test_a_rechnungssteller_without_a_name_or_firma_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Rechnungssteller Ohne Name User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Gruppe"

            Rechnungssteller-1:
                Straße: "Weg 1"
                PLZ: 1
                Ort: "Stadt"
        YAML);

        $this->seed(TestDataSeeder::class);
    }

    public function test_a_kundenaccount_without_a_kundenummer_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Kundenaccount Ohne Nummer User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Gruppe"

            Rechnungssteller-1:
                Firma: "Versorger GmbH"
                Straße: ""
                PLZ: 1
                Ort: "Stadt"

                Kundenaccount-0:
                    Beschreibung: "Ohne Nummer"
        YAML);

        $this->seed(TestDataSeeder::class);
    }

    public function test_a_vermieter_without_a_name_or_firma_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Vermieter Ohne Name User"
            Passwort: "geheim123"

            Vermieter-0:
                Straße: "Weg 1"
                PLZ: 1
                Ort: "Stadt"

            Verwaltungsgruppe-1:
                Beschreibung: "Gruppe"
        YAML);

        $this->seed(TestDataSeeder::class);
    }
}
