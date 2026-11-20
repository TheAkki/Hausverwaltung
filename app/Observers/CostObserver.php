<?php

namespace App\Observers;

use App\Models\Cost;
use App\Models\CostCategory;
use App\Models\Invoice;
use App\Services\GroupOwnership\GroupOwnership;
use App\Services\GroupOwnership\ReferenceIntegrity;

/**
 * Kategorie und Rechnungssteller einer Kostenzeile gehören zur Benutzergruppe der
 * Verwaltungsgruppe, auf die sie gebucht ist (siehe GroupOwnership), ihr
 * Abrechnungsintervall zu dieser Verwaltungsgruppe (ReferenceIntegrity). Geprüft wird
 * nur, wenn sich Kategorie, Zuordnung, Rechnung oder Abrechnungsintervall ändern.
 */
class CostObserver
{
    /** Formularfeld, an dem ein unpassender Rechnungssteller gemeldet wird. */
    public const FIELD_COSTABLE = 'costable_id';

    public function saving(Cost $cost): void
    {
        if (GroupOwnership::isSuspended() || ! $cost->isDirty(['cost_category_id', 'costable_type', 'costable_id', 'invoice_id', 'billing_interval_id'])) {
            return;
        }

        $managementGroup = GroupOwnership::managementGroupOf($cost->costable_type, $cost->costable_id);

        if ($managementGroup === null) {
            return;
        }

        ReferenceIntegrity::assertIntervalBelongsTo($cost->billing_interval_id, $managementGroup);

        $category = CostCategory::find($cost->cost_category_id);
        if ($category !== null) {
            GroupOwnership::assertCategoryFits($category, $managementGroup);
        }

        $issuer = $cost->invoice_id === null ? null : Invoice::find($cost->invoice_id)?->invoiceIssuer;
        if ($issuer !== null) {
            GroupOwnership::assertIssuerFits($issuer, $managementGroup, self::FIELD_COSTABLE);
        }
    }
}
