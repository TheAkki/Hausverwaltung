<?php

namespace App\Services\GroupOwnership;

use App\Models\BillingTemplate;
use App\Models\Cost;
use App\Models\CostCategory;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Verschiebt eine Verwaltungsgruppe in eine andere Benutzergruppe und nimmt ihre
 * Stammdaten mit (Mieter, Rechnungssteller samt Kundenkonten, eigene Kategorien) und
 * ihre Standardvorlage (ReferenceIntegrity):
 *
 * - gibt es in der neuen Gruppe schon einen gleichen Eintrag, wird dieser verwendet;
 * - verwendet nur diese Verwaltungsgruppe den Eintrag, zieht er mit um;
 * - verwendet ihn auch eine andere, bekommt die neue Gruppe eine Kopie.
 *
 * Eine Vorlage wird nie verschoben, sondern kopiert: sie gehört der Gruppe auch ohne
 * Verwaltungsgruppe (z. B. als Grundlage weiterer Vorlagen).
 *
 * Alles oder nichts: in einer Transaktion, die am Ende die Regel aus GroupOwnership
 * prüft. Weboberfläche, API und Seeder verwenden dieselbe Logik.
 */
final class ManagementGroupTransfer
{
    private const LEASE_TENANT_TABLE = 'lease_tenant';

    private const CATEGORY_PIVOT_TABLE = 'management_group_cost_category';

    public function __construct(private readonly MasterDataCopier $copier) {}

    public function move(ManagementGroup $managementGroup, int $targetGroupId): TransferReport
    {
        $report = new TransferReport;

        if ($managementGroup->user_group_id === $targetGroupId) {
            return $report;
        }

        DB::transaction(fn () => GroupOwnership::suspended(function () use ($managementGroup, $targetGroupId, $report) {
            $idsByType = ManagementGroupCostables::idsOf($managementGroup);

            $this->moveTenants($idsByType, $targetGroupId, $report);
            $this->moveIssuers($idsByType, $targetGroupId, $report);
            $this->moveCategories($managementGroup, $idsByType, $targetGroupId, $report);
            $this->moveTemplate($managementGroup, $targetGroupId, $report);

            ManagementGroup::whereKey($managementGroup->id)->update(['user_group_id' => $targetGroupId]);
            $managementGroup->user_group_id = $targetGroupId;
            $managementGroup->syncOriginalAttribute('user_group_id');

            GroupOwnership::assertNoViolations($managementGroup, $targetGroupId);
        }));

        return $report;
    }

    /**
     * @param  array<string, list<int>>  $idsByType
     */
    private function moveTenants(array $idsByType, int $targetGroupId, TransferReport $report): void
    {
        $leaseIds = $idsByType[(new Lease)->getMorphClass()];

        foreach (GroupOwnership::foreignTenants($idsByType, $targetGroupId) as $tenant) {
            $usedElsewhere = $tenant->leases()->whereNotIn('leases.id', $leaseIds)->exists();
            $target = $this->relocate($tenant, $targetGroupId, $usedElsewhere, GroupOwnership::tenantLabel($tenant), $report);

            if (! $target->is($tenant)) {
                $this->repointPivot(self::LEASE_TENANT_TABLE, 'lease_id', $leaseIds, 'tenant_id', $tenant->id, $target->id);
            }
        }
    }

    /**
     * @param  array<string, list<int>>  $idsByType
     */
    private function moveIssuers(array $idsByType, int $targetGroupId, TransferReport $report): void
    {
        $invoiceIds = ManagementGroupCostables::invoices($idsByType)->pluck('id')->all();

        foreach (GroupOwnership::foreignIssuers($idsByType, $targetGroupId) as $issuer) {
            $usedElsewhere = $issuer->invoices()->whereNotIn('id', $invoiceIds)->exists();
            $target = $this->relocate($issuer, $targetGroupId, $usedElsewhere, GroupOwnership::issuerLabel($issuer), $report);

            if (! $target->is($issuer)) {
                $this->repointInvoices($issuer, $target, $invoiceIds);
            }
        }
    }

    /**
     * @param  array<string, list<int>>  $idsByType
     */
    private function moveCategories(ManagementGroup $managementGroup, array $idsByType, int $targetGroupId, TransferReport $report): void
    {
        foreach (GroupOwnership::foreignCategories($managementGroup, $idsByType, $targetGroupId) as $category) {
            $usedElsewhere = $this->categoryUsedElsewhere($category, $managementGroup, $idsByType);
            $target = $this->relocate($category, $targetGroupId, $usedElsewhere, GroupOwnership::categoryLabel($category), $report);

            if (! $target->is($category)) {
                ManagementGroupCostables::costs($idsByType)->where('cost_category_id', $category->id)
                    ->update(['cost_category_id' => $target->id]);
                $this->repointPivot(self::CATEGORY_PIVOT_TABLE, 'management_group_id', [$managementGroup->id], 'cost_category_id', $category->id, $target->id);
            }
        }
    }

    /**
     * Formulardaten nach move(): steht darin noch die bisherige Standardvorlage, gilt
     * die in die neue Gruppe mitgenommene – eine bewusst andere Auswahl bleibt.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function keepMovedTemplate(array $validated, ?int $templateBefore, ManagementGroup $managementGroup): array
    {
        $field = ReferenceIntegrity::FIELD_TEMPLATE;
        $submitted = $validated[$field] ?? null;

        if ($templateBefore !== null && $submitted !== null && (int) $submitted === $templateBefore) {
            $validated[$field] = $managementGroup->default_billing_template_id;
        }

        return $validated;
    }

    private function moveTemplate(ManagementGroup $managementGroup, int $targetGroupId, TransferReport $report): void
    {
        $template = BillingTemplate::find($managementGroup->default_billing_template_id);

        if ($template === null || ReferenceIntegrity::templateFits($template, $targetGroupId)) {
            return;
        }

        $label = __('Vorlage „:name“', ['name' => $template->name]);
        [$target, $created] = $this->copier->copyTo($template, $targetGroupId);
        if ($created) {
            $report->copied[] = $label;
        } else {
            $report->reused[] = $label;
        }

        ManagementGroup::whereKey($managementGroup->id)->update(['default_billing_template_id' => $target->id]);
        $managementGroup->default_billing_template_id = $target->id;
        $managementGroup->syncOriginalAttribute('default_billing_template_id');
    }

    /**
     * @param  array<string, list<int>>  $idsByType
     */
    private function categoryUsedElsewhere(CostCategory $category, ManagementGroup $managementGroup, array $idsByType): bool
    {
        $ownCostIds = ManagementGroupCostables::costs($idsByType)->where('cost_category_id', $category->id)->pluck('id');

        return Cost::where('cost_category_id', $category->id)->whereNotIn('id', $ownCostIds)->exists()
            || DB::table(self::CATEGORY_PIVOT_TABLE)->where('cost_category_id', $category->id)
                ->where('management_group_id', '!=', $managementGroup->id)->exists();
    }

    /**
     * Der Eintrag, den die Verwaltungsgruppe in der neuen Gruppe verwendet.
     *
     * @template TModel of Tenant|InvoiceIssuer|CostCategory
     *
     * @param  TModel  $model
     * @return TModel
     */
    private function relocate(Model $model, int $targetGroupId, bool $usedElsewhere, string $label, TransferReport $report): Model
    {
        $equal = $this->copier->findEqual($model, $targetGroupId);

        if ($equal !== null) {
            $report->reused[] = $label;

            return $equal;
        }

        if ($usedElsewhere) {
            $report->copied[] = $label;

            return $this->copier->copyTo($model, $targetGroupId)[0];
        }

        $model->newQuery()->whereKey($model->getKey())->update(['user_group_id' => $targetGroupId]);
        $model->setAttribute('user_group_id', $targetGroupId);
        $report->moved[] = $label;

        return $model;
    }

    /**
     * @param  list<int>  $invoiceIds
     */
    private function repointInvoices(InvoiceIssuer $from, InvoiceIssuer $to, array $invoiceIds): void
    {
        $invoices = Invoice::whereIn('id', $invoiceIds)->where('invoice_issuer_id', $from->id);

        foreach ($this->copier->accountMap($from, $to) as $oldAccountId => $newAccountId) {
            (clone $invoices)->where('invoice_issuer_account_id', $oldAccountId)
                ->update(['invoice_issuer_account_id' => $newAccountId]);
        }

        $invoices->update(['invoice_issuer_id' => $to->id]);
    }

    /**
     * Hängt Pivot-Zeilen der $owners von $old auf $new um; eine Zeile, die es für
     * $new schon gibt, fällt weg (Unique-Index).
     *
     * @param  list<int>  $ownerIds
     */
    private function repointPivot(string $table, string $ownerColumn, array $ownerIds, string $column, int $old, int $new): void
    {
        $alreadyLinked = DB::table($table)->whereIn($ownerColumn, $ownerIds)->where($column, $new)->pluck($ownerColumn);

        DB::table($table)->whereIn($ownerColumn, $alreadyLinked)->where($column, $old)->delete();
        DB::table($table)->whereIn($ownerColumn, $ownerIds)->where($column, $old)->update([$column => $new]);
    }
}
