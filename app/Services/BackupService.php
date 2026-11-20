<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransfer;
use App\Models\BillingInterval;
use App\Models\BillingTemplate;
use App\Models\Cost;
use App\Models\CostCategory;
use App\Models\Group;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\InvoiceIssuerAccount;
use App\Models\Landlord;
use App\Models\Lease;
use App\Models\LeaseDocument;
use App\Models\LeaseInterval;
use App\Models\MaintenanceContract;
use App\Models\ManagementGroup;
use App\Models\ManagementUnit;
use App\Models\RentalUnit;
use App\Models\RentPayment;
use App\Models\SettlementClaim;
use App\Models\Tenant;
use App\Models\Theme;
use App\Models\User;
use App\Services\GroupOwnership\GroupOwnership;
use App\Support\FileStorage;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Implements the Backup-Konzept: every user can export/import their own data
 * as a ZIP archive; the Administrator account can export/import ALL settings,
 * with group permissions (memberships and roles) being restored on import.
 *
 * Benutzer-Backup (Konzept.md "## API"): Der Export enthält alle SICHTBAREN Daten,
 * also jede Gruppe, in der der Benutzer Mitglied ist – gleich mit welcher Rolle –
 * samt Verwaltungsstruktur und Dateien. Der Import spielt alle SCHREIBBAREN Daten
 * wieder ein (Mitglieder dürfen in ihren Gruppen arbeiten). Mitgliedschaften und
 * Rollen stellt er nur für Gruppen wieder her, in denen der Importierende laut
 * Backup Admin war, und dann nur für bereits vorhandene Konten. Ein Benutzer-Import
 * kann damit nichts, was der Benutzer nicht auch in der Oberfläche könnte: Er legt
 * keine Konten an und macht niemand anderen zum Besitzer einer Gruppe. Neue Konten
 * entstehen nur beim Gesamt-Import durch den Administrator.
 *
 * The ZIP contains a `backup.json` with the structured data plus a `files/`
 * directory holding the attached documents (invoice PDFs, lease documents,
 * billing template header images). Passwords are deliberately NOT exported:
 * users that do not exist yet on import are recreated with an empty password
 * and a forced password change, exactly like the Admin-Account bootstrap flow.
 */
class BackupService
{
    public const FORMAT = 'hausverwaltung-backup';

    /**
     * Version 2 ergänzt Vermieter, Forderungen, Mietvertrags-Kopplung/Vorvertrag,
     * gruppeneigene Kategorien und Vorlagen sowie Kosten/Rechnungen an
     * Mietverträgen. Version-1-Backups bleiben importierbar (alle neuen Schlüssel
     * sind optional).
     */
    public const VERSION = 2;

    private const LANDLORD_FIELDS = [
        'salutation', 'first_name', 'name', 'company_name', 'street', 'postal_code', 'city',
        'email', 'phone', 'mobile_phone', 'iban', 'bic', 'notes',
    ];

    private const TEMPLATE_FIELDS = ['name', 'content', 'css_content', 'header', 'footer', 'is_default'];

    private const ROLE_ADMIN = 'admin';

    private const ROLE_USER = 'user';

    /** @var list<string> */
    private const ROLES = [self::ROLE_ADMIN, self::ROLE_USER];

    /** Mitglieder ohne Konto, die ein Benutzer-Import übersprungen hat. */
    private int $skippedMembers = 0;

    /** @var array<int|string, int> */
    private array $leaseMap = [];

    /** @var array<int, array{lease: int, ref: int|string}> */
    private array $pendingCouplings = [];

    /** @var array<int|string, int> */
    private array $tenantMap = [];

    /** @var array<int|string, int> */
    private array $issuerMap = [];

    /** @var array<int|string, int> */
    private array $issuerAccountMap = [];

    /** @var array<int|string, int> */
    private array $managementGroupMap = [];

    /** @var array<int|string, int> */
    private array $managementUnitMap = [];

    /** @var array<int|string, int> */
    private array $rentalUnitMap = [];

    /** @var array<int|string, int> */
    private array $billingIntervalMap = [];

    /** @var array<int|string, int> */
    private array $accountMap = [];

    /** @var array<int|string, int> */
    private array $invoiceMap = [];

    /** @var array<int, string> */
    private array $importedFiles = [];

    private ?ZipArchive $importZip = null;

    /**
     * Exports the given user's own data (type "user") as a ZIP file and returns
     * its absolute path (a temp file the caller should download and delete).
     * Enthalten ist jede Gruppe, in der der Benutzer Mitglied ist (alles, was er
     * sehen kann).
     */
    public function export(User $user): string
    {
        $groups = $user->groups()->orderBy('groups.id')->get();

        $data = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'type' => 'user',
            'exported_at' => now()->toIso8601String(),
            'exported_by' => $user->email,
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'theme_slug' => $user->theme?->slug,
            ],
            'groups' => $groups->map(fn (Group $group) => $this->exportGroup($group))->all(),
        ];

        return $this->writeZip($data);
    }

    /**
     * Exports ALL settings (type "full"): every user, every group, and the
     * global reference data (themes, billing templates, cost categories).
     */
    public function exportFull(User $administrator): string
    {
        $data = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'type' => 'full',
            'exported_at' => now()->toIso8601String(),
            'exported_by' => $administrator->email,
            'users' => User::orderBy('id')->get()->map(fn (User $user) => [
                'name' => $user->name,
                'email' => $user->email,
                'theme_slug' => $user->theme?->slug,
            ])->all(),
            'themes' => Theme::where('is_builtin', false)->orderBy('id')->get()
                ->map(fn (Theme $theme) => $theme->only(['name', 'slug', 'css']))->all(),
            // Gruppeneigene Vorlagen und Kategorien stecken seit Version 2 in ihrer
            // Gruppe; hier nur noch die systemweiten.
            'billing_templates' => BillingTemplate::whereNull('user_group_id')->orderBy('id')->get()->map(fn (BillingTemplate $template) => [
                ...$template->only(self::TEMPLATE_FIELDS),
                'header_image' => $this->fileEntry($template->header_image_path, null),
            ])->all(),
            'cost_categories' => CostCategory::whereNull('user_group_id')->orderBy('id')->get()
                ->map(fn (CostCategory $category) => $category->only(['name', 'is_relevant_for_billing']))->all(),
            'groups' => Group::orderBy('id')->get()->map(fn (Group $group) => $this->exportGroup($group))->all(),
        ];

        return $this->writeZip($data);
    }

    /**
     * Imports a backup ZIP previously produced by export()/exportFull() for the
     * given user. "full" backups may only be imported by the Administrator.
     * Returns a short human-readable summary of what was restored.
     */
    public function import(User $user, string $zipPath): string
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException(__('Die Datei ist kein lesbares ZIP-Archiv.'));
        }

        $json = $zip->getFromName('backup.json');

        if ($json === false || ! is_array($data = json_decode($json, true))) {
            $zip->close();
            throw new \RuntimeException(__('Im Archiv fehlt eine gültige backup.json.'));
        }

        if (($data['format'] ?? null) !== self::FORMAT) {
            $zip->close();
            throw new \RuntimeException(__('Die Datei ist kein Backup dieser Anwendung.'));
        }

        if (($data['type'] ?? 'user') === 'full' && ! $user->isAdministrator()) {
            $zip->close();
            throw new \RuntimeException(__('Nur der Administrator darf ein Gesamt-Backup importieren.'));
        }

        $this->resetMaps();
        $this->importZip = $zip;
        $this->importedFiles = [];

        try {
            // Ein Backup stellt den gesicherten Zustand wieder her, wie er war – auch
            // Altdaten, die der Gruppenregel (GroupOwnership) noch nicht entsprechen.
            $summary = GroupOwnership::suspended(fn () => DB::transaction(fn () => $this->importData($user, $data)));
        } catch (\Throwable $e) {
            // Die Transaktion ist zurückgerollt – bereits extrahierte Dateien
            // gehören zu keiner Datenbankzeile mehr und werden aufgeräumt.
            foreach ($this->importedFiles as $path) {
                FileStorage::disk()->delete($path);
            }

            throw $e;
        } finally {
            $this->importZip = null;
            $zip->close();
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function importData(User $user, array $data): string
    {
        $isFull = ($data['type'] ?? 'user') === 'full';

        if ($isFull) {
            foreach ($data['themes'] ?? [] as $theme) {
                Theme::firstOrCreate(['slug' => $theme['slug']], [
                    'name' => $theme['name'],
                    'css' => $theme['css'],
                    'is_builtin' => false,
                ]);
            }

            foreach ($data['billing_templates'] ?? [] as $template) {
                if (BillingTemplate::where('name', $template['name'])->exists()) {
                    continue;
                }

                BillingTemplate::create([
                    'name' => $template['name'],
                    'content' => $template['content'],
                    'css_content' => $template['css_content'] ?? null,
                    'header' => $template['header'] ?? null,
                    'footer' => $template['footer'] ?? null,
                    'is_default' => (bool) ($template['is_default'] ?? false),
                    'header_image_path' => $this->restoreFile($template['header_image'] ?? null, FileStorage::BILLING_TEMPLATE_IMAGES),
                ]);
            }

            foreach ($data['cost_categories'] ?? [] as $category) {
                CostCategory::firstOrCreate(['name' => $category['name']], [
                    'is_relevant_for_billing' => (bool) ($category['is_relevant_for_billing'] ?? false),
                ]);
            }

            foreach ($data['users'] ?? [] as $userData) {
                $this->findOrCreateUser($userData['email'], $userData['name'], $userData['theme_slug'] ?? null);
            }
        } else {
            // Profileinstellungen: Name und Theme des importierenden Benutzers
            // werden übernommen (die E-Mail identifiziert den Account und
            // bleibt unverändert).
            $profile = $data['profile'] ?? [];
            $user->name = $profile['name'] ?? $user->name;

            if (! empty($profile['theme_slug'])) {
                $user->theme_id = Theme::where('slug', $profile['theme_slug'])->value('id') ?? $user->theme_id;
            }

            $user->save();
        }

        $groupCount = 0;

        foreach ($data['groups'] ?? [] as $groupData) {
            $this->importGroup($user, $groupData, $isFull);
            $groupCount++;
        }

        return $this->summary($groupCount);
    }

    private function summary(int $groupCount): string
    {
        $summary = __(':groups Gruppe(n) importiert.', ['groups' => $groupCount]);

        if ($this->skippedMembers > 0) {
            $summary .= ' '.__(':members Mitglied(er) ohne Konto auf diesem System übersprungen.', ['members' => $this->skippedMembers]);
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function exportGroup(Group $group): array
    {
        $group->load('users', 'owner');

        $managementGroups = ManagementGroup::where('user_group_id', $group->id)->orderBy('id')->get();

        return [
            'name' => $group->name,
            'description' => $group->description,
            'owner_email' => $group->owner?->email,
            'members' => $group->users->map(fn (User $member) => [
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->pivot->role,
            ])->all(),
            'tenants' => Tenant::where('user_group_id', $group->id)->orderBy('id')->get()
                ->map(fn (Tenant $tenant) => [
                    'ref' => $tenant->id,
                    ...$tenant->only([
                        'salutation', 'last_name', 'first_name', 'company_name', 'street', 'postal_code', 'city',
                        'email', 'mobile_phone', 'iban', 'bic', 'notes',
                    ]),
                ])->all(),
            'invoice_issuers' => InvoiceIssuer::where('user_group_id', $group->id)->orderBy('id')->get()
                ->map(fn (InvoiceIssuer $issuer) => [
                    'ref' => $issuer->id,
                    ...$issuer->only([
                        'name', 'first_name', 'salutation', 'company_name', 'street', 'postal_code', 'city',
                        'email', 'phone', 'mobile_phone', 'iban', 'bic', 'comment',
                    ]),
                    'accounts' => $issuer->accounts()->orderBy('id')->get()
                        ->map(fn (InvoiceIssuerAccount $account) => [
                            'ref' => $account->id,
                            ...$account->only(['customer_number', 'description', 'direct_debit', 'notes']),
                        ])->all(),
                ])->all(),
            'landlord' => $this->exportLandlord(
                Landlord::where('user_group_id', $group->id)->whereNull('management_group_id')->first()
            ),
            'cost_categories' => CostCategory::where('user_group_id', $group->id)->orderBy('id')->get()
                ->map(fn (CostCategory $category) => $category->only(['name', 'is_relevant_for_billing', 'is_tax_relevant']))
                ->all(),
            'billing_templates' => BillingTemplate::where('user_group_id', $group->id)->orderBy('id')->get()
                ->map(fn (BillingTemplate $template) => [
                    ...$template->only(self::TEMPLATE_FIELDS),
                    'header_image' => $this->fileEntry($template->header_image_path, null),
                ])->all(),
            'management_groups' => $managementGroups
                ->map(fn (ManagementGroup $managementGroup) => $this->exportManagementGroup($managementGroup))->all(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function exportLandlord(?Landlord $landlord): ?array
    {
        return $landlord?->only(self::LANDLORD_FIELDS);
    }

    /**
     * @param  Collection<int, SettlementClaim>  $claims
     * @return array<int, array<string, mixed>>
     */
    private function exportSettlementClaims(Collection $claims): array
    {
        return $claims->map(fn (SettlementClaim $claim) => [
            'billing_interval_ref' => $claim->billing_interval_id,
            'amount' => $claim->amount,
            'date' => $claim->date?->toDateString(),
            'booking_date' => $claim->booking_date?->toDateString(),
            'notes' => $claim->notes,
            'file' => $this->fileEntry($claim->file_path, $claim->file_original_name),
        ])->all();
    }

    /**
     * Ids of every Mietvertrag below the given Mieteinheiten – Rechnungen/Kosten
     * können auch direkt einem Mietvertrag zugeordnet sein (costable "lease").
     *
     * @param  Collection<int, int>  $rentalUnitIds
     * @return Collection<int, int>
     */
    private function leaseIdsFor(Collection $rentalUnitIds): Collection
    {
        return Lease::whereIn('rental_unit_id', $rentalUnitIds)->pluck('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function exportManagementGroup(ManagementGroup $managementGroup): array
    {
        $units = $managementGroup->managementUnits()->orderBy('id')->get();

        return [
            'ref' => $managementGroup->id,
            'description' => $managementGroup->description,
            'billing_start' => $managementGroup->billing_start?->toDateString(),
            'kontosystem_enabled' => (bool) $managementGroup->kontosystem_enabled,
            'variable_cost_forecast_mode' => $managementGroup->variable_cost_forecast_mode,
            'default_billing_template' => $managementGroup->defaultBillingTemplate?->name,
            'landlord' => $this->exportLandlord($managementGroup->landlord),
            'cost_categories' => $managementGroup->costCategories->map(fn (CostCategory $category) => [
                'name' => $category->name,
                'is_relevant_for_billing' => (bool) $category->is_relevant_for_billing,
                'allocation_key' => $category->pivot->allocation_key,
            ])->all(),
            'billing_intervals' => $managementGroup->billingIntervals()->orderBy('id')->get()
                ->map(fn (BillingInterval $interval) => [
                    'ref' => $interval->id,
                    'start_date' => $interval->start_date?->toDateString(),
                    'end_date' => $interval->end_date?->toDateString(),
                    'description' => $interval->description,
                ])->all(),
            'accounts' => $managementGroup->accounts()->orderBy('id')->get()
                ->map(fn (Account $account) => [
                    'ref' => $account->id,
                    ...$account->only(['name', 'description', 'initial_balance', 'is_default']),
                ])->all(),
            'account_transfers' => AccountTransfer::whereIn('from_account_id', $managementGroup->accounts()->pluck('id'))
                ->orderBy('id')->get()
                ->map(fn (AccountTransfer $transfer) => [
                    'from_ref' => $transfer->from_account_id,
                    'to_ref' => $transfer->to_account_id,
                    'amount' => $transfer->amount,
                    'transfer_date' => $transfer->transfer_date?->toDateString(),
                    'comment' => $transfer->comment,
                    'file' => $this->fileEntry($transfer->file_path, $transfer->file_original_name),
                ])->all(),
            'maintenance_contracts' => $managementGroup->maintenanceContracts()->orderBy('id')->get()
                ->map(fn (MaintenanceContract $contract) => [
                    'name' => $contract->name,
                    'type' => $contract->type,
                    'provider' => $contract->provider,
                    'start_date' => $contract->start_date?->toDateString(),
                    'end_date' => $contract->end_date?->toDateString(),
                ])->all(),
            'management_units' => $units->map(fn (ManagementUnit $unit) => $this->exportManagementUnit($unit))->all(),
            'invoices' => $this->exportInvoices($managementGroup, $units),
            'costs' => $this->exportCosts($managementGroup, $units),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function exportManagementUnit(ManagementUnit $unit): array
    {
        return [
            'ref' => $unit->id,
            ...$unit->only(['street', 'postal_code', 'city', 'description']),
            'rental_units' => $unit->rentalUnits()->orderBy('id')->get()->map(fn (RentalUnit $rentalUnit) => [
                'ref' => $rentalUnit->id,
                ...$rentalUnit->only(['size', 'type', 'description', 'floor', 'is_relevant_for_billing']),
                'settlement_claims' => $this->exportSettlementClaims(
                    $rentalUnit->settlementClaims()->whereNull('lease_id')->orderBy('id')->get()
                ),
                'leases' => $rentalUnit->leases()->orderBy('id')->get()->map(fn (Lease $lease) => [
                    'ref' => $lease->id,
                    ...collect($lease->only([
                        'start_date', 'end_date', 'rent_adjustment_type', 'cold_rent',
                        'deposit_amount', 'min_term_months', 'notice_period', 'notes', 'is_pre_contract',
                    ]))->map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value)->all(),
                    'coupled_to_lease_ref' => $lease->coupled_to_lease_id,
                    'tenant_refs' => $lease->tenants()->pluck('tenants.id')->all(),
                    'settlement_claims' => $this->exportSettlementClaims($lease->settlementClaims()->orderBy('id')->get()),
                    'documents' => $lease->documents()->orderBy('id')->get()
                        ->map(fn (LeaseDocument $document) => $this->fileEntry($document->file_path, $document->original_name))
                        ->filter()->values()->all(),
                    'intervals' => $lease->leaseIntervals()->orderBy('id')->get()->map(fn (LeaseInterval $interval) => [
                        'ref' => $interval->id,
                        'start_date' => $interval->start_date?->toDateString(),
                        'end_date' => $interval->end_date?->toDateString(),
                        ...$interval->only(['rent_amount', 'occupant_count', 'utility_advance_payment', 'notes']),
                        'rent_payments' => $interval->rentPayments()->orderBy('id')->get()->map(fn (RentPayment $payment) => [
                            'period_date' => $payment->period_date?->toDateString(),
                            'booking_date' => $payment->booking_date?->toDateString(),
                            ...$payment->only(['amount', 'rent_amount', 'utility_amount']),
                            'accounts' => $payment->accounts()->get()
                                ->map(fn (Account $account) => ['ref' => $account->id, 'amount' => $account->pivot->amount])->all(),
                        ])->all(),
                    ])->all(),
                ])->all(),
            ])->all(),
        ];
    }

    /**
     * All invoices belonging to this Verwaltungsgruppe's structure: matched via
     * their costable (the group itself, a unit, or a rental unit) OR – for
     * invoices without a Zuordnung (costable null), like "Concordia"/"F&M" in
     * the test data – via an issuer of the group's Benutzergruppe.
     *
     * @param  Collection<int, ManagementUnit>  $units
     * @return array<int, array<string, mixed>>
     */
    private function exportInvoices(ManagementGroup $managementGroup, $units): array
    {
        $rentalUnitIds = RentalUnit::whereIn('management_unit_id', $units->pluck('id'))->pluck('id');
        $leaseIds = $this->leaseIdsFor($rentalUnitIds);

        $invoices = Invoice::query()
            ->where(function ($query) use ($managementGroup, $units, $rentalUnitIds, $leaseIds) {
                $query->where(fn ($q) => $q->where('costable_type', 'management_group')->where('costable_id', $managementGroup->id))
                    ->orWhere(fn ($q) => $q->where('costable_type', 'management_unit')->whereIn('costable_id', $units->pluck('id')))
                    ->orWhere(fn ($q) => $q->where('costable_type', 'rental_unit')->whereIn('costable_id', $rentalUnitIds))
                    ->orWhere(fn ($q) => $q->where('costable_type', 'lease')->whereIn('costable_id', $leaseIds))
                    ->orWhere(fn ($q) => $q->whereNull('costable_type')->whereHas(
                        'invoiceIssuer',
                        fn ($issuer) => $issuer->where('user_group_id', $managementGroup->user_group_id)
                    ));
            })
            ->orderBy('id')
            ->get();

        return $invoices->map(fn (Invoice $invoice) => [
            'ref' => $invoice->id,
            'issuer_ref' => $invoice->invoice_issuer_id,
            'issuer_account_ref' => $invoice->invoice_issuer_account_id,
            'costable' => $invoice->costable_type !== null
                ? ['type' => $invoice->costable_type, 'ref' => $invoice->costable_id]
                : null,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'booking_date' => $invoice->booking_date?->toDateString(),
            'recurrence_start' => $invoice->recurrence_start?->toDateString(),
            'recurrence_end' => $invoice->recurrence_end?->toDateString(),
            ...$invoice->only([
                'amount', 'effective_amount', 'invoice_number', 'description', 'periodicity', 'disable_amount_cap',
                'amount_segments',
            ]),
            'file' => $this->fileEntry($invoice->file_path, $invoice->file_original_name),
            'occurrences' => $invoice->occurrences()->orderBy('id')->get()
                ->map(fn ($occurrence) => [
                    'occurrence_date' => $occurrence->occurrence_date?->toDateString(),
                    'amount' => $occurrence->amount,
                ])->all(),
        ])->all();
    }

    /**
     * @param  Collection<int, ManagementUnit>  $units
     * @return array<int, array<string, mixed>>
     */
    private function exportCosts(ManagementGroup $managementGroup, $units): array
    {
        $rentalUnitIds = RentalUnit::whereIn('management_unit_id', $units->pluck('id'))->pluck('id');
        $leaseIds = $this->leaseIdsFor($rentalUnitIds);

        $costs = Cost::query()
            ->where(function ($query) use ($managementGroup, $units, $rentalUnitIds, $leaseIds) {
                $query->where(fn ($q) => $q->where('costable_type', 'management_group')->where('costable_id', $managementGroup->id))
                    ->orWhere(fn ($q) => $q->where('costable_type', 'management_unit')->whereIn('costable_id', $units->pluck('id')))
                    ->orWhere(fn ($q) => $q->where('costable_type', 'rental_unit')->whereIn('costable_id', $rentalUnitIds))
                    ->orWhere(fn ($q) => $q->where('costable_type', 'lease')->whereIn('costable_id', $leaseIds));
            })
            ->with('costCategory')
            ->orderBy('id')
            ->get();

        return $costs->map(fn (Cost $cost) => [
            'invoice_ref' => $cost->invoice_id,
            'costable' => ['type' => $cost->costable_type, 'ref' => $cost->costable_id],
            'billing_interval_ref' => $cost->billing_interval_id,
            'category' => $cost->costCategory?->name,
            'amount' => $cost->amount,
            'period_date' => $cost->period_date?->toDateString(),
            'booking_date' => $cost->booking_date?->toDateString(),
            'note' => $cost->note,
            'accounts' => $cost->accounts()->get()
                ->map(fn (Account $account) => ['ref' => $account->id, 'amount' => $account->pivot->amount])->all(),
        ])->all();
    }

    /**
     * @param  array<string, mixed>  $groupData
     */
    private function importGroup(User $importer, array $groupData, bool $isFull): void
    {
        // Beim Benutzer-Import gehört die neu angelegte Gruppe immer dem
        // Importierenden – wie bei einer Gruppe, die er in der Oberfläche anlegt.
        $owner = $isFull && isset($groupData['owner_email'])
            ? $this->findOrCreateUser($groupData['owner_email'], $groupData['owner_email'])
            : $importer;

        $group = Group::create([
            'name' => $groupData['name'],
            'description' => $groupData['description'] ?? null,
            'owner_id' => $owner->id,
        ]);

        if ($isFull || $this->wasGroupAdmin($importer, $groupData)) {
            $this->restoreMemberships($group, $groupData['members'] ?? [], $isFull);
        }

        if (! $isFull && ! $group->users()->where('users.id', $importer->id)->exists()) {
            // Beim Selbst-Import muss der Importierende seine Gruppe auch wieder
            // verwalten können – auch wenn er im Backup nur Mitglied war oder die
            // Mitgliederliste ihn (z. B. nach einer E-Mail-Änderung) nicht enthält.
            $group->users()->attach($importer->id, ['role' => self::ROLE_ADMIN]);
        }

        foreach ($groupData['tenants'] ?? [] as $tenantData) {
            $tenant = Tenant::create([
                'user_group_id' => $group->id,
                ...collect($tenantData)->only([
                    'salutation', 'last_name', 'first_name', 'company_name', 'street', 'postal_code', 'city',
                    'email', 'mobile_phone', 'iban', 'bic', 'notes',
                ])->all(),
            ]);
            $this->tenantMap[$tenantData['ref']] = $tenant->id;
        }

        foreach ($groupData['invoice_issuers'] ?? [] as $issuerData) {
            $issuer = InvoiceIssuer::create([
                'user_group_id' => $group->id,
                ...collect($issuerData)->only([
                    'name', 'first_name', 'salutation', 'company_name', 'street', 'postal_code', 'city',
                    'email', 'phone', 'mobile_phone', 'iban', 'bic', 'comment',
                ])->all(),
            ]);
            $this->issuerMap[$issuerData['ref']] = $issuer->id;

            foreach ($issuerData['accounts'] ?? [] as $accountData) {
                $account = InvoiceIssuerAccount::create([
                    'invoice_issuer_id' => $issuer->id,
                    ...collect($accountData)->only(['customer_number', 'description', 'direct_debit', 'notes'])->all(),
                ]);
                $this->issuerAccountMap[$accountData['ref']] = $account->id;
            }
        }

        $this->importLandlord($groupData['landlord'] ?? null, ['user_group_id' => $group->id, 'management_group_id' => null]);

        foreach ($groupData['cost_categories'] ?? [] as $categoryData) {
            $this->resolveOrCreateCostCategory($group, $categoryData['name'], [
                'is_relevant_for_billing' => (bool) ($categoryData['is_relevant_for_billing'] ?? false),
                'is_tax_relevant' => (bool) ($categoryData['is_tax_relevant'] ?? true),
            ]);
        }

        foreach ($groupData['billing_templates'] ?? [] as $templateData) {
            BillingTemplate::create([
                'user_group_id' => $group->id,
                ...collect($templateData)->only(self::TEMPLATE_FIELDS)->all(),
                'header_image_path' => $this->restoreFile($templateData['header_image'] ?? null, FileStorage::BILLING_TEMPLATE_IMAGES),
            ]);
        }

        foreach ($groupData['management_groups'] ?? [] as $managementGroupData) {
            $this->importManagementGroup($group, $managementGroupData);
        }
    }

    /**
     * Ob der Importierende laut Backup Admin dieser Gruppe war.
     *
     * @param  array<string, mixed>  $groupData
     */
    private function wasGroupAdmin(User $importer, array $groupData): bool
    {
        foreach ($groupData['members'] ?? [] as $member) {
            if (strtolower((string) ($member['email'] ?? '')) === $importer->email) {
                return ($member['role'] ?? null) === self::ROLE_ADMIN;
            }
        }

        return false;
    }

    /**
     * Mitglieder mit ihrer damaligen Rolle wieder eintragen. Fehlende Konten legt
     * nur der Gesamt-Import des Administrators an (ohne Passwort, mit
     * Passwortzwang); ein Benutzer-Import überspringt sie.
     *
     * @param  array<int, array<string, mixed>>  $members
     */
    private function restoreMemberships(Group $group, array $members, bool $isFull): void
    {
        foreach ($members as $member) {
            $email = strtolower(trim((string) ($member['email'] ?? '')));

            if ($email === '') {
                continue;
            }

            $memberUser = $isFull
                ? $this->findOrCreateUser($email, $member['name'] ?? $email)
                : User::where('email', $email)->first();

            if ($memberUser === null) {
                $this->skippedMembers++;

                continue;
            }

            $role = in_array($member['role'] ?? null, self::ROLES, true) ? $member['role'] : self::ROLE_USER;
            $group->users()->syncWithoutDetaching([$memberUser->id => ['role' => $role]]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @param  array{user_group_id: ?int, management_group_id: ?int}  $owner
     */
    private function importLandlord(?array $data, array $owner): void
    {
        if ($data === null) {
            return;
        }

        Landlord::updateOrCreate($owner, collect($data)->only(self::LANDLORD_FIELDS)->all());
    }

    /**
     * Gruppeneigene Vorlage gleichen Namens vor einer systemweiten – nach dem
     * Gruppen-Import existieren beide, wenn die Vorlage auch systemweit vorkommt.
     */
    private function resolveBillingTemplateId(Group $group, ?string $name): ?int
    {
        if ($name === null) {
            return null;
        }

        return BillingTemplate::where('name', $name)->where('user_group_id', $group->id)->value('id')
            ?? BillingTemplate::where('name', $name)->whereNull('user_group_id')->value('id');
    }

    /**
     * @param  array<int, array<string, mixed>>  $claims
     * @param  array{lease_id: ?int, rental_unit_id: ?int}  $owner
     */
    private function importSettlementClaims(array $claims, array $owner): void
    {
        foreach ($claims as $claimData) {
            $billingIntervalId = $this->billingIntervalMap[$claimData['billing_interval_ref']] ?? null;

            if ($billingIntervalId === null) {
                continue;
            }

            SettlementClaim::create([
                ...$owner,
                'billing_interval_id' => $billingIntervalId,
                ...collect($claimData)->only(['amount', 'date', 'booking_date', 'notes'])->all(),
                ...$this->restoreReceipt($claimData, FileStorage::SETTLEMENT_CLAIMS),
            ]);
        }
    }

    /**
     * Kopplungen ("Mietvertrag: <ref>") können auf einen später exportierten
     * Mietvertrag zeigen und werden daher erst aufgelöst, wenn alle Mietverträge
     * der Verwaltungsgruppe angelegt sind.
     */
    private function resolvePendingCouplings(): void
    {
        foreach ($this->pendingCouplings as $coupling) {
            $targetId = $this->leaseMap[$coupling['ref']] ?? null;

            if ($targetId !== null) {
                Lease::withoutEvents(fn () => Lease::whereKey($coupling['lease'])->update(['coupled_to_lease_id' => $targetId]));
            }
        }

        $this->pendingCouplings = [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function importManagementGroup(Group $group, array $data): void
    {
        $templateId = $this->resolveBillingTemplateId($group, $data['default_billing_template'] ?? null);

        // Ohne Events: der ManagementGroupObserver würde sonst ein eigenes
        // initiales BillingInterval anlegen – die Intervalle kommen hier
        // vollständig aus dem Backup.
        $managementGroup = ManagementGroup::withoutEvents(fn () => ManagementGroup::create([
            'description' => $data['description'],
            'billing_start' => $data['billing_start'] ?? null,
            'kontosystem_enabled' => (bool) ($data['kontosystem_enabled'] ?? false),
            'variable_cost_forecast_mode' => $data['variable_cost_forecast_mode'] ?? ManagementGroup::VARIABLE_FORECAST_LAST_BOOKING,
            'default_billing_template_id' => $templateId,
            'user_group_id' => $group->id,
        ]));
        $this->managementGroupMap[$data['ref']] = $managementGroup->id;
        $this->importLandlord($data['landlord'] ?? null, ['user_group_id' => null, 'management_group_id' => $managementGroup->id]);

        foreach ($data['cost_categories'] ?? [] as $categoryData) {
            $category = $this->resolveOrCreateCostCategory($group, $categoryData['name'], [
                'is_relevant_for_billing' => (bool) ($categoryData['is_relevant_for_billing'] ?? false),
            ]);
            $managementGroup->costCategories()
                ->syncWithoutDetaching([$category->id => ['allocation_key' => $categoryData['allocation_key'] ?? null]]);
        }

        foreach ($data['billing_intervals'] ?? [] as $intervalData) {
            $interval = BillingInterval::create([
                'management_group_id' => $managementGroup->id,
                'start_date' => $intervalData['start_date'],
                'end_date' => $intervalData['end_date'],
                'description' => $intervalData['description'] ?? null,
            ]);
            $this->billingIntervalMap[$intervalData['ref']] = $interval->id;
        }

        foreach ($data['accounts'] ?? [] as $accountData) {
            $account = Account::create([
                'management_group_id' => $managementGroup->id,
                ...collect($accountData)->only(['name', 'description', 'initial_balance', 'is_default'])->all(),
            ]);
            $this->accountMap[$accountData['ref']] = $account->id;
        }

        foreach ($data['maintenance_contracts'] ?? [] as $contractData) {
            MaintenanceContract::create([
                'management_group_id' => $managementGroup->id,
                ...collect($contractData)->only(['name', 'type', 'provider', 'start_date', 'end_date'])->all(),
            ]);
        }

        foreach ($data['management_units'] ?? [] as $unitData) {
            $this->importManagementUnit($managementGroup, $unitData);
        }

        $this->resolvePendingCouplings();

        foreach ($data['account_transfers'] ?? [] as $transferData) {
            $from = $this->accountMap[$transferData['from_ref']] ?? null;
            $to = $this->accountMap[$transferData['to_ref']] ?? null;

            if ($from === null || $to === null) {
                continue;
            }

            AccountTransfer::create([
                'from_account_id' => $from,
                'to_account_id' => $to,
                'amount' => $transferData['amount'],
                'transfer_date' => $transferData['transfer_date'],
                'comment' => $transferData['comment'] ?? null,
                ...$this->restoreReceipt($transferData, FileStorage::ACCOUNT_TRANSFERS),
            ]);
        }

        foreach ($data['invoices'] ?? [] as $invoiceData) {
            $this->importInvoice($invoiceData);
        }

        foreach ($data['costs'] ?? [] as $costData) {
            $this->importCost($group, $costData);
        }
    }

    /**
     * Resolves a Kosten-Kategorie by name to one this Group can already see
     * (systemweit oder bereits eigene, siehe CostCategory::scopeVisibleTo()) statt sie
     * zu duplizieren; nur wenn keine davon existiert, wird eine neue, auf diese Gruppe
     * beschränkte Kategorie angelegt.
     *
     * @param  array<string, mixed>  $attributesIfCreated
     */
    private function resolveOrCreateCostCategory(Group $group, string $name, array $attributesIfCreated): CostCategory
    {
        $category = CostCategory::where('name', $name)
            ->where(fn ($q) => $q->whereNull('user_group_id')->orWhere('user_group_id', $group->id))
            ->first();

        return $category ?? CostCategory::create(['user_group_id' => $group->id, 'name' => $name, ...$attributesIfCreated]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function importManagementUnit(ManagementGroup $managementGroup, array $data): void
    {
        $unit = ManagementUnit::create([
            'management_group_id' => $managementGroup->id,
            ...collect($data)->only(['street', 'postal_code', 'city', 'description'])->all(),
        ]);
        $this->managementUnitMap[$data['ref']] = $unit->id;

        foreach ($data['rental_units'] ?? [] as $rentalUnitData) {
            $rentalUnit = RentalUnit::create([
                'management_unit_id' => $unit->id,
                ...collect($rentalUnitData)->only(['size', 'type', 'description', 'floor', 'is_relevant_for_billing'])->all(),
            ]);
            $this->rentalUnitMap[$rentalUnitData['ref']] = $rentalUnit->id;
            $this->importSettlementClaims($rentalUnitData['settlement_claims'] ?? [], ['lease_id' => null, 'rental_unit_id' => $rentalUnit->id]);

            foreach ($rentalUnitData['leases'] ?? [] as $leaseData) {
                $this->importLease($rentalUnit, $leaseData);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function importLease(RentalUnit $rentalUnit, array $data): void
    {
        // Ohne Events: der LeaseObserver würde sonst ein eigenes erstes
        // LeaseInterval anlegen – die Intervalle kommen aus dem Backup.
        $lease = Lease::withoutEvents(fn () => Lease::create([
            'rental_unit_id' => $rentalUnit->id,
            ...collect($data)->only([
                'start_date', 'end_date', 'rent_adjustment_type', 'cold_rent',
                'deposit_amount', 'min_term_months', 'notice_period', 'notes', 'is_pre_contract',
            ])->all(),
        ]));

        if (isset($data['ref'])) {
            $this->leaseMap[$data['ref']] = $lease->id;
        }

        if (isset($data['coupled_to_lease_ref'])) {
            $this->pendingCouplings[] = ['lease' => $lease->id, 'ref' => $data['coupled_to_lease_ref']];
        }

        $this->importSettlementClaims($data['settlement_claims'] ?? [], ['lease_id' => $lease->id, 'rental_unit_id' => null]);

        $tenantIds = collect($data['tenant_refs'] ?? [])
            ->map(fn ($ref) => $this->tenantMap[$ref] ?? null)
            ->filter()
            ->all();
        $lease->tenants()->sync($tenantIds);

        foreach ($data['documents'] ?? [] as $documentData) {
            $path = $this->restoreFile($documentData, FileStorage::LEASE_DOCUMENTS);

            if ($path === null) {
                continue;
            }

            LeaseDocument::create([
                'lease_id' => $lease->id,
                'file_path' => $path,
                'original_name' => $documentData['original_name'] ?? basename($path),
            ]);
        }

        foreach ($data['intervals'] ?? [] as $intervalData) {
            $interval = LeaseInterval::create([
                'lease_id' => $lease->id,
                ...collect($intervalData)->only([
                    'start_date', 'end_date', 'rent_amount', 'occupant_count', 'utility_advance_payment', 'notes',
                ])->all(),
            ]);

            foreach ($intervalData['rent_payments'] ?? [] as $paymentData) {
                $payment = RentPayment::create([
                    'lease_interval_id' => $interval->id,
                    ...collect($paymentData)->only([
                        'period_date', 'amount', 'rent_amount', 'utility_amount', 'booking_date',
                    ])->all(),
                ]);

                $this->attachAccountAmounts($payment->accounts(), $paymentData['accounts'] ?? []);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function importInvoice(array $data): void
    {
        $issuerId = $this->issuerMap[$data['issuer_ref']] ?? null;

        if ($issuerId === null) {
            return;
        }

        $costable = $data['costable'] ?? null;
        [$costableType, $costableId] = $costable !== null
            ? $this->resolveCostable($costable['type'], $costable['ref'])
            : [null, null];

        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuerId,
            'invoice_issuer_account_id' => isset($data['issuer_account_ref'])
                ? ($this->issuerAccountMap[$data['issuer_account_ref']] ?? null)
                : null,
            'costable_type' => $costableType,
            'costable_id' => $costableId,
            'file_path' => $this->restoreFile($data['file'] ?? null, FileStorage::INVOICES),
            'file_original_name' => $data['file']['original_name'] ?? null,
            ...collect($data)->only([
                'amount', 'effective_amount', 'invoice_number', 'invoice_date', 'booking_date', 'description',
                'periodicity', 'recurrence_start', 'recurrence_end', 'disable_amount_cap', 'amount_segments',
            ])->all(),
        ]);
        $this->invoiceMap[$data['ref']] = $invoice->id;

        foreach ($data['occurrences'] ?? [] as $occurrenceData) {
            $invoice->occurrences()->create([
                'occurrence_date' => $occurrenceData['occurrence_date'],
                'amount' => $occurrenceData['amount'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function importCost(Group $group, array $data): void
    {
        [$costableType, $costableId] = $this->resolveCostable($data['costable']['type'], $data['costable']['ref']);
        $categoryId = isset($data['category']) ? $this->resolveOrCreateCostCategory($group, $data['category'], [])->id : null;
        $billingIntervalId = $this->billingIntervalMap[$data['billing_interval_ref']] ?? null;

        if ($costableType === null || $categoryId === null || $billingIntervalId === null) {
            return;
        }

        $cost = Cost::create([
            'invoice_id' => isset($data['invoice_ref']) ? ($this->invoiceMap[$data['invoice_ref']] ?? null) : null,
            'costable_type' => $costableType,
            'costable_id' => $costableId,
            'billing_interval_id' => $billingIntervalId,
            'cost_category_id' => $categoryId,
            'amount' => $data['amount'],
            'period_date' => $data['period_date'],
            'booking_date' => $data['booking_date'] ?? null,
            'note' => $data['note'] ?? null,
        ]);

        $this->attachAccountAmounts($cost->accounts(), $data['accounts'] ?? []);
    }

    /**
     * @return array{0: ?string, 1: ?int}
     */
    private function resolveCostable(string $type, int|string $ref): array
    {
        $id = match ($type) {
            'management_group' => $this->managementGroupMap[$ref] ?? null,
            'management_unit' => $this->managementUnitMap[$ref] ?? null,
            'rental_unit' => $this->rentalUnitMap[$ref] ?? null,
            'lease' => $this->leaseMap[$ref] ?? null,
            default => null,
        };

        return $id !== null ? [$type, $id] : [null, null];
    }

    /**
     * @param  BelongsToMany<Account, covariant \Illuminate\Database\Eloquent\Model>  $relation
     * @param  array<int, array{ref: int|string, amount: mixed}>  $entries
     */
    private function attachAccountAmounts($relation, array $entries): void
    {
        foreach ($entries as $entry) {
            $accountId = $this->accountMap[$entry['ref']] ?? null;

            if ($accountId !== null) {
                $relation->attach($accountId, ['amount' => $entry['amount']]);
            }
        }
    }

    /**
     * Users are matched by e-mail; missing ones are recreated WITHOUT a password
     * (backups deliberately contain no password hashes) and must set one on
     * first login – the same bootstrap flow as the Admin-Account.
     */
    private function findOrCreateUser(string $email, string $name, ?string $themeSlug = null): User
    {
        $email = strtolower($email);
        $user = User::where('email', $email)->first();

        if ($user !== null) {
            return $user;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make(''),
            'theme_id' => $themeSlug !== null ? Theme::where('slug', $themeSlug)->value('id') : null,
        ]);
        $user->must_change_password = true;
        $user->save();

        return $user;
    }

    /**
     * @return array{path: string, original_name: ?string}|null
     */
    private function fileEntry(?string $storagePath, ?string $originalName): ?array
    {
        if ($storagePath === null || ! FileStorage::disk()->exists($storagePath)) {
            return null;
        }

        return ['path' => $storagePath, 'original_name' => $originalName];
    }

    /**
     * Extracts a file entry from the import ZIP into the file disk (FileStorage) under
     * a fresh, collision-free name inside the directory of $kind; returns the new path.
     *
     * @param  array{path?: string, original_name?: ?string}|null  $entry
     */
    private function restoreFile(?array $entry, string $kind): ?string
    {
        if ($entry === null || empty($entry['path']) || $this->importZip === null) {
            return null;
        }

        $contents = $this->importZip->getFromName('files/'.$entry['path']);

        if ($contents === false) {
            return null;
        }

        $extension = pathinfo($entry['path'], PATHINFO_EXTENSION);
        $path = FileStorage::directory($kind).'/'.Str::uuid().($extension !== '' ? ".{$extension}" : '');

        FileStorage::disk()->put($path, $contents);
        $this->importedFiles[] = $path;

        return $path;
    }

    /**
     * Beleg (file_path, file_original_name) einer Forderung bzw. Umbuchung aus dem Import.
     *
     * @param  array<string, mixed>  $data
     * @return array{file_path: ?string, file_original_name: ?string}
     */
    private function restoreReceipt(array $data, string $kind): array
    {
        $path = $this->restoreFile($data['file'] ?? null, $kind);

        return ['file_path' => $path, 'file_original_name' => $path === null ? null : ($data['file']['original_name'] ?? null)];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeZip(array $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'hv-backup-');

        if ($path === false) {
            throw new \RuntimeException('Could not create temp file for the backup archive.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not open the backup archive for writing.');
        }

        $zip->addFromString('backup.json', (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        foreach ($this->collectFilePaths($data) as $filePath) {
            $absolute = FileStorage::disk()->path($filePath);

            if (is_file($absolute)) {
                $zip->addFile($absolute, 'files/'.$filePath);
            }
        }

        $zip->close();

        return $path;
    }

    /**
     * Walks the serialized data for every file entry ({path, original_name})
     * produced by fileEntry() and returns the unique storage paths to bundle.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function collectFilePaths(array $data): array
    {
        $paths = [];

        array_walk_recursive($data, function ($value, $key) use (&$paths) {
            if ($key === 'path' && is_string($value)) {
                $paths[] = $value;
            }
        });

        return array_values(array_unique($paths));
    }

    private function resetMaps(): void
    {
        $this->tenantMap = [];
        $this->issuerMap = [];
        $this->issuerAccountMap = [];
        $this->managementGroupMap = [];
        $this->managementUnitMap = [];
        $this->rentalUnitMap = [];
        $this->billingIntervalMap = [];
        $this->accountMap = [];
        $this->invoiceMap = [];
        $this->leaseMap = [];
        $this->pendingCouplings = [];
        $this->skippedMembers = 0;
    }
}
