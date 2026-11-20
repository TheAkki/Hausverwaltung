<?php

namespace App\Services\GroupOwnership;

use App\Models\BillingTemplate;
use App\Models\CostCategory;
use App\Models\InvoiceIssuer;
use App\Models\InvoiceIssuerAccount;
use App\Models\Tenant;
use App\Support\FileStorage;
use Illuminate\Database\Eloquent\Model;

/**
 * Holt Stammdaten (Mieter, Rechnungssteller samt Kundenkonten, eigene Kategorien,
 * Abrechnungsvorlagen samt Kopfbild) in
 * eine andere Benutzergruppe: nimmt dort einen gleichen Eintrag, wenn es ihn schon
 * gibt (keine Dubletten, z. B. dieselben Stadtwerke für zwei verschobene
 * Verwaltungsgruppen), sonst eine Kopie. Das Original bleibt unverändert.
 */
final class MasterDataCopier
{
    /** Woran ein gleicher Eintrag erkannt wird (Mieter und Rechnungssteller wie im Importskript). */
    public const KEY_FIELDS = [
        Tenant::class => ['first_name', 'last_name', 'street', 'postal_code', 'city'],
        InvoiceIssuer::class => ['name', 'postal_code', 'city'],
        CostCategory::class => ['name'],
        BillingTemplate::class => ['name'],
    ];

    /** Woran ein gleiches Kundenkonto desselben Rechnungsstellers erkannt wird. */
    public const ACCOUNT_KEY_FIELD = 'customer_number';

    /**
     * Ein gleicher Eintrag in der Gruppe, falls vorhanden.
     *
     * @template TModel of Tenant|InvoiceIssuer|CostCategory|BillingTemplate
     *
     * @param  TModel  $model
     * @return TModel|null
     */
    public function findEqual(Model $model, int $groupId): ?Model
    {
        if ($model->getAttribute('user_group_id') === $groupId) {
            return $model;
        }

        $query = $model->newQuery()->where('user_group_id', $groupId);
        foreach (self::KEY_FIELDS[$model::class] as $field) {
            $query->where($field, $model->getAttribute($field));
        }

        return $query->orderBy('id')->first();
    }

    /**
     * Der Eintrag in der Gruppe: ein gleicher vorhandener oder eine neue Kopie. Bei
     * einem Rechnungssteller kommen alle seine Kundenkonten mit.
     *
     * @template TModel of Tenant|InvoiceIssuer|CostCategory|BillingTemplate
     *
     * @param  TModel  $model
     * @return array{0: TModel, 1: bool} der Eintrag in der Gruppe und ob er neu angelegt wurde
     */
    public function copyTo(Model $model, int $groupId): array
    {
        $existing = $this->findEqual($model, $groupId);
        $target = $existing ?? $this->replicateInto($model, $groupId);

        if ($model instanceof InvoiceIssuer) {
            $this->accountMap($model, $target);
        }

        return [$target, $existing === null];
    }

    /**
     * Ordnet jedem Kundenkonto von $from das gleiche Kundenkonto von $to zu und legt
     * fehlende dort an.
     *
     * @return array<int, int> Id des Kundenkontos von $from => Id bei $to
     */
    public function accountMap(InvoiceIssuer $from, InvoiceIssuer $to): array
    {
        $map = [];

        foreach ($from->accounts()->orderBy('id')->get() as $account) {
            $map[$account->id] = $from->is($to) ? $account->id : $this->accountIn($account, $to)->id;
        }

        return $map;
    }

    private function accountIn(InvoiceIssuerAccount $account, InvoiceIssuer $issuer): InvoiceIssuerAccount
    {
        $existing = $issuer->accounts()->where(self::ACCOUNT_KEY_FIELD, $account->{self::ACCOUNT_KEY_FIELD})->first();

        if ($existing !== null) {
            return $existing;
        }

        $copy = $account->replicate();
        $copy->invoice_issuer_id = $issuer->id;
        $copy->save();

        return $copy;
    }

    /**
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @return TModel
     */
    private function replicateInto(Model $model, int $groupId): Model
    {
        $copy = $model->replicate();
        $copy->setAttribute('user_group_id', $groupId);

        if ($copy instanceof BillingTemplate) {
            // Eigene Datei: das Löschen der einen Vorlage darf das Bild der anderen nicht entfernen.
            $copy->header_image_path = FileStorage::duplicate($copy->header_image_path);
            $copy->is_default = false;
        }

        $copy->save();

        return $copy;
    }
}
