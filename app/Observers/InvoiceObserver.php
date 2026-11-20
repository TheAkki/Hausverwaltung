<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Services\GroupOwnership\GroupOwnership;
use App\Services\GroupOwnership\ReferenceIntegrity;

/**
 * Der Rechnungssteller einer Rechnung gehört zur Benutzergruppe der
 * Verwaltungsgruppe, der die Rechnung zugeordnet ist (siehe GroupOwnership), ihr
 * Kundenaccount zu diesem Rechnungssteller (ReferenceIntegrity). Geprüft wird nur, wenn
 * sich Rechnungssteller, Kundenaccount oder Zuordnung ändern – bestehende Rechnungen
 * bleiben sonst bearbeitbar.
 */
class InvoiceObserver
{
    public function saving(Invoice $invoice): void
    {
        if (GroupOwnership::isSuspended()) {
            return;
        }

        if ($invoice->isDirty(['invoice_issuer_id', 'invoice_issuer_account_id'])) {
            ReferenceIntegrity::assertIssuerAccountBelongsTo($invoice->invoice_issuer_account_id, $invoice->invoice_issuer_id);
        }

        if (! $invoice->isDirty(['invoice_issuer_id', 'costable_type', 'costable_id'])) {
            return;
        }

        $managementGroup = GroupOwnership::managementGroupOf($invoice->costable_type, $invoice->costable_id);
        $issuer = InvoiceIssuer::find($invoice->invoice_issuer_id);

        if ($managementGroup !== null && $issuer !== null) {
            GroupOwnership::assertIssuerFits($issuer, $managementGroup);
        }
    }
}
