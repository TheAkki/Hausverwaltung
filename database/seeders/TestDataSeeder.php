<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\BillingInterval;
use App\Models\CostCategory;
use App\Models\Group;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\ManagementUnit;
use App\Models\RentalUnit;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Concerns\ParsesYamlValues;
use Database\Seeders\Concerns\SeedsBillingTemplates;
use Database\Seeders\Concerns\SeedsBuchungen;
use Database\Seeders\Concerns\SeedsContacts;
use Database\Seeders\Concerns\SeedsInvoicesAndCosts;
use Database\Seeders\Concerns\SeedsLeases;
use Illuminate\Database\Seeder;
use Symfony\Component\Yaml\Yaml;

/**
 * Seeds accounts, management groups/units, rental units, leases, tenants, invoice
 * issuers and invoices from an optional seeddata.yaml file in "private-assets/".
 *
 * The bulk of the field-level logic lives in the Concerns\* traits below, grouped by
 * domain: ParsesYamlValues (generic "Prefix-*" reading, date/decimal/reference
 * parsing), SeedsContacts (Mieter/Rechnungssteller/Vermieter), SeedsBillingTemplates,
 * SeedsLeases (Mietvertrag/Mietintervall), SeedsInvoicesAndCosts (Rechnungen/Kosten,
 * the largest and most intricate part) and SeedsBuchungen (the "Buchungen" block: a
 * parallel entry point reusing Rechnungen's own seeding plus Zahlungen/Forderung-*).
 * This class itself only orchestrates the top-level structure (Account → Sharegroup/
 * Verwaltungsgruppe → Verwaltungseinheit → Mieteinheit).
 */
class TestDataSeeder extends Seeder
{
    use ParsesYamlValues;
    use SeedsBillingTemplates;
    use SeedsBuchungen;
    use SeedsContacts;
    use SeedsInvoicesAndCosts;
    use SeedsLeases;

    private const RENTAL_UNIT_TYPES = ['Wohnung', 'Garage', 'Stellplatz'];

    /**
     * Run the database seeds.
     *
     * Reads seeddata.yaml from "private-assets/" (gitignored, like the template/image
     * files it can reference via "From-File"/"Image") and populates the database with
     * the accounts, Verwaltungsgruppen, Verwaltungseinheiten, Mieteinheiten,
     * Mietverträge and Mieter described in it. Missing or empty files are ignored
     * without error.
     */
    public function run(): void
    {
        $path = (string) config('seeding.data_file');

        if (! is_file($path)) {
            return;
        }

        $contents = file_get_contents($path);

        if ($contents === false || trim($contents) === '') {
            return;
        }

        $data = Yaml::parse($contents);

        if (empty($data) || ($data['lang'] ?? 'de') !== 'de') {
            return;
        }

        $this->assertNoLiteralNSuffixKeys($data);

        $accountsByKey = [];
        foreach ($this->entriesMatchingWithKeys($data, 'Account') as $key => $accountData) {
            $accountsByKey[$key] = $this->resolveAccountFromFile($accountData, $key);
        }

        $usersByAccountKey = [];
        $managementGroupsByAccountKey = [];

        foreach ($accountsByKey as $key => $accountData) {
            [$user, $managementGroupsByKey] = $this->seedAccount($accountData);
            $usersByAccountKey[$key] = $user;
            $managementGroupsByAccountKey[$key] = $managementGroupsByKey;
        }

        // Sharegroups ("Group-*"/"Group") reference other Accounts and this account's own
        // Verwaltungsgruppen by key, so they can only be resolved once every Account has
        // been seeded once (first pass above).
        foreach ($accountsByKey as $key => $accountData) {
            $this->seedSharegroups(
                $accountData,
                $usersByAccountKey[$key],
                $managementGroupsByAccountKey[$key],
                $usersByAccountKey
            );
        }
    }

    /**
     * Resolves an Account's optional "From-File" reference (Konzept.md "### Account":
     * "Die Daten eines Accounts sollen auch über eine Datei-Referenz eingelesen werden
     * können ... um es übersichtlicher zu machen unterschiedliche Accounts zu seeden"
     * – analogous to a Template's/einer Buchungen-Datei's own "From-File"). Must be
     * the ONLY key under "Account-*" when given (Konzept.md: "Es muss überprüft
     * werden, dass, wenn 'From-File' vorhanden ist, es der einzige Knoten unter
     * 'Account' ist.") – unlike a Template's From-File, which does tolerate a
     * Name/Standard override alongside it, an Account's From-File allows no override
     * at all, so it's never ambiguous which of two conflicting values applies.
     *
     * @param  array<string, mixed>  $accountData
     * @return array<string, mixed>
     */
    private function resolveAccountFromFile(array $accountData, string $key): array
    {
        $fromFile = $this->fromFileValue($accountData);

        if ($fromFile === '') {
            return $accountData;
        }

        if (count($accountData) > 1) {
            throw new \RuntimeException("Account-{$key}: Wenn \"From-File\" gesetzt ist, darf es der einzige Knoten unter \"Account-{$key}\" sein.");
        }

        $resolvedPath = $this->resolveLocalAssetPath($fromFile);
        $contents = file_get_contents($resolvedPath);
        $fileData = $contents !== false ? Yaml::parse($contents) : null;

        if (! is_array($fileData)) {
            throw new \RuntimeException("Account-{$key}: Datei \"{$fromFile}\" ist leer oder kein gültiges YAML.");
        }

        $this->assertNoLiteralNSuffixKeys($fileData);

        return $this->unwrapAccountFile($fileData);
    }

    /**
     * An Account-Datei may hold the Account's fields directly (Name/Passwort/…) or
     * wrap them in a single top-level "Account"/"Account-*" key – mirrors the same
     * tolerance already built for a Template-Datei (unwrapTemplateFile()) and a
     * Buchungen-Datei (unwrapBuchungenFile()).
     *
     * @param  array<string, mixed>  $fileData
     * @return array<string, mixed>
     */
    private function unwrapAccountFile(array $fileData): array
    {
        if (isset($fileData['Name']) || isset($fileData['Passwort'])) {
            return $fileData;
        }

        $wrapped = $this->entriesMatching($fileData, 'Account');

        return $wrapped[0] ?? $fileData;
    }

    /**
     * @param  array<string, mixed>  $account
     * @return array{0: User, 1: array<string, ManagementGroup>} the seeded user and its
     *                                                           Verwaltungsgruppen keyed
     *                                                           by their YAML suffix
     */
    private function seedAccount(array $account): array
    {
        $name = trim((string) ($account['Name'] ?? ''));
        $password = (string) ($account['Passwort'] ?? '');

        if ($name === '' || $password === '') {
            throw new \RuntimeException('Ein Account-Eintrag hat keinen Namen oder kein Passwort (beide sind Pflichtangaben).');
        }

        $user = User::where('name', $name)->first();

        if ($user === null) {
            $user = User::create([
                'name' => $name,
                'email' => $this->uniqueEmailFor($name),
                'password' => $password,
            ]);
        }

        // The UserObserver normally creates this, but seeders may run with model events disabled.
        $userGroup = $user->groups()->first();

        if ($userGroup === null) {
            $userGroup = Group::create(['name' => "Gruppe von {$user->name}", 'owner_id' => $user->id]);
            $userGroup->users()->attach($user->id, ['role' => 'admin']);
        }

        // The "Kontosystem" flag lives on the Verwaltungsgruppe. A Kontosystem set directly
        // on the Account applies as the default for every Verwaltungsgruppe under it that
        // doesn't specify its own value.
        $accountKontosystemDefault = (bool) ($account['Kontosystem'] ?? false);

        // Account-level Vermieter (landlord): used on the Betriebskostenabrechnung unless a
        // Verwaltungsgruppe overrides it with its own. Declared as "Vermieter-*" (like
        // "Konto-*"), but a bare "Vermieter" key is accepted too; only the first entry is used.
        $accountLandlordData = $this->entriesMatching($account, 'Vermieter')[0] ?? null;
        if ($accountLandlordData !== null) {
            $this->seedLandlord($accountLandlordData, userGroupId: $userGroup->id, managementGroupId: null);
        }

        $tenantsByKey = [];
        foreach ($this->entriesMatchingWithKeysUnwrapped($account, 'Mieter') as $key => $tenantData) {
            $tenantsByKey[$key] = $this->seedTenant($tenantData, $userGroup->id);
        }

        $invoiceIssuersByKey = [];
        $invoiceIssuerAccountsByKey = [];
        foreach ($this->entriesMatchingWithKeysUnwrapped($account, 'Rechnungssteller') as $key => $issuerData) {
            $issuer = $this->seedInvoiceIssuer($issuerData, $userGroup->id);
            $invoiceIssuersByKey[$key] = $issuer;
            $invoiceIssuerAccountsByKey[$key] = $this->seedInvoiceIssuerAccounts($issuerData, $issuer);
        }

        $managementGroupsByKey = [];
        $managementUnitsByKey = [];
        $rentalUnitsByKey = [];
        $billingIntervalsByKey = [];
        $leasesByKey = [];
        $accountsByKey = [];

        foreach ($this->entriesMatchingWithKeys($account, 'Verwaltungsgruppe') as $vgKey => $managementGroupData) {
            $unitsByKey = [];
            $rentalsByKey = [];
            $intervalsByKey = [];
            $leasesForThisGroup = [];
            $kontenForThisGroup = [];

            $managementGroupsByKey[$vgKey] = $this->seedManagementGroup(
                $managementGroupData, $vgKey, $user, $userGroup->id, $accountKontosystemDefault, $tenantsByKey, $unitsByKey, $rentalsByKey, $intervalsByKey, $leasesForThisGroup, $kontenForThisGroup
            );
            $managementUnitsByKey[$vgKey] = $unitsByKey;
            $rentalUnitsByKey[$vgKey] = $rentalsByKey;
            $billingIntervalsByKey[$vgKey] = $intervalsByKey;
            $leasesByKey[$vgKey] = $leasesForThisGroup;
            $accountsByKey[$vgKey] = $kontenForThisGroup;
        }

        if (isset($account['Kategorien']) && is_array($account['Kategorien'])) {
            $this->seedCategories($account['Kategorien'], $managementGroupsByKey, $userGroup->id);
        }

        if (isset($account['Rechnungen']) && is_array($account['Rechnungen'])) {
            $this->seedRechnungen(
                $account['Rechnungen'],
                $managementGroupsByKey,
                $managementUnitsByKey,
                $rentalUnitsByKey,
                $invoiceIssuersByKey,
                $invoiceIssuerAccountsByKey,
                $billingIntervalsByKey,
                $leasesByKey
            );
        }

        if (isset($account['Buchungen']) && is_array($account['Buchungen'])) {
            $this->seedBuchungen(
                $account['Buchungen'],
                $managementGroupsByKey,
                $managementUnitsByKey,
                $rentalUnitsByKey,
                $invoiceIssuersByKey,
                $invoiceIssuerAccountsByKey,
                $billingIntervalsByKey,
                $leasesByKey,
                is_array($account['Rechnungen'] ?? null) ? $account['Rechnungen'] : [],
                $accountsByKey
            );
        }

        return [$user, $managementGroupsByKey];
    }

    /**
     * Seeds "[Kategorien].[Kategorie-*]" custom cost categories declared under an Account, in
     * addition to the standard ones from CostCategorySeeder. Categories are global/shared
     * (mirroring CostCategorySeeder's own firstOrCreate-by-name), so re-declaring an existing
     * name (e.g. "Zinsen") is a no-op beyond keeping it present. If an entry specifies an
     * Abrechnungsschlüssel, it's applied as the allocation_key for every one of this Account's
     * own Verwaltungsgruppen – on every seed run, overwriting whatever value was there before
     * (Konzept.md "### Kategorie": "Wenn der Name schon existiert wird der
     * Abrechnungsschlüssel überschrieben"), including one set manually via die Oberfläche.
     * "Steuer" behaves the same way when given: it's applied on every run, even to an
     * already-existing category (e.g. a standard one from CostCategorySeeder like
     * "Zinsen", for which firstOrCreate()'s own defaults never take effect since the
     * row always already exists) – but only if the entry actually specifies "Steuer" at
     * all, so a value the user set manually via die Oberfläche for a category the YAML
     * doesn't mention isn't silently reset back to the default.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, ManagementGroup>  $managementGroupsByKey
     */
    private function seedCategories(array $data, array $managementGroupsByKey, int $userGroupId): void
    {
        foreach ($this->entriesMatchingWithKeys($data, 'Kategorie') as $categoryData) {
            $name = trim((string) ($categoryData['Name'] ?? ''));

            if ($name === '') {
                continue;
            }

            // Eine bereits systemweite (CostCategorySeeder) oder diesem Account bereits
            // gehörende Kategorie gleichen Namens wiederverwenden statt sie zu duplizieren;
            // nur wenn keine davon existiert, wird eine neue Kategorie angelegt – auf
            // diesen Account beschränkt, damit sie nicht in fremden Verwaltungsgruppen
            // auftaucht (siehe CostCategory::scopeVisibleTo()).
            $category = CostCategory::where('name', $name)
                ->where(fn ($q) => $q->whereNull('user_group_id')->orWhere('user_group_id', $userGroupId))
                ->first();

            $category ??= CostCategory::create([
                'user_group_id' => $userGroupId,
                'name' => $name,
                'is_relevant_for_billing' => (bool) ($categoryData['Abrechnungsrelevant'] ?? true),
                'is_tax_relevant' => (bool) ($categoryData['Steuer'] ?? true),
            ]);

            if (array_key_exists('Steuer', $categoryData)) {
                $category->update(['is_tax_relevant' => (bool) $categoryData['Steuer']]);
            }

            $allocationKey = $this->mapAllocationKey($categoryData['Abrechnungsschlüssel'] ?? null);

            if ($allocationKey === null) {
                continue;
            }

            foreach ($managementGroupsByKey as $managementGroup) {
                $managementGroup->costCategories()->syncWithoutDetaching([
                    $category->id => ['allocation_key' => $allocationKey],
                ]);
            }
        }
    }

    /**
     * Seeds "Group"/"Group-*" (Sharegroup) blocks: an additional Benutzergruppe, owned by the
     * Account it's declared under, that can list other Accounts as Admins/Users and take over
     * ownership of one or more of this Account's own Verwaltungsgruppen (moving their
     * user_group_id so every member of the Sharegroup can see/manage them).
     *
     * @param  array<string, mixed>  $accountData
     * @param  array<string, ManagementGroup>  $managementGroupsByKey  this account's own Verwaltungsgruppen, keyed by YAML suffix
     * @param  array<string, User>  $usersByAccountKey  every seeded user, keyed by their Account's YAML suffix
     */
    private function seedSharegroups(
        array $accountData,
        User $creator,
        array $managementGroupsByKey,
        array $usersByAccountKey
    ): void {
        foreach ($this->entriesMatchingWithKeys($accountData, 'Group') as $sharegroupData) {
            $name = trim((string) ($sharegroupData['Name'] ?? ''));

            if ($name === '') {
                throw new \RuntimeException('Ein Group-Eintrag (Sharegroup) hat keinen Namen.');
            }

            $sharegroup = Group::firstOrCreate(['name' => $name], ['owner_id' => $creator->id]);
            $sharegroup->users()->syncWithoutDetaching([$creator->id => ['role' => 'admin']]);

            foreach ((array) ($sharegroupData['Admins'] ?? []) as $accountRef) {
                $this->attachSharegroupMember($sharegroup, $accountRef, $usersByAccountKey, 'admin');
            }

            foreach ((array) ($sharegroupData['Users'] ?? []) as $accountRef) {
                $this->attachSharegroupMember($sharegroup, $accountRef, $usersByAccountKey, 'user');
            }

            foreach ((array) ($sharegroupData['Groups'] ?? []) as $vgRef) {
                $vgKey = $this->normalizeReferenceKey((string) $vgRef, 'Verwaltungsgruppe-');
                $managementGroup = $managementGroupsByKey[$vgKey] ?? null;

                if ($managementGroup === null) {
                    throw new \RuntimeException("Sharegroup \"{$name}\": \"{$vgRef}\" referenziert keine bestehende Verwaltungsgruppe dieses Accounts.");
                }

                if ($managementGroup->user_group_id !== $sharegroup->id) {
                    $managementGroup->update(['user_group_id' => $sharegroup->id]);
                }
            }

            $this->seedGroupTemplates((array) ($sharegroupData['Templates'] ?? []), $sharegroup);
        }
    }

    /**
     * @param  array<string, User>  $usersByAccountKey
     */
    private function attachSharegroupMember(Group $group, mixed $accountRef, array $usersByAccountKey, string $role): void
    {
        $key = $this->normalizeReferenceKey((string) $accountRef, 'Account-');
        $user = $usersByAccountKey[$key] ?? null;

        if ($user === null) {
            throw new \RuntimeException("Sharegroup \"{$group->name}\": \"{$accountRef}\" referenziert keinen bestehenden Account.");
        }

        $group->users()->syncWithoutDetaching([$user->id => ['role' => $role]]);
    }

    /**
     * @param  array<string, ManagementUnit>  $unitsByKey  populated with the created units, keyed by their YAML suffix
     * @param  array<string, array<string, RentalUnit>>  $rentalsByKey  populated with the created rental units, keyed by [unit suffix][rental unit suffix]
     * @param  array<string, array<string, array<string, Lease>>>  $leasesByKey  populated with the created leases, keyed by [unit suffix][rental unit suffix][Mietvertrag suffix]
     * @param  array<string, Account>  $accountsByKey  populated with the created Konten, keyed by
     *                                                 their "Konto-*" YAML suffix, so an "Umbuchung"'s
     *                                                 "Von"/"Nach: Konto-*" can resolve to the Account
     */
    private function seedManagementGroup(
        array $data,
        string $vgKey,
        User $user,
        int $userGroupId,
        bool $accountKontosystemDefault,
        array $tenantsByKey,
        array &$unitsByKey,
        array &$rentalsByKey,
        array &$billingIntervalsByKey = [],
        array &$leasesByKey = [],
        array &$accountsByKey = []
    ): ManagementGroup {
        $description = $data['Beschreibung'] ?? null;

        // seedSharegroups() may have moved this Verwaltungsgruppe's user_group_id to a
        // Sharegroup on an earlier seeder run, so it no longer matches the Account's own
        // personal Group. Re-seeding must look for it among every Group this Account's user
        // currently belongs to (own group + any Sharegroups), not just their own group,
        // or it would create a duplicate here that seedSharegroups() then moves as well.
        $accessibleGroupIds = $user->groups()->pluck('groups.id');

        $managementGroup = ManagementGroup::where('description', $description)
            ->whereIn('user_group_id', $accessibleGroupIds)
            ->first();

        if ($managementGroup === null) {
            $managementGroup = ManagementGroup::create([
                'description' => $description,
                'user_group_id' => $userGroupId,
            ]);
        }

        $kontosystemEnabled = array_key_exists('Kontosystem', $data)
            ? (bool) $data['Kontosystem']
            : $accountKontosystemDefault;

        if ($kontosystemEnabled && ! $managementGroup->kontosystem_enabled) {
            $managementGroup->update(['kontosystem_enabled' => true]);
        }

        // Populates the by-reference out-param so the caller can resolve a Rechnung's
        // "Abrechnungsintervall: Intervall-*" reference to the created BillingInterval.
        $billingIntervalsByKey = $this->seedBillingIntervals($data, $managementGroup);

        // Optional Vermieter-override for this Verwaltungsgruppe (otherwise the account-
        // level Vermieter applies, see ManagementGroup::effectiveLandlord()). Same
        // "Vermieter-*"/bare-"Vermieter" acceptance as the account level above.
        $vgLandlordData = $this->entriesMatching($data, 'Vermieter')[0] ?? null;
        if ($vgLandlordData !== null) {
            $this->seedLandlord($vgLandlordData, userGroupId: null, managementGroupId: $managementGroup->id);
        }

        foreach ($this->entriesMatchingWithKeys($data, 'Konto') as $kontoKey => $kontoData) {
            $name = trim((string) ($kontoData['Name'] ?? ''));

            if ($name === '') {
                throw new \RuntimeException("Konto-{$kontoKey} der Verwaltungsgruppe (Id {$managementGroup->id}) hat keinen Namen.");
            }

            $isDefault = (bool) ($kontoData['Default'] ?? false);

            if ($isDefault) {
                $managementGroup->accounts()->update(['is_default' => false]);
            }

            // Populates the by-reference out-param so the caller can resolve an
            // "Umbuchung"'s "Von"/"Nach: Konto-*" references to the created Account.
            $accountsByKey[$kontoKey] = $managementGroup->accounts()->firstOrCreate([
                'name' => $name,
            ], [
                'description' => $kontoData['Beschreibung'] ?? null,
                'initial_balance' => $this->parseDecimal($kontoData['Initial'] ?? null) ?? 0,
                'is_default' => $isDefault,
            ]);
        }

        foreach ($this->entriesMatchingWithKeys($data, 'Verwaltungseinheit') as $veKey => $unitData) {
            $rentalUnitsForThisUnit = [];
            $leasesForThisUnit = [];
            $unitsByKey[$veKey] = $this->seedManagementUnit(
                $unitData, $managementGroup, $tenantsByKey, $rentalUnitsForThisUnit, $leasesForThisUnit, $vgKey, $veKey
            );
            $rentalsByKey[$veKey] = $rentalUnitsForThisUnit;
            $leasesByKey[$veKey] = $leasesForThisUnit;
        }

        return $managementGroup;
    }

    /**
     * Seeds the Verwaltungsgruppen-level "Intervall-*" entries (Abrechnungsintervalle /
     * BillingIntervals) with Start/Ende/Beschreibung. Idempotent (firstOrCreate by
     * start+end date), so it does not duplicate the one interval the
     * ManagementGroupObserver auto-creates for the current year when the group is first
     * created – if a defined interval matches it, it simply reuses/updates that row.
     * Note: a Mietvertrag also has "Intervall-*" entries, but those are LeaseIntervals
     * handled in seedLeaseIntervals(); these here are the group's BillingIntervals,
     * distinguished by having "Start"/"Ende" rather than "Personen".
     *
     * @param  array<string, mixed>  $data
     * @return array<string, BillingInterval> the created intervals keyed by their YAML
     *                                        suffix (e.g. "0" for "Intervall-0"), so a
     *                                        Rechnung can reference one by "Intervall-*"
     */
    private function seedBillingIntervals(array $data, ManagementGroup $managementGroup): array
    {
        $intervalsByKey = [];

        foreach ($this->entriesMatchingWithKeys($data, 'Intervall') as $key => $intervalData) {
            $start = $this->parseDate($intervalData['Start'] ?? null);
            $end = $this->parseDate($intervalData['Ende'] ?? null);

            if ($start === null || $end === null) {
                throw new \RuntimeException("Intervall-{$key} der Verwaltungsgruppe (Id {$managementGroup->id}) hat kein (gültiges) Start- oder Ende-Datum.");
            }

            // Match by DATE, not exact value: the ManagementGroupObserver's auto-created
            // interval is stored with a time component, so an exact firstOrCreate on the
            // date string would miss it and create a duplicate for the same year.
            $intervalsByKey[$key] = $managementGroup->billingIntervals()
                ->whereDate('start_date', $start)
                ->whereDate('end_date', $end)
                ->first()
                ?? $managementGroup->billingIntervals()->create([
                    'start_date' => $start,
                    'end_date' => $end,
                    'description' => $intervalData['Beschreibung'] ?? null,
                ]);
        }

        return $intervalsByKey;
    }

    /**
     * Seeds every Mieteinheit (RentalUnit) of one Verwaltungseinheit and their
     * Mietverträge in two passes, so a Mietvertrag that establishes its tenant
     * identity via a "Mietvertrag: <ref>" coupling (Konzept.md "### Mietvertrag")
     * instead of its own MieterId can resolve against a Mietvertrag of ANY Mieteinheit
     * of this same Verwaltungseinheit – including one that appears later in the YAML –
     * not just ones already processed. Pass 1 creates every RentalUnit and every
     * directly MieterId-based Mietvertrag; pass 2 resolves the deferred, reference-based
     * ones once every direct Mietvertrag of this Verwaltungseinheit is guaranteed to exist.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, Tenant>  $tenantsByKey
     * @param  array<string, RentalUnit>  $rentalUnitsByKey  populated with the created rental units, keyed by their YAML suffix
     * @param  array<string, array<string, Lease>>  $leasesByKey  populated with the created leases, keyed by [Mieteinheit suffix][Mietvertrag suffix]
     */
    private function seedManagementUnit(
        array $data,
        ManagementGroup $managementGroup,
        array $tenantsByKey,
        array &$rentalUnitsByKey,
        array &$leasesByKey,
        string $vgKey,
        string $veKey
    ): ManagementUnit {
        $street = trim((string) ($data['Straße'] ?? ''));
        $city = trim((string) ($data['Stadt'] ?? ''));

        if ($street === '' || $city === '') {
            throw new \RuntimeException("Verwaltungseinheit \"{$vgKey}.{$veKey}\" hat keine Straße oder keine Stadt (beide sind Pflichtangaben).");
        }

        $managementUnit = $managementGroup->managementUnits()->firstOrCreate([
            'street' => $street,
            'postal_code' => (string) ($data['PLZ'] ?? ''),
            'city' => $city,
        ], [
            'description' => $data['Beschreibung'] ?? null,
        ]);

        // Pass 1: RentalUnits plus every directly MieterId-based Mietvertrag. Entries
        // coupled to another Mieteinheit via "Mietvertrag: <ref>" are collected here
        // instead of seeded, since their target may not exist yet.
        $deferred = [];

        foreach ($this->entriesMatchingWithKeys($data, 'Mieteinheit') as $meKey => $rentalUnitData) {
            $rentalUnitsByKey[$meKey] = $rentalUnit = $this->createRentalUnit($rentalUnitData, $managementUnit, "{$vgKey}.{$veKey}.{$meKey}");
            $leasesByKey[$meKey] = [];

            foreach ($this->entriesMatchingWithKeys($rentalUnitData, 'Mietvertrag') as $mietvertragKey => $leaseData) {
                $reference = trim((string) ($leaseData['Mietvertrag'] ?? ''));
                $hasOwnMieterId = $this->tenantKeysFromLeaseData($leaseData) !== [];

                if ($reference === '' && ! $hasOwnMieterId) {
                    throw new \RuntimeException(
                        "Mietvertrag \"{$vgKey}.{$veKey}.{$meKey}.Mietvertrag-{$mietvertragKey}\" hat weder eine ".
                        'MieterId (bzw. MieterId-*) noch eine Mietvertrag-Referenz angegeben.'
                    );
                }

                if ($reference !== '') {
                    if ($rentalUnit->is_relevant_for_billing) {
                        throw new \RuntimeException(
                            "Mietvertrag \"{$vgKey}.{$veKey}.{$meKey}.Mietvertrag-{$mietvertragKey}\": eine ".
                            'abrechnungsrelevante Mieteinheit darf nur per MieterId referenziert werden, nicht '.
                            'per Mietvertrag-Referenz.'
                        );
                    }

                    $deferred[] = [
                        'meKey' => $meKey,
                        'mietvertragKey' => $mietvertragKey,
                        'leaseData' => $leaseData,
                        'rentalUnit' => $rentalUnit,
                        'reference' => $reference,
                    ];

                    continue;
                }

                $leasesByKey[$meKey][$mietvertragKey] = $this->seedLease($leaseData, $rentalUnit, $tenantsByKey);
            }
        }

        // Pass 2: resolve every deferred "Mietvertrag: <ref>" coupling now that every
        // direct-MieterId Mietvertrag of this Verwaltungseinheit exists.
        foreach ($deferred as $entry) {
            $targetLease = $this->resolveLeaseReference(
                $entry['reference'], $vgKey, $veKey, $entry['meKey'], $rentalUnitsByKey, $leasesByKey
            );

            $this->assertCoupledLeaseDoesNotOutliveReference($entry['reference'], $entry['leaseData'], $targetLease);

            $leasesByKey[$entry['meKey']][$entry['mietvertragKey']] = $this->seedLease(
                $entry['leaseData'],
                $entry['rentalUnit'],
                $tenantsByKey,
                $targetLease->tenants()->pluck('tenants.id')->all(),
                $targetLease->id
            );
        }

        return $managementUnit;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createRentalUnit(array $data, ManagementUnit $managementUnit, string $context): RentalUnit
    {
        $type = (string) ($data['Typ'] ?? '');

        if (! in_array($type, self::RENTAL_UNIT_TYPES, true)) {
            throw new \RuntimeException(
                "Mieteinheit \"{$context}\" hat keinen (gültigen) Typ – erlaubt sind: ".implode(', ', self::RENTAL_UNIT_TYPES).'.'
            );
        }

        return $managementUnit->rentalUnits()->firstOrCreate([
            'type' => $type,
            'description' => $data['Beschreibung'] ?? null,
        ], [
            'size' => $this->parseDecimal($data['Größe'] ?? null),
            'floor' => $data['Etage'] ?? null,
            'is_relevant_for_billing' => (bool) ($data['Abrechnungsrelevant'] ?? true),
        ]);
    }
}
