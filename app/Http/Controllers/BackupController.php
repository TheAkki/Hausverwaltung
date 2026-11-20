<?php

namespace App\Http\Controllers;

use App\Services\BackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Implements the Backup-Konzept endpoints: per-user export/import of their own
 * data as a ZIP, plus the Administrator-only full export/import of ALL settings.
 */
class BackupController extends Controller
{
    public function __construct(private readonly BackupService $backupService) {}

    /**
     * Downloads the authenticated user's own backup ZIP.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $path = $this->backupService->export($request->user());

        return response()
            ->download($path, 'hausverwaltung-backup-'.now()->format('Y-m-d').'.zip')
            ->deleteFileAfterSend();
    }

    /**
     * Downloads the full backup of ALL settings (Administrator only).
     */
    public function exportFull(Request $request): BinaryFileResponse
    {
        abort_unless($request->user()->isAdministrator(), 403);

        $path = $this->backupService->exportFull($request->user());

        return response()
            ->download($path, 'hausverwaltung-gesamtbackup-'.now()->format('Y-m-d').'.zip')
            ->deleteFileAfterSend();
    }

    /**
     * Imports an uploaded backup ZIP. Full backups are rejected by the service
     * unless the importing user is the Administrator.
     */
    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'backup' => ['required', 'file', 'mimes:zip'],
        ]);

        try {
            $summary = $this->backupService->import($request->user(), $request->file('backup')->getRealPath());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['backup' => $e->getMessage()]);
        }

        return back()->with('status', __('Backup importiert: :summary', ['summary' => $summary]));
    }
}
