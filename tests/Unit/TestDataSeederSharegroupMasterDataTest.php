<?php

// KI-generiert

namespace Tests\Unit;

use App\Models\CostCategory;
use App\Models\Group;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\ManagementGroup;
use App\Models\Tenant;
use Database\Seeders\CostCategorySeeder;
use Database\Seeders\TestDataSeeder;

/**
 * Mieter, Rechnungssteller und eigene Kategorien unter "Group-*" gehören dieser Gruppe;
 * die Verwaltungsgruppen unter "Groups" entstehen gleich dort (GroupOwnership).
 */
class TestDataSeederSharegroupMasterDataTest extends TestDataSeederTestCase
{
    private const BASE = <<<'YAML'
    lang: "de"

    Account-1:
        Name: "Gruppen Owner"
        Passwort: "geheim123"

        Mieter-1:
            Vorname: "Anna"
            Name: "Privat"
            Straße: "A-Weg 1"
            PLZ: 11111
            Ort: "A-Stadt"

        Rechnungssteller-1:
            Firma: "Privatbank"
            Straße: "Bankweg 1"
            PLZ: 11111
            Ort: "A-Stadt"

        Kategorien:
            Kategorie-1:
                Name: "Privatkosten"
                Abrechnungsrelevant: true
                Abrechnungsschlüssel: "Fläche"

        Verwaltungsgruppe-1:
            Beschreibung: "Privat VG"
            Verwaltungseinheit-1:
                Straße: "Privatweg 1"
                PLZ: 11111
                Stadt: "A-Stadt"
                Mieteinheit-1:
                    Typ: "Wohnung"
                    Größe: 50
                    Abrechnungsrelevant: true
                    Mietvertrag-1:
                        MieterId: Mieter-1
                        Beginn: 2026-01-01
                        Mietpreistyp: "Fix"
                        Intervall-0:
                            Kaltmiete: 500
                            Nebenkosten: 100
                            Personen: 1

        Verwaltungsgruppe-2:
            Beschreibung: "Geteilt VG"
            Verwaltungseinheit-1:
                Straße: "Gemeinsamweg 1"
                PLZ: 22222
                Stadt: "B-Stadt"
                Mieteinheit-1:
                    Typ: "Wohnung"
                    Größe: 60
                    Abrechnungsrelevant: true
                    Mietvertrag-1:
                        MieterId: Mieter-__TENANT__
                        Beginn: 2026-01-01
                        Mietpreistyp: "Fix"
                        Intervall-0:
                            Kaltmiete: 600
                            Nebenkosten: 120
                            Personen: 2

        Group-0:
            Name: "Gemeinschaft GbR"
            Groups:
                - Verwaltungsgruppe-2
    __GROUP__

        Rechnungen:
            Verwaltungsgruppe-2:
                Rechnung-0:
                    Rechnungssteller: Rechnungssteller-__ISSUER__
                    Rechnungsdatum: 12.01.2026
                    Buchungsdatum: 15.01.2026
                    Betrag: 100,00
                    Beschreibung: "Gemeinsame Rechnung"
                    Wiederholung: "einmalig"
                    Zuordnung:
                        Betrag-1: 100,00
                        Kategorie-1: "__CATEGORY__"
    YAML;

    private const GROUP_MASTER_DATA = <<<'YAML'
            Mieter-11:
                Vorname: "Gerd"
                Name: "Gemeinsam"
                Straße: "B-Weg 2"
                PLZ: 22222
                Ort: "B-Stadt"
            Rechnungssteller-12:
                Firma: "Stadtwerke"
                Straße: "Werkweg 1"
                PLZ: 22222
                Ort: "B-Stadt"
                Kundenaccount-0:
                    Kundenummer: 4711
            Kategorien:
                Kategorie-1:
                    Name: "Gemeinschaftskosten"
                    Abrechnungsrelevant: true
                    Abrechnungsschlüssel: "Personen"
    YAML;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CostCategorySeeder::class);
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function writeSeed(array $replacements = []): void
    {
        $replacements += [
            '__GROUP__' => self::GROUP_MASTER_DATA,
            '__TENANT__' => '11',
            '__ISSUER__' => '12',
            '__CATEGORY__' => 'Gemeinschaftskosten',
        ];

        file_put_contents($this->path, strtr(self::BASE, $replacements));
    }

    public function test_master_data_under_a_group_belongs_to_that_group_and_its_management_group(): void
    {
        $this->writeSeed();

        $this->seed(TestDataSeeder::class);

        $sharegroup = Group::where('name', 'Gemeinschaft GbR')->sole();
        $personal = ManagementGroup::where('description', 'Privat VG')->sole()->user_group_id;

        $this->assertSame($sharegroup->id, ManagementGroup::where('description', 'Geteilt VG')->sole()->user_group_id);
        $this->assertSame($sharegroup->id, Tenant::where('last_name', 'Gemeinsam')->sole()->user_group_id);
        $this->assertSame($sharegroup->id, InvoiceIssuer::where('company_name', 'Stadtwerke')->sole()->user_group_id);
        $this->assertSame($sharegroup->id, CostCategory::where('name', 'Gemeinschaftskosten')->sole()->user_group_id);
        $this->assertSame($personal, Tenant::where('last_name', 'Privat')->sole()->user_group_id);
        $this->assertSame($personal, CostCategory::where('name', 'Privatkosten')->sole()->user_group_id);
        $this->assertSame('4711', Invoice::where('description', 'Gemeinsame Rechnung')->sole()->invoiceIssuer->accounts->sole()->customer_number);
    }

    public function test_allocation_keys_apply_only_where_the_category_may_be_used(): void
    {
        $this->writeSeed();

        $this->seed(TestDataSeeder::class);

        $private = ManagementGroup::where('description', 'Privat VG')->sole();
        $shared = ManagementGroup::where('description', 'Geteilt VG')->sole();

        $this->assertTrue($private->costCategories()->where('name', 'Privatkosten')->exists());
        $this->assertFalse($shared->costCategories()->where('name', 'Privatkosten')->exists());
        $this->assertSame('person_count', $shared->costCategories()->where('name', 'Gemeinschaftskosten')->sole()->pivot->allocation_key);
        $this->assertFalse($private->costCategories()->where('name', 'Gemeinschaftskosten')->exists());
    }

    public function test_reseeding_keeps_everything_in_place(): void
    {
        $this->writeSeed();

        $this->seed(TestDataSeeder::class);
        $this->seed(TestDataSeeder::class);

        $this->assertSame(1, Tenant::where('last_name', 'Gemeinsam')->count());
        $this->assertSame(1, ManagementGroup::where('description', 'Geteilt VG')->count());
        $this->assertSame(1, Invoice::where('description', 'Gemeinsame Rechnung')->count());
    }

    public function test_a_personal_tenant_in_a_shared_management_group_aborts_with_a_hint(): void
    {
        $this->writeSeed(['__TENANT__' => '1']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Account-1: Mieter „Anna Privat“ gehört zur Gruppe .* gehören in diese Group-\*/s');

        $this->seed(TestDataSeeder::class);
    }

    public function test_a_personal_issuer_in_a_shared_management_group_aborts(): void
    {
        $this->writeSeed(['__ISSUER__' => '1']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Rechnungssteller „Privatbank“ gehört zur Gruppe/');

        $this->seed(TestDataSeeder::class);
    }

    public function test_a_personal_category_in_a_shared_management_group_is_unknown_there(): void
    {
        $this->writeSeed(['__CATEGORY__' => 'Privatkosten']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unbekannte Kategorie "Privatkosten"\. Eigene Kategorien .* Group-\*/');

        $this->seed(TestDataSeeder::class);
    }

    public function test_a_key_defined_on_the_account_and_under_a_group_aborts(): void
    {
        $this->writeSeed(['__GROUP__' => str_replace('Mieter-11:', 'Mieter-1:', self::GROUP_MASTER_DATA), '__TENANT__' => '1']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Mieter-1 ist mehrfach angegeben (Account und Group-0)');

        $this->seed(TestDataSeeder::class);
    }

    public function test_a_management_group_in_two_groups_aborts(): void
    {
        $this->writeSeed(['__GROUP__' => self::GROUP_MASTER_DATA."\n    Group-1:\n        Name: \"Zweite\"\n        Groups:\n            - Verwaltungsgruppe-2"]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('steht unter "Groups" mehrerer Group-Einträge');

        $this->seed(TestDataSeeder::class);
    }

    public function test_unknown_keys_under_a_group_abort_before_master_data_is_seeded(): void
    {
        $this->writeSeed(['__GROUP__' => self::GROUP_MASTER_DATA."\n        Buchungen:\n            from-File: \"buchungen.yaml\"\n        Rechnungen:\n            Verwaltungsgruppe-2: {}"]);

        try {
            $this->seed(TestDataSeeder::class);
            $this->fail('Der Seed hätte abbrechen müssen.');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('Group-0: unbekannte Einträge "Buchungen", "Rechnungen". Erlaubt sind: Name, Admins, Users, Groups, Templates, Kategorien, Mieter, Rechnungssteller.', $e->getMessage());
            $this->assertStringContainsString('Buchungen stehen auf Account-Ebene', $e->getMessage());
        }

        $this->assertSame(0, Tenant::count());
    }

    public function test_an_unknown_key_under_a_bare_group_names_it_group(): void
    {
        $this->writeSeed(['Group-0:' => 'Group:', '__GROUP__' => self::GROUP_MASTER_DATA."\n        Notiz: \"x\""]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Group: unbekannte Einträge "Notiz".');

        $this->seed(TestDataSeeder::class);
    }

    public function test_a_bare_group_accepts_a_wrapped_tenant_block(): void
    {
        $wrappedTenant = "        Mieter:\n            Mieter-11:\n                Vorname: \"Gerd\"\n                Name: \"Gemeinsam\"\n"
            ."                Straße: \"B-Weg 2\"\n                PLZ: 22222\n                Ort: \"B-Stadt\"\n";
        $rest = substr(self::GROUP_MASTER_DATA, (int) strpos(self::GROUP_MASTER_DATA, '        Rechnungssteller-12:'));
        $this->writeSeed(['Group-0:' => 'Group:', '__GROUP__' => $wrappedTenant.$rest]);

        $this->seed(TestDataSeeder::class);

        $this->assertSame(Group::where('name', 'Gemeinschaft GbR')->sole()->id, Tenant::where('last_name', 'Gemeinsam')->sole()->user_group_id);
    }

    public function test_a_group_without_a_name_aborts_before_anything_is_seeded(): void
    {
        $this->writeSeed(['__GROUP__' => self::GROUP_MASTER_DATA."\n    Group-1:\n        Groups: []"]);

        try {
            $this->seed(TestDataSeeder::class);
            $this->fail('Der Seed hätte abbrechen müssen.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Ein Group-Eintrag (Sharegroup) hat keinen Namen.', $e->getMessage());
        }

        $this->assertSame(0, Tenant::count());
    }
}
