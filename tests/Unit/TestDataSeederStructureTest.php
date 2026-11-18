<?php

namespace Tests\Unit;

use App\Models\CostCategory;
use App\Models\Group;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\ManagementUnit;
use App\Models\RentalUnit;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\CostCategorySeeder;
use Database\Seeders\TestDataSeeder;
use Illuminate\Support\Facades\Hash;

/**
 * Covers TestDataSeeder's top-level orchestration: Account/Sharegroup/
 * Verwaltungsgruppe/Verwaltungseinheit/Mieteinheit structure, Kategorien and
 * Abrechnungsintervalle seeding, and idempotent re-seeding.
 */
class TestDataSeederStructureTest extends TestDataSeederTestCase
{
    public function test_missing_file_does_not_error(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }

        $this->seed(TestDataSeeder::class);

        $this->assertSame(0, User::count());
    }

    public function test_empty_file_does_not_error(): void
    {
        file_put_contents($this->path, '');

        $this->seed(TestDataSeeder::class);

        $this->assertSame(0, User::count());
    }

    public function test_it_creates_the_full_hierarchy_from_yaml(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Seed Test User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Seed Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Teststraße 9"
                    PLZ: 11111
                    Stadt: "Testort"
                    Beschreibung: "Haus 9"

                    Mieteinheit-1:
                        Typ: "Wohnung"
                        Größe: 42
                        Beschreibung: "Dachgeschoss"
                        Abrechnungsrelevant: true

                    Mieteinheit-2:
                        Typ: "Stellplatz"
                        Größe: null
                        Beschreibung: "Platz 3"
                        Abrechnungsrelevant: false

            Mieter-1:
                Anrede: "Frau"
                Vorname: "Erika"
                Name: "Musterfrau"
                Straße: "Musterweg 3"
                PLZ: 33333
                Ort: "Musterhausen"
                IBAN: "DE00 1234 5678 9012 3456 00"
                BIC: "TESTBIC1"
        YAML);

        $this->seed(TestDataSeeder::class);

        $user = User::where('name', 'Seed Test User')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('geheim123', $user->password));
        $this->assertNotNull($user->groups()->first());

        $managementGroup = ManagementGroup::where('description', 'Seed Gruppe')->first();
        $this->assertNotNull($managementGroup);
        $this->assertSame(1, $managementGroup->billingIntervals()->count());

        $managementUnit = ManagementUnit::where('street', 'Teststraße 9')->first();
        $this->assertNotNull($managementUnit);
        $this->assertSame('11111', $managementUnit->postal_code);
        $this->assertSame('Testort', $managementUnit->city);

        $this->assertSame(2, RentalUnit::where('management_unit_id', $managementUnit->id)->count());

        $dachgeschoss = RentalUnit::where('description', 'Dachgeschoss')->first();
        $this->assertSame(42.0, (float) $dachgeschoss->size);
        $this->assertTrue($dachgeschoss->is_relevant_for_billing);

        $stellplatz = RentalUnit::where('description', 'Platz 3')->first();
        $this->assertNull($stellplatz->size);
        $this->assertFalse($stellplatz->is_relevant_for_billing);

        $tenant = Tenant::where('first_name', 'Erika')->where('last_name', 'Musterfrau')->first();
        $this->assertNotNull($tenant);
        $this->assertSame('Frau', $tenant->salutation);
        $this->assertSame('33333', $tenant->postal_code);
        $this->assertSame('Musterhausen', $tenant->city);
        $this->assertSame('DE00 1234 5678 9012 3456 00', $tenant->iban);
        $this->assertSame('TESTBIC1', $tenant->bic);
    }

    public function test_running_the_seeder_twice_does_not_duplicate_data(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Repeat User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Repeat Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Wiederholweg 1"
                    PLZ: 22222
                    Stadt: "Wiederholstadt"

                    Mieteinheit-1:
                        Typ: "Wohnung"
                        Größe: 50
                        Beschreibung: "Whg 1"
                        Abrechnungsrelevant: true

                        Mietvertrag-1:
                            MieterId: Mieter-1
                            Beginn: 2026-01-01
                            Mietpreistyp: "Fix"

                            Intervall-0:
                                Kaltmiete: 400
                                Nebenkosten: 80
                                Personen: 1

            Mieter-1:
                Vorname: "Rita"
                Name: "Vierte"
                Straße: "D-Weg 4"
                PLZ: 99999
                Ort: "D-Stadt"
        YAML);

        $this->seed(TestDataSeeder::class);
        $this->seed(TestDataSeeder::class);

        $this->assertSame(1, User::where('name', 'Repeat User')->count());
        $this->assertSame(1, ManagementGroup::where('description', 'Repeat Gruppe')->count());
        $this->assertSame(1, ManagementUnit::where('street', 'Wiederholweg 1')->count());
        $this->assertSame(1, RentalUnit::where('description', 'Whg 1')->count());

        $lease = Lease::whereHas('rentalUnit', fn ($q) => $q->where('description', 'Whg 1'))->first();
        $this->assertNotNull($lease);
        $this->assertSame(1, $lease->leaseIntervals()->count());
    }

    public function test_kategorien_entries_seed_custom_cost_categories(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Kategorie Seed User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Kategorie Gruppe"

            Kategorien:
                Kategorie-1:
                    Name: "Zinsen"
                    Abrechnungsrelevant: false
                Kategorie-2:
                    Name: "Sonderumlage"
                    Abrechnungsrelevant: true
                    Abrechnungsschlüssel: "Individual"
        YAML);

        $this->seed(TestDataSeeder::class);

        $zinsen = CostCategory::where('name', 'Zinsen')->first();
        $this->assertNotNull($zinsen);
        $this->assertFalse($zinsen->is_relevant_for_billing);

        $sonderumlage = CostCategory::where('name', 'Sonderumlage')->first();
        $this->assertNotNull($sonderumlage);
        $this->assertTrue($sonderumlage->is_relevant_for_billing);

        $managementGroup = ManagementGroup::where('description', 'Kategorie Gruppe')->first();
        $pivot = $managementGroup->costCategories()->where('cost_category_id', $sonderumlage->id)->first();
        $this->assertSame('individual', $pivot->pivot->allocation_key);

        // Nicht abrechnungsrelevante Kategorien brauchen keinen Abrechnungsschlüssel: kein
        // Abrechnungsschlüssel angegeben, also auch keine Zuordnung angelegt.
        $this->assertNull($managementGroup->costCategories()->where('cost_category_id', $zinsen->id)->first());
    }

    /**
     * Nutzerhinweis ("Warum sehe ich in dieser Übersicht Heizung und Heizkosten? Die
     * Kategorie Heizung hat der Account gar nicht."): eine per "Kategorien:" selbst
     * definierte Kategorie ist auf den Account beschränkt, der sie anlegt – ein anderer
     * Account, der zufällig denselben (nicht systemweiten) Namen verwendet, bekommt eine
     * eigene, unabhängige Kategorie statt versehentlich dieselbe wiederzuverwenden.
     */
    public function test_two_accounts_defining_the_same_custom_kategorie_name_get_separate_categories(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Kategorie Account Eins"
            Passwort: "geheim123"

            Kategorien:
                Kategorie-1:
                    Name: "Sonderposten"
                    Abrechnungsrelevant: true

        Account-2:
            Name: "Kategorie Account Zwei"
            Passwort: "geheim123"

            Kategorien:
                Kategorie-1:
                    Name: "Sonderposten"
                    Abrechnungsrelevant: false
        YAML);

        $this->seed(TestDataSeeder::class);

        $categories = CostCategory::where('name', 'Sonderposten')->get();
        $this->assertCount(2, $categories);

        $groupEins = Group::where('name', 'Gruppe von Kategorie Account Eins')->first();
        $groupZwei = Group::where('name', 'Gruppe von Kategorie Account Zwei')->first();

        $this->assertSame(
            $groupEins->id,
            $categories->firstWhere('is_relevant_for_billing', true)->user_group_id
        );
        $this->assertSame(
            $groupZwei->id,
            $categories->firstWhere('is_relevant_for_billing', false)->user_group_id
        );
    }

    /**
     * Anders als eine eigene Kategorie: verwendet ein Account eine bereits systemweite
     * (CostCategorySeeder) Kategorie erneut, wird diese wiederverwendet statt dupliziert
     * – "Heizkosten" bleibt genau eine, systemweite Zeile.
     */
    public function test_reusing_a_systemweite_kategorie_name_does_not_duplicate_it(): void
    {
        $this->seed(CostCategorySeeder::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Kategorie Seed User"
            Passwort: "geheim123"

            Kategorien:
                Kategorie-1:
                    Name: "Heizkosten"
                    Abrechnungsrelevant: true
        YAML);

        $this->seed(TestDataSeeder::class);

        $categories = CostCategory::where('name', 'Heizkosten')->get();
        $this->assertCount(1, $categories);
        $this->assertNull($categories->first()->user_group_id);
    }

    public function test_kategorien_entries_default_to_tax_relevant_but_can_opt_out(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Steuer Seed User"
            Passwort: "geheim123"

            Kategorien:
                Kategorie-1:
                    Name: "Nicht-Werbungskosten-Kategorie"
                    Abrechnungsrelevant: false
                    Steuer: false
                Kategorie-2:
                    Name: "Werbungskosten-Kategorie"
                    Abrechnungsrelevant: false
        YAML);

        $this->seed(TestDataSeeder::class);

        $this->assertFalse(CostCategory::where('name', 'Nicht-Werbungskosten-Kategorie')->first()->is_tax_relevant);
        // "Steuer" ist optional und defaultet auf true, analog "Abrechnungsrelevant".
        $this->assertTrue(CostCategory::where('name', 'Werbungskosten-Kategorie')->first()->is_tax_relevant);
    }

    public function test_the_mieteinheit_abrechnungsschluessel_maps_to_the_rental_unit_allocation_key(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Kategorie Seed User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Kategorie Gruppe"

            Kategorien:
                Kategorie-1:
                    Name: "Grundsteuer"
                    Abrechnungsrelevant: true
                    Abrechnungsschlüssel: "Mieteinheit"
        YAML);

        $this->seed(TestDataSeeder::class);

        $category = CostCategory::where('name', 'Grundsteuer')->first();
        $managementGroup = ManagementGroup::where('description', 'Kategorie Gruppe')->first();
        $pivot = $managementGroup->costCategories()->where('cost_category_id', $category->id)->first();

        $this->assertSame('rental_unit', $pivot->pivot->allocation_key);
    }

    public function test_a_manually_set_allocation_key_is_overwritten_on_reseed(): void
    {
        // Konzept.md "### Kategorie": "Wenn der Name schon existiert wird der
        // Abrechnungsschlüssel überschrieben" – a reseed re-applies seeddata.yaml's
        // Abrechnungsschlüssel even over a value someone changed via the Oberfläche.
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Kategorie Seed User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Kategorie Gruppe"

            Kategorien:
                Kategorie-1:
                    Name: "Sonderumlage"
                    Abrechnungsrelevant: true
                    Abrechnungsschlüssel: "Individual"
        YAML);

        $this->seed(TestDataSeeder::class);

        $sonderumlage = CostCategory::where('name', 'Sonderumlage')->first();
        $managementGroup = ManagementGroup::where('description', 'Kategorie Gruppe')->first();
        $managementGroup->costCategories()->updateExistingPivot($sonderumlage->id, ['allocation_key' => 'area']);

        $this->seed(TestDataSeeder::class);

        $pivot = $managementGroup->costCategories()->where('cost_category_id', $sonderumlage->id)->first();
        $this->assertSame('individual', $pivot->pivot->allocation_key);
    }

    /**
     * Regression: "Zinsen" is one of CostCategorySeeder's own standard categories, so
     * it always already exists by the time seedCategories() runs – firstOrCreate()'s
     * own "is_tax_relevant" default therefore never had a chance to take effect for
     * it. "Steuer" must behave like "Abrechnungsschlüssel" and be applied on every
     * run, not just at creation.
     */
    public function test_steuer_is_applied_on_reseed_even_for_an_already_existing_standard_category(): void
    {
        $this->seed(CostCategorySeeder::class);
        $zinsen = CostCategory::where('name', 'Zinsen')->first();
        $this->assertTrue($zinsen->is_tax_relevant);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Steuer Reseed User"
            Passwort: "geheim123"

            Kategorien:
                Kategorie-1:
                    Name: "Zinsen"
                    Abrechnungsrelevant: false
                    Steuer: false
        YAML);

        $this->seed(TestDataSeeder::class);

        $this->assertFalse($zinsen->fresh()->is_tax_relevant);
    }

    /**
     * When "Steuer" is omitted entirely for an already-existing category, a value set
     * manually via die Oberfläche must not be silently reset back to the default.
     */
    public function test_omitting_steuer_leaves_an_already_existing_categorys_value_untouched(): void
    {
        $category = CostCategory::create(['name' => 'Manuell gesetzt', 'is_relevant_for_billing' => true, 'is_tax_relevant' => false]);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Steuer Omit User"
            Passwort: "geheim123"

            Kategorien:
                Kategorie-1:
                    Name: "Manuell gesetzt"
                    Abrechnungsrelevant: true
        YAML);

        $this->seed(TestDataSeeder::class);

        $this->assertFalse($category->fresh()->is_tax_relevant);
    }

    public function test_konto_entries_under_a_verwaltungsgruppe_are_seeded_as_accounts(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Konto Seed User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Konto Gruppe"
                Kontosystem: true

                Konto-1:
                    Name: "Girokonto"
                    Beschreibung: "Laufendes Konto bei der Sparkasse"
                    Initial: 1000,00€
                    Default: true

                Konto-2:
                    Name: "Rücklagenkonto"
        YAML);

        $this->seed(TestDataSeeder::class);

        $managementGroup = ManagementGroup::where('description', 'Konto Gruppe')->first();
        $this->assertNotNull($managementGroup);

        $accounts = $managementGroup->accounts()->orderBy('name')->get();
        $this->assertCount(2, $accounts);
        $this->assertSame('Girokonto', $accounts[0]->name);
        $this->assertSame('Laufendes Konto bei der Sparkasse', $accounts[0]->description);
        $this->assertSame(1000.0, (float) $accounts[0]->initial_balance);
        $this->assertTrue($accounts[0]->is_default);
        $this->assertSame('Rücklagenkonto', $accounts[1]->name);
        $this->assertNull($accounts[1]->description);
        $this->assertSame(0.0, (float) $accounts[1]->initial_balance);
        $this->assertFalse($accounts[1]->is_default);
    }

    public function test_a_sharegroup_gives_another_account_access_to_this_accounts_verwaltungsgruppe(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Sharegroup Owner"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Owner VG1"

        Account-2:
            Name: "Sharegroup Member"
            Passwort: "geheim123"

            Group:
                Name: "Sharegroup 1-2"
                Users:
                    - Account-1
                Groups:
                    - Verwaltungsgruppe-1

            Verwaltungsgruppe-1:
                Beschreibung: "Shared VG1"
                Kontosystem: true
        YAML);

        $this->seed(TestDataSeeder::class);

        $owner = User::where('name', 'Sharegroup Owner')->first();
        $member = User::where('name', 'Sharegroup Member')->first();
        $this->assertNotNull($owner);
        $this->assertNotNull($member);

        $sharegroup = Group::where('name', 'Sharegroup 1-2')->first();
        $this->assertNotNull($sharegroup);
        $this->assertSame($member->id, $sharegroup->owner_id);
        $this->assertSame('admin', $sharegroup->users->firstWhere('id', $member->id)->pivot->role);
        $this->assertSame('user', $sharegroup->users->firstWhere('id', $owner->id)->pivot->role);

        $sharedGroup = ManagementGroup::where('description', 'Shared VG1')->first();
        $this->assertNotNull($sharedGroup);
        $this->assertSame($sharegroup->id, $sharedGroup->user_group_id);
        $this->assertTrue($sharedGroup->kontosystemEnabled());

        // The owner's own "Owner VG1" is untouched by the Sharegroup (not listed in its Groups).
        $ownVg = ManagementGroup::where('description', 'Owner VG1')->first();
        $this->assertNotSame($sharegroup->id, $ownVg->user_group_id);

        // Both the creator (Member, auto-admin) and the referenced Account-1 (Owner, as a
        // listed User) can now see the shared Verwaltungsgruppe.
        $this->assertTrue(ManagementGroup::visibleTo($member)->get()->contains($sharedGroup));
        $this->assertTrue(ManagementGroup::visibleTo($owner)->get()->contains($sharedGroup));
    }

    /**
     * Regression test: seedManagementGroup() used to match an existing Verwaltungsgruppe by
     * "description" + the Account's own user_group_id. Once seedSharegroups() moves a
     * Verwaltungsgruppe's user_group_id onto the Sharegroup, a second seeder run could no
     * longer find it under the Account's own group and created a duplicate, which
     * seedSharegroups() then moved onto the Sharegroup as well, leaving two Verwaltungsgruppen
     * with the same description (and duplicated Konto-* rows) visible to the Sharegroup.
     */
    public function test_seeding_a_sharegroups_verwaltungsgruppe_twice_does_not_duplicate_it(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Sharegroup Member"
            Passwort: "geheim123"

            Group:
                Name: "Sharegroup 1-2"
                Groups:
                    - Verwaltungsgruppe-1

            Verwaltungsgruppe-1:
                Beschreibung: "Shared VG1"

                Konto-1:
                    Name: "Defaultkonto"
                    Default: true
        YAML);

        $this->seed(TestDataSeeder::class);
        $this->seed(TestDataSeeder::class);

        $this->assertSame(1, ManagementGroup::where('description', 'Shared VG1')->count());

        $sharedGroup = ManagementGroup::where('description', 'Shared VG1')->first();
        $sharegroup = Group::where('name', 'Sharegroup 1-2')->first();
        $this->assertSame($sharegroup->id, $sharedGroup->user_group_id);
        $this->assertSame(1, $sharedGroup->accounts()->count());
    }

    public function test_verwaltungsgruppe_billing_intervals_are_seeded_from_intervall_entries(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Intervall Owner"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Intervall VG"

                Intervall-0:
                    Start: 1.1.2026
                    Ende: 31.12.2026
                Intervall-1:
                    Start: 1.1.2025
                    Ende: 31.12.2025
                    Beschreibung: "Abrechnungsjahr 2025"
        YAML);

        $this->seed(TestDataSeeder::class);

        $managementGroup = ManagementGroup::where('description', 'Intervall VG')->first();
        $intervals = $managementGroup->billingIntervals()->orderBy('start_date')->get();

        // The 2026 interval matches the one the observer auto-creates, so no duplicate.
        $this->assertSame(2, $intervals->count());
        $this->assertSame('2025-01-01', $intervals[0]->start_date->toDateString());
        $this->assertSame('2025-12-31', $intervals[0]->end_date->toDateString());
        $this->assertSame('Abrechnungsjahr 2025', $intervals[0]->description);
        $this->assertSame('2026-01-01', $intervals[1]->start_date->toDateString());
    }

    /**
     * Regression guards for Nutzerfeedback "Baue das Seeden so um, dass bei Abbrüchen
     * eine Meldung kommt. Niemals stillschweigend Daten überspringen." – every one of
     * these used to silently drop the incomplete/unresolvable entry (and everything
     * nested under it) instead of aborting the seed run.
     */
    public function test_an_account_without_a_name_or_passwort_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Kein Passwort User"
        YAML);

        $this->seed(TestDataSeeder::class);
    }

    public function test_a_verwaltungseinheit_without_a_strasse_or_stadt_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Unvollstaendige VE User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Gruppe"

                Verwaltungseinheit-1:
                    PLZ: 11111
        YAML);

        $this->seed(TestDataSeeder::class);
    }

    public function test_a_mieteinheit_with_an_invalid_typ_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Ungueltiger Typ User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Gruppe"

                Verwaltungseinheit-1:
                    Straße: "Weg 1"
                    PLZ: 11111
                    Stadt: "Stadt"

                    Mieteinheit-1:
                        Typ: "Büro"
        YAML);

        $this->seed(TestDataSeeder::class);
    }

    public function test_a_konto_without_a_name_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Kontoname User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Gruppe"

                Konto-0:
                    Beschreibung: "Ohne Name"
        YAML);

        $this->seed(TestDataSeeder::class);
    }

    public function test_a_billing_interval_without_start_or_ende_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Intervall Datum User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Gruppe"

                Intervall-0:
                    Beschreibung: "Ohne Datum"
        YAML);

        $this->seed(TestDataSeeder::class);
    }

    public function test_a_sharegroup_without_a_name_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Sharegroup Name User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Gruppe"

            Group-0:
                Groups:
                    - Verwaltungsgruppe-1
        YAML);

        $this->seed(TestDataSeeder::class);
    }

    public function test_a_sharegroup_referencing_an_unknown_verwaltungsgruppe_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Sharegroup VG User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Gruppe"

            Group-0:
                Name: "Sharegroup"
                Groups:
                    - Verwaltungsgruppe-99
        YAML);

        $this->seed(TestDataSeeder::class);
    }

    public function test_a_sharegroup_referencing_an_unknown_account_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Sharegroup Account User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Gruppe"

            Group-0:
                Name: "Sharegroup"
                Admins:
                    - Account-99
        YAML);

        $this->seed(TestDataSeeder::class);
    }

    /**
     * Konzept.md "### Account": "Die Daten eines Accounts sollen auch über eine
     * Datei-Referenz eingelesen werden können ... um es übersichtlicher zu machen
     * unterschiedliche Accounts zu seeden" – analog zu Template/Buchungen.
     */
    public function test_an_account_from_file_reference_is_loaded_and_used(): void
    {
        $accountFilePath = sys_get_temp_dir().'/testdata-seeder-account-'.uniqid().'.yaml';
        file_put_contents($accountFilePath, <<<'YAML'
        Name: "Datei Account User"
        Passwort: "geheim123"

        Verwaltungsgruppe-1:
            Beschreibung: "Datei-Account Gruppe"
        YAML);

        file_put_contents($this->path, <<<YAML
        lang: "de"

        Account-1:
            From-File: "$accountFilePath"
        YAML);

        $this->seed(TestDataSeeder::class);
        unlink($accountFilePath);

        $user = User::where('name', 'Datei Account User')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('geheim123', $user->password));

        $managementGroup = ManagementGroup::where('description', 'Datei-Account Gruppe')->first();
        $this->assertNotNull($managementGroup);
    }

    /**
     * Ein per "From-File" geladener Account funktioniert auch als Ziel einer
     * Sharegroup-Referenz eines ANDEREN Accounts – stellt sicher, dass die Auflösung
     * für beide Durchläufe (seedAccount() und seedSharegroups()) gilt, nicht nur für
     * den ersten.
     */
    public function test_a_sharegroup_can_reference_an_account_loaded_via_from_file(): void
    {
        $accountFilePath = sys_get_temp_dir().'/testdata-seeder-account-'.uniqid().'.yaml';
        file_put_contents($accountFilePath, <<<'YAML'
        Name: "Datei Mitglied User"
        Passwort: "geheim123"
        YAML);

        file_put_contents($this->path, <<<YAML
        lang: "de"

        Account-1:
            Name: "Sharegroup Owner"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Sharegroup Gruppe"

            Group-1:
                Name: "Von Datei-Account referenzierte Sharegroup"
                Admins:
                    - Account-2
                Groups:
                    - Verwaltungsgruppe-1

        Account-2:
            From-File: "$accountFilePath"
        YAML);

        $this->seed(TestDataSeeder::class);
        unlink($accountFilePath);

        $member = User::where('name', 'Datei Mitglied User')->first();
        $this->assertNotNull($member);
        $sharegroup = Group::where('name', 'Von Datei-Account referenzierte Sharegroup')->first();
        $this->assertNotNull($sharegroup);
        $this->assertTrue($member->groups->contains($sharegroup->id));
    }

    public function test_an_account_from_file_reference_alongside_other_keys_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            From-File: "private-assets/does-not-matter.yaml"
            Name: "Sollte nicht erlaubt sein"
        YAML);

        $this->seed(TestDataSeeder::class);
    }

    public function test_a_missing_account_from_file_reference_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            From-File: "private-assets/does-not-exist-account.yaml"
        YAML);

        $this->seed(TestDataSeeder::class);
    }

    public function test_an_account_from_file_wrapped_in_a_top_level_account_key_is_unwrapped(): void
    {
        $accountFilePath = sys_get_temp_dir().'/testdata-seeder-account-'.uniqid().'.yaml';
        file_put_contents($accountFilePath, <<<'YAML'
        Account-1:
            Name: "Gewickelter Datei Account User"
            Passwort: "geheim123"
        YAML);

        file_put_contents($this->path, <<<YAML
        lang: "de"

        Account-1:
            From-File: "$accountFilePath"
        YAML);

        $this->seed(TestDataSeeder::class);
        unlink($accountFilePath);

        $this->assertNotNull(User::where('name', 'Gewickelter Datei Account User')->first());
    }
}
