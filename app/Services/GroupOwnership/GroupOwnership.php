<?php

namespace App\Services\GroupOwnership;

use App\Exceptions\GroupMismatchException;
use App\Models\Cost;
use App\Models\CostCategory;
use App\Models\Group;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\Tenant;
use App\Services\InvoiceSeries\CostableManagementGroup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * Regel: Mieter, Rechnungssteller und eigene Kategorien gehören zur selben
 * Benutzergruppe wie die Verwaltungsgruppe, in der sie verwendet werden. Sonst sähe
 * jedes Mitglied einer gemeinsamen Gruppe über Mietverträge und Rechnungen Stammdaten
 * einer fremden (z. B. der persönlichen) Gruppe – und umgekehrt. Systemkategorien
 * (ohne Gruppe) passen überall.
 *
 * Eine Logik für Weboberfläche, API und Seeder: die Controller prüfen vor dem
 * Schreiben, die Modell-Observer als Sicherheitsnetz.
 */
final class GroupOwnership
{
    public const FIELD_TENANTS = 'tenant_ids';

    public const FIELD_ISSUER = 'invoice_issuer_id';

    public const FIELD_CATEGORY = 'cost_category_id';

    public const FIELD_GROUP = 'user_group_id';

    private static int $suspensions = 0;

    /**
     * Führt $callback ohne die Prüfungen der Observer aus – für das Wiederherstellen
     * eines Backups (stellt den gesicherten Zustand her, wie er war) und für das
     * Verschieben einer Verwaltungsgruppe (prüft am Ende selbst).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function suspended(callable $callback): mixed
    {
        self::$suspensions++;

        try {
            return $callback();
        } finally {
            self::$suspensions--;
        }
    }

    public static function isSuspended(): bool
    {
        return self::$suspensions > 0;
    }

    /**
     * Die Verwaltungsgruppe einer Kostenstelle, frisch aus der Datenbank (nicht aus
     * einer womöglich veralteten geladenen Relation); null ohne Kostenstelle.
     */
    public static function managementGroupOf(?string $costableType, int|string|null $costableId): ?ManagementGroup
    {
        $class = $costableType === null ? null : Relation::getMorphedModel($costableType);
        $costable = $class === null || $costableId === null ? null : $class::find($costableId);

        return $costable === null ? null : CostableManagementGroup::of($costable);
    }

    /**
     * Alle Verwaltungsgruppen einer Rechnung: die ihrer Zuordnung und die ihrer
     * Kostenzeilen.
     *
     * @return list<ManagementGroup>
     */
    public static function managementGroupsOf(Invoice $invoice): array
    {
        return collect([self::managementGroupOf($invoice->costable_type, $invoice->costable_id)])
            ->merge($invoice->costs->map(fn (Cost $cost) => self::managementGroupOf($cost->costable_type, $cost->costable_id)))
            ->filter()->unique('id')->values()->all();
    }

    /**
     * Ein Mieter ohne Gruppe (nur Altdaten; Oberfläche und API verlangen immer eine)
     * gehört keiner Gruppe und gibt daher keiner fremden etwas preis.
     */
    public static function tenantFits(Tenant $tenant, ManagementGroup $managementGroup): bool
    {
        return $tenant->user_group_id === null || $tenant->user_group_id === $managementGroup->user_group_id;
    }

    /**
     * Wie bei tenantFits(): ein Rechnungssteller ohne Gruppe passt überall.
     */
    public static function issuerFits(InvoiceIssuer $issuer, ManagementGroup $managementGroup): bool
    {
        return $issuer->user_group_id === null || $issuer->user_group_id === $managementGroup->user_group_id;
    }

    public static function categoryFits(CostCategory $category, ManagementGroup $managementGroup): bool
    {
        return $category->user_group_id === null || $category->user_group_id === $managementGroup->user_group_id;
    }

    public static function assertTenantFits(Tenant $tenant, ManagementGroup $managementGroup, string $field = self::FIELD_TENANTS): void
    {
        if (! self::tenantFits($tenant, $managementGroup)) {
            self::fail($field, self::tenantLabel($tenant), $tenant->user_group_id, $managementGroup);
        }
    }

    /**
     * Prüft die Mieter-Ids eines Formulars vor dem Speichern; schon zugeordnete
     * Mieter ($alreadyAttached, z. B. aus Altdaten) bleiben unbeanstandet.
     *
     * @param  iterable<int|string>  $tenantIds
     * @param  iterable<int|string>  $alreadyAttached
     */
    public static function assertTenantIdsFit(iterable $tenantIds, ManagementGroup $managementGroup, iterable $alreadyAttached = []): void
    {
        $newIds = collect($tenantIds)->map(fn ($id) => (int) $id)->diff(collect($alreadyAttached)->map(fn ($id) => (int) $id));

        foreach (Tenant::whereIn('id', $newIds)->orderBy('id')->get() as $tenant) {
            self::assertTenantFits($tenant, $managementGroup);
        }
    }

    public static function assertIssuerFits(InvoiceIssuer $issuer, ManagementGroup $managementGroup, string $field = self::FIELD_ISSUER): void
    {
        if (! self::issuerFits($issuer, $managementGroup)) {
            self::fail($field, self::issuerLabel($issuer), $issuer->user_group_id, $managementGroup);
        }
    }

    public static function assertCategoryFits(CostCategory $category, ManagementGroup $managementGroup, string $field = self::FIELD_CATEGORY): void
    {
        if (! self::categoryFits($category, $managementGroup)) {
            self::fail($field, self::categoryLabel($category), $category->user_group_id, $managementGroup);
        }
    }

    /**
     * Alle Stammdaten, die die Verwaltungsgruppe verwendet und die NICHT in der Gruppe
     * $groupId liegen – als lesbare Bezeichnungen, z. B. „Mieter „Anna Muster““.
     *
     * @return list<string>
     */
    public static function violations(ManagementGroup $managementGroup, int $groupId): array
    {
        $idsByType = ManagementGroupCostables::idsOf($managementGroup);

        return [
            ...self::foreignTenants($idsByType, $groupId)->map(fn (Tenant $t) => self::tenantLabel($t))->all(),
            ...self::foreignIssuers($idsByType, $groupId)->map(fn (InvoiceIssuer $i) => self::issuerLabel($i))->all(),
            ...self::foreignCategories($managementGroup, $idsByType, $groupId)->map(fn (CostCategory $c) => self::categoryLabel($c))->all(),
        ];
    }

    /**
     * Wirft, wenn die Verwaltungsgruppe in der Gruppe $groupId Stammdaten einer
     * fremden Gruppe verwenden würde.
     */
    public static function assertNoViolations(ManagementGroup $managementGroup, int $groupId): void
    {
        $violations = self::violations($managementGroup, $groupId);

        if ($violations !== []) {
            throw new GroupMismatchException(self::FIELD_GROUP, __(
                'Die Verwaltungsgruppe passt nicht zur Gruppe „:group“, weil sie diese Stammdaten einer anderen Gruppe verwendet: :list',
                ['group' => self::groupName($groupId), 'list' => implode(', ', $violations)]
            ));
        }
    }

    /**
     * @param  array<string, list<int>>  $idsByType
     * @return Collection<int, Tenant>
     */
    public static function foreignTenants(array $idsByType, int $groupId)
    {
        $leaseIds = $idsByType[(new Lease)->getMorphClass()] ?? [];

        return Tenant::whereHas('leases', fn ($q) => $q->whereIn('leases.id', $leaseIds))
            ->whereNotNull('user_group_id')->where('user_group_id', '!=', $groupId)
            ->orderBy('id')->get();
    }

    /**
     * @param  array<string, list<int>>  $idsByType
     * @return Collection<int, InvoiceIssuer>
     */
    public static function foreignIssuers(array $idsByType, int $groupId)
    {
        $issuerIds = ManagementGroupCostables::invoices($idsByType)->distinct()->pluck('invoice_issuer_id');

        return InvoiceIssuer::whereIn('id', $issuerIds)
            ->whereNotNull('user_group_id')->where('user_group_id', '!=', $groupId)
            ->orderBy('id')->get();
    }

    /**
     * @param  array<string, list<int>>  $idsByType
     * @return Collection<int, CostCategory>
     */
    public static function foreignCategories(ManagementGroup $managementGroup, array $idsByType, int $groupId)
    {
        $categoryIds = ManagementGroupCostables::costs($idsByType)->distinct()->pluck('cost_category_id')
            ->merge(DB::table('management_group_cost_category')
                ->where('management_group_id', $managementGroup->id)->pluck('cost_category_id'))
            ->unique();

        return CostCategory::whereIn('id', $categoryIds)
            ->whereNotNull('user_group_id')->where('user_group_id', '!=', $groupId)
            ->orderBy('id')->get();
    }

    public static function tenantLabel(Tenant $tenant): string
    {
        return __('Mieter „:name“', ['name' => $tenant->fullName()]);
    }

    public static function issuerLabel(InvoiceIssuer $issuer): string
    {
        return __('Rechnungssteller „:name“', ['name' => $issuer->name]);
    }

    public static function categoryLabel(CostCategory $category): string
    {
        return __('Kategorie „:name“', ['name' => $category->name]);
    }

    private static function fail(string $field, string $subject, ?int $ownerGroupId, ManagementGroup $managementGroup): never
    {
        throw new GroupMismatchException($field, __(
            ':subject gehört zur Gruppe „:owner“, die Verwaltungsgruppe „:management_group“ aber zur Gruppe „:group“. Beide müssen in derselben Gruppe liegen.',
            [
                'subject' => $subject,
                'owner' => self::groupName($ownerGroupId),
                'management_group' => (string) $managementGroup->description,
                'group' => self::groupName($managementGroup->user_group_id),
            ]
        ));
    }

    private static function groupName(?int $groupId): string
    {
        return $groupId === null ? '–' : (string) Group::whereKey($groupId)->value('name');
    }
}
