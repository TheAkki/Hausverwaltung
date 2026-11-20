<?php

namespace App\Observers;

use App\Models\InvoiceIssuer;
use App\Services\GroupOwnership\GroupOwnership;

/**
 * Ein Rechnungssteller darf nicht in eine andere Benutzergruppe wechseln, solange
 * Rechnungen einer Verwaltungsgruppe der bisherigen Gruppe ihn verwenden (siehe
 * GroupOwnership).
 */
class InvoiceIssuerObserver
{
    public function updating(InvoiceIssuer $issuer): void
    {
        if (GroupOwnership::isSuspended() || ! $issuer->isDirty('user_group_id')) {
            return;
        }

        foreach ($issuer->invoices()->with('costs')->get() as $invoice) {
            foreach (GroupOwnership::managementGroupsOf($invoice) as $managementGroup) {
                GroupOwnership::assertIssuerFits($issuer, $managementGroup, GroupOwnership::FIELD_GROUP);
            }
        }
    }
}
