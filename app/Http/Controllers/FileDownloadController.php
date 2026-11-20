<?php

namespace App\Http\Controllers;

use App\Models\AccountTransfer;
use App\Models\BillingTemplate;
use App\Models\Invoice;
use App\Models\LeaseDocument;
use App\Models\SettlementClaim;
use App\Support\FileStorage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Liefert hochgeladene Dateien aus – nur an Benutzer, die den zugehörigen Datensatz
 * sehen dürfen. Die Dateien selbst liegen auf einer nicht öffentlichen Disk
 * (FileStorage); es gibt keinen Weg an dieser Prüfung vorbei.
 */
class FileDownloadController extends Controller
{
    /** Query-Parameter: Datei im Browser anzeigen statt herunterladen. */
    public const INLINE = 'inline';

    public function invoice(Request $request, Invoice $invoice): StreamedResponse
    {
        $this->authorize('view', $invoice);

        return FileStorage::response($invoice->file_path, $invoice->file_original_name, $request->boolean(self::INLINE));
    }

    public function leaseDocument(Request $request, LeaseDocument $leaseDocument): StreamedResponse
    {
        $this->authorize('view', $leaseDocument->lease);

        return FileStorage::response($leaseDocument->file_path, $leaseDocument->original_name, $request->boolean(self::INLINE));
    }

    public function billingTemplateImage(BillingTemplate $billingTemplate): StreamedResponse
    {
        $this->authorize('view', $billingTemplate);

        return FileStorage::response($billingTemplate->header_image_path, basename((string) $billingTemplate->header_image_path), inline: true);
    }

    public function settlementClaim(Request $request, SettlementClaim $settlementClaim): StreamedResponse
    {
        $this->authorize('view', $settlementClaim);

        return FileStorage::response($settlementClaim->file_path, $settlementClaim->file_original_name, $request->boolean(self::INLINE));
    }

    public function accountTransfer(Request $request, AccountTransfer $accountTransfer): StreamedResponse
    {
        $this->authorize('view', $accountTransfer);

        return FileStorage::response($accountTransfer->file_path, $accountTransfer->file_original_name, $request->boolean(self::INLINE));
    }
}
