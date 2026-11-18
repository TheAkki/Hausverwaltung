<?php

namespace App\Http\Controllers;

use App\Models\Lease;
use App\Models\LeaseDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Handles uploading and removing LeaseDocuments (e.g. the signed contract PDF)
 * attached to a Lease.
 */
class LeaseDocumentController extends Controller
{
    /**
     * Store a newly uploaded document for the given lease.
     */
    public function store(Request $request, Lease $lease): RedirectResponse
    {
        $this->authorize('update', $lease);

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,jpg,jpeg,png'],
        ]);

        $path = $validated['file']->store('lease-documents', 'public');

        $lease->documents()->create([
            'file_path' => $path,
            'original_name' => $validated['file']->getClientOriginalName(),
        ]);

        return redirect()->route('leases.show', $lease);
    }

    /**
     * Remove the specified document.
     */
    public function destroy(LeaseDocument $leaseDocument): RedirectResponse
    {
        $this->authorize('update', $leaseDocument->lease);

        Storage::disk('public')->delete($leaseDocument->file_path);

        $lease = $leaseDocument->lease;
        $leaseDocument->delete();

        return redirect()->route('leases.show', $lease);
    }
}
