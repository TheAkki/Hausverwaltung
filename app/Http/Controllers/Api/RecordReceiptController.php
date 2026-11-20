<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountTransfer;
use App\Models\SettlementClaim;
use App\Services\RecordReceipt;
use App\Support\FileStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * REST-API: Beleg einer Forderung bzw. Umbuchung hochladen (ersetzt einen vorhandenen),
 * herunterladen und entfernen – wie /api/invoices/{invoice}/file.
 */
class RecordReceiptController extends Controller
{
    public function __construct(private readonly RecordReceipt $receipt) {}

    public function storeClaim(Request $request, SettlementClaim $settlementClaim): JsonResponse
    {
        return $this->store($request, $settlementClaim);
    }

    public function showClaim(SettlementClaim $settlementClaim): StreamedResponse
    {
        return $this->show($settlementClaim);
    }

    public function destroyClaim(SettlementClaim $settlementClaim): JsonResponse
    {
        return $this->destroy($settlementClaim);
    }

    public function storeTransfer(Request $request, AccountTransfer $accountTransfer): JsonResponse
    {
        return $this->store($request, $accountTransfer);
    }

    public function showTransfer(AccountTransfer $accountTransfer): StreamedResponse
    {
        return $this->show($accountTransfer);
    }

    public function destroyTransfer(AccountTransfer $accountTransfer): JsonResponse
    {
        return $this->destroy($accountTransfer);
    }

    private function store(Request $request, SettlementClaim|AccountTransfer $record): JsonResponse
    {
        $this->authorize('update', $record);

        $file = $request->validate([RecordReceipt::FIELD_FILE => ['required', ...FileStorage::uploadRules()]])[RecordReceipt::FIELD_FILE];
        $this->receipt->attach($record, $file, $file->getClientOriginalName());

        return response()->json($record);
    }

    private function show(SettlementClaim|AccountTransfer $record): StreamedResponse
    {
        $this->authorize('view', $record);

        return FileStorage::response($record->file_path, $record->file_original_name);
    }

    private function destroy(SettlementClaim|AccountTransfer $record): JsonResponse
    {
        $this->authorize('update', $record);

        $this->receipt->remove($record);

        return response()->json($record);
    }
}
