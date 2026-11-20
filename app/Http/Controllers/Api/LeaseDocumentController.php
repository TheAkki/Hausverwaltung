<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\LeaseDocument;
use App\Support\FileStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REST-API für Dokumente eines Mietvertrags, gespiegelt von LeaseDocumentController.
 */
class LeaseDocumentController extends Controller
{
    public function store(Request $request, Lease $lease): JsonResponse
    {
        $this->authorize('update', $lease);

        $file = $request->validate(['file' => ['required', ...FileStorage::uploadRules()]])['file'];

        $document = $lease->documents()->create([
            'file_path' => FileStorage::store($file, FileStorage::LEASE_DOCUMENTS),
            'original_name' => $file->getClientOriginalName(),
        ]);

        return response()->json($document, 201);
    }

    public function destroy(LeaseDocument $leaseDocument): JsonResponse
    {
        $this->authorize('update', $leaseDocument->lease);

        FileStorage::delete($leaseDocument->file_path);
        $leaseDocument->delete();

        return response()->json(status: 204);
    }
}
