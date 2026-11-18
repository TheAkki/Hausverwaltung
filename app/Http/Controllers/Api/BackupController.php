<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * REST-API für Backup und Restore, gespiegelt von BackupController: eigenes
 * Backup als ZIP, Gesamt-Backup (nur Administrator) und Import eines Backup-ZIPs.
 */
class BackupController extends Controller
{
    private const DOWNLOAD_NAME = 'hausverwaltung-backup-%s.zip';

    private const FULL_DOWNLOAD_NAME = 'hausverwaltung-gesamtbackup-%s.zip';

    private const DATE_FORMAT = 'Y-m-d';

    public function __construct(private readonly BackupService $backupService) {}

    public function export(Request $request): BinaryFileResponse
    {
        return $this->download($this->backupService->export($request->user()), self::DOWNLOAD_NAME);
    }

    public function exportFull(Request $request): BinaryFileResponse
    {
        abort_unless($request->user()->isAdministrator(), 403);

        return $this->download($this->backupService->exportFull($request->user()), self::FULL_DOWNLOAD_NAME);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'backup' => ['required', 'file', 'mimes:zip'],
        ]);

        try {
            $summary = $this->backupService->import($request->user(), $request->file('backup')->getRealPath());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['backup' => [$e->getMessage()]]], 422);
        }

        return response()->json(['message' => $summary]);
    }

    private function download(string $path, string $nameFormat): BinaryFileResponse
    {
        return response()
            ->download($path, sprintf($nameFormat, now()->format(self::DATE_FORMAT)))
            ->deleteFileAfterSend();
    }
}
