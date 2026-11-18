<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\LeaseDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * REST-API für Dokumente eines Mietvertrags, gespiegelt von LeaseDocumentController.
 */
class LeaseDocumentController extends Controller
{
    private const DIRECTORY = 'lease-documents';

    private const DISK = 'public';

    private const FILE_RULES = ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,jpg,jpeg,png'];

    public function store(Request $request, Lease $lease): JsonResponse
    {
        $this->authorize('update', $lease);

        $file = $request->validate(['file' => self::FILE_RULES])['file'];

        $document = $lease->documents()->create([
            'file_path' => $file->store(self::DIRECTORY, self::DISK),
            'original_name' => $file->getClientOriginalName(),
        ]);

        return response()->json($document, 201);
    }

    public function destroy(LeaseDocument $leaseDocument): JsonResponse
    {
        $this->authorize('update', $leaseDocument->lease);

        Storage::disk(self::DISK)->delete($leaseDocument->file_path);
        $leaseDocument->delete();

        return response()->json(status: 204);
    }
}
