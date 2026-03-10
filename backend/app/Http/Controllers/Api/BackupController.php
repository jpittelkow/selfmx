<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Models\AuditLog;
use App\Services\AuditService;
use App\Services\Backup\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupController extends Controller
{
    use ApiResponseTrait;

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

        return $this->dataResponse([
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

            return $this->createdResponse('Backup created successfully', [
                'backup' => $backup,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to create backup', 500);
        }
    }

    /**
     * Download a backup file.
     */
    public function download(string $filename): StreamedResponse|JsonResponse
    {
        if (!$this->validateFilename($filename)) {
            return $this->errorResponse('Invalid backup filename', 400);
        }

        if (!$this->backupService->exists($filename)) {
            return $this->errorResponse('Backup not found', 404);
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
                    return $this->errorResponse('Invalid backup filename', 400);
                }
                $result = $this->backupService->restoreFromFile($request->filename);
                $this->auditService->log('backup.restored', null, [], ['filename' => $request->filename], null, null, AuditLog::SEVERITY_WARNING);
            }

            return $this->successResponse('Backup restored successfully', [
                'details' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to restore backup', 500);
        }
    }

    /**
     * Upload a backup file without triggering a restore.
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'backup' => ['required', 'file', 'max:100000', 'mimes:zip'],
        ]);

        try {
            $file = $request->file('backup');
            $disk = config('backup.disk', 'backups');

            // Sanitize the original filename: keep only safe characters
            $originalName = $file->getClientOriginalName();
            $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
            $filename = $filename . '.zip';

            Storage::disk($disk)->putFileAs('', $file, $filename);

            $this->auditService->log('backup.uploaded', null, [], [
                'filename' => $filename,
                'size' => $file->getSize(),
            ]);

            return $this->successResponse('Backup uploaded successfully', [
                'filename' => $filename,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to upload backup', 500);
        }
    }

    /**
     * Delete a backup.
     */
    public function destroy(string $filename): JsonResponse
    {
        if (!$this->validateFilename($filename)) {
            return $this->errorResponse('Invalid backup filename', 400);
        }

        if (!$this->backupService->exists($filename)) {
            return $this->errorResponse('Backup not found', 404);
        }

        $this->auditService->log('backup.deleted', null, [], ['filename' => $filename]);

        $this->backupService->delete($filename);

        return $this->deleteResponse('Backup deleted successfully');
    }
}
