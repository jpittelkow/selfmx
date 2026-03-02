<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuditService;
use App\Services\Backup\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupController extends Controller
{
    public function __construct(
        private BackupService $backupService,
        private AuditService $auditService
    ) {}

    /**
     * Validate filename to prevent path traversal attacks.
     */
    private function validateFilename(string $filename): bool
    {
        // Only allow alphanumeric, dash, underscore, and .zip extension
        // Must match our backup naming pattern: selfmx-backup-YYYY-MM-DD_HH-ii-ss.zip
        if (!preg_match('/^selfmx-backup-\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.zip$/', $filename)) {
            return false;
        }

        // Double-check for path traversal characters
        if (str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            return false;
        }

        return true;
    }

    /**
     * List available backups.
     */
    public function index(): JsonResponse
    {
        $backups = $this->backupService->listBackups();

        return response()->json([
            'backups' => $backups,
        ]);
    }

    /**
     * Create a new backup.
     */
    public function create(Request $request): JsonResponse
    {
        try {
            $backup = $this->backupService->create([
                'include_database' => $request->boolean('include_database', true),
                'include_files' => $request->boolean('include_files', true),
                'include_settings' => $request->boolean('include_settings', true),
            ]);

            $this->auditService->log('backup.created', null, [], [
                'filename' => $backup['filename'] ?? null,
                'size' => $backup['size'] ?? null,
            ]);

            return response()->json([
                'message' => 'Backup created successfully',
                'backup' => $backup,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create backup',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download a backup file.
     */
    public function download(string $filename): StreamedResponse|JsonResponse
    {
        if (!$this->validateFilename($filename)) {
            return response()->json([
                'message' => 'Invalid backup filename',
            ], 400);
        }

        if (!$this->backupService->exists($filename)) {
            return response()->json([
                'message' => 'Backup not found',
            ], 404);
        }

        $this->auditService->log('backup.downloaded', null, [], ['filename' => $filename]);

        return $this->backupService->download($filename);
    }

    /**
     * Restore from backup.
     */
    public function restore(Request $request): JsonResponse
    {
        $request->validate([
            'backup' => ['required_without:filename', 'file', 'mimes:zip'],
            'filename' => ['required_without:backup', 'string'],
        ]);

        try {
            if ($request->hasFile('backup')) {
                $result = $this->backupService->restoreFromUpload($request->file('backup'));
                $this->auditService->log('backup.restored', null, [], ['source' => 'upload'], null, null, AuditLog::SEVERITY_WARNING);
            } else {
                // Validate filename before restore
                if (!$this->validateFilename($request->filename)) {
                    return response()->json([
                        'message' => 'Invalid backup filename',
                    ], 400);
                }
                $result = $this->backupService->restoreFromFile($request->filename);
                $this->auditService->log('backup.restored', null, [], ['filename' => $request->filename], null, null, AuditLog::SEVERITY_WARNING);
            }

            return response()->json([
                'message' => 'Backup restored successfully',
                'details' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to restore backup',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a backup.
     */
    public function destroy(string $filename): JsonResponse
    {
        if (!$this->validateFilename($filename)) {
            return response()->json([
                'message' => 'Invalid backup filename',
            ], 400);
        }

        if (!$this->backupService->exists($filename)) {
            return response()->json([
                'message' => 'Backup not found',
            ], 404);
        }

        $this->auditService->log('backup.deleted', null, [], ['filename' => $filename]);

        $this->backupService->delete($filename);

        return response()->json([
            'message' => 'Backup deleted successfully',
        ]);
    }
}
