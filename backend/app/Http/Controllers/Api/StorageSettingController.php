<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorageSettingController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private StorageService $storageService,
    ) {}

    /**
     * Get storage settings.
     */
    public function show(): JsonResponse
    {
        $settings = SystemSetting::getGroup('storage');

        return $this->dataResponse([
            'settings' => $settings,
        ]);
    }

    /**
     * Get storage location paths and descriptions.
     */
    public function paths(): JsonResponse
    {
        return $this->dataResponse([
            'paths' => [
                ['key' => 'app', 'path' => storage_path('app'), 'description' => 'Application files'],
                ['key' => 'public', 'path' => storage_path('app/public'), 'description' => 'Publicly accessible files'],
                ['key' => 'backups', 'path' => storage_path('app/backups'), 'description' => 'Database and file backups'],
                ['key' => 'cache', 'path' => storage_path('framework/cache'), 'description' => 'Framework cache'],
                ['key' => 'sessions', 'path' => storage_path('framework/sessions'), 'description' => 'Session files'],
                ['key' => 'logs', 'path' => storage_path('logs'), 'description' => 'Application logs'],
            ],
        ]);
    }

    /**
     * Get storage health status (permissions, disk space).
     */
    public function health(): JsonResponse
    {
        try {
            return $this->dataResponse($this->storageService->getHealth());
        } catch (\Throwable $e) {
            return $this->errorResponse('Unable to get storage health: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Test storage connection for the given driver and config.
     */
    public function test(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'driver' => ['required', 'string', 'in:local,s3,gcs,azure,do_spaces,minio,b2'],
        ]);

        $driver = $validated['driver'];
        $config = $request->except(['driver']);
        $result = $this->storageService->testConnection($driver, $config);

        if ($result['success']) {
            return $this->dataResponse(['success' => true]);
        }

        return $this->dataResponse([
            'success' => false,
            'error' => $result['error'] ?? 'Connection test failed',
        ], 422);
    }

    /**
     * Update storage settings.
     */
    public function update(Request $request): JsonResponse
    {
        $rules = [
            'driver' => ['required', 'string', 'in:local,s3,gcs,azure,do_spaces,minio,b2'],
            'max_upload_size' => ['required', 'integer', 'min:1'],
            'allowed_file_types' => ['required', 'array'],
            'storage_alert_enabled' => ['sometimes', 'boolean'],
            'storage_alert_threshold' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'storage_alert_critical' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'storage_alert_email' => ['sometimes', 'boolean'],
            's3_bucket' => ['required_if:driver,s3', 'nullable', 'string'],
            's3_region' => ['required_if:driver,s3', 'nullable', 'string'],
            's3_key' => ['required_if:driver,s3', 'nullable', 'string'],
            's3_secret' => ['required_if:driver,s3', 'nullable', 'string'],
            'gcs_bucket' => ['required_if:driver,gcs', 'nullable', 'string'],
            'gcs_project_id' => ['required_if:driver,gcs', 'nullable', 'string'],
            'gcs_credentials_json' => ['required_if:driver,gcs', 'nullable', 'string'],
            'azure_container' => ['required_if:driver,azure', 'nullable', 'string'],
            'azure_connection_string' => ['required_if:driver,azure', 'nullable', 'string'],
            'do_spaces_bucket' => ['required_if:driver,do_spaces', 'nullable', 'string'],
            'do_spaces_region' => ['required_if:driver,do_spaces', 'nullable', 'string'],
            'do_spaces_key' => ['required_if:driver,do_spaces', 'nullable', 'string'],
            'do_spaces_secret' => ['required_if:driver,do_spaces', 'nullable', 'string'],
            'do_spaces_endpoint' => ['nullable', 'string'],
            'minio_bucket' => ['required_if:driver,minio', 'nullable', 'string'],
            'minio_endpoint' => ['required_if:driver,minio', 'nullable', 'string'],
            'minio_key' => ['required_if:driver,minio', 'nullable', 'string'],
            'minio_secret' => ['required_if:driver,minio', 'nullable', 'string'],
            'b2_bucket' => ['required_if:driver,b2', 'nullable', 'string'],
            'b2_region' => ['required_if:driver,b2', 'nullable', 'string'],
            'b2_key_id' => ['required_if:driver,b2', 'nullable', 'string'],
            'b2_application_key' => ['required_if:driver,b2', 'nullable', 'string'],
        ];

        $validated = $request->validate($rules);

        $user = $request->user();

        foreach ($validated as $key => $value) {
            SystemSetting::set($key, $value, 'storage', $user->id, false);
        }

        return $this->successResponse('Storage settings updated successfully');
    }

    /**
     * Get cleanup suggestions (local driver only).
     */
    public function cleanupSuggestions(): JsonResponse
    {
        try {
            return $this->dataResponse($this->storageService->getCleanupSuggestions());
        } catch (\Throwable $e) {
            return $this->errorResponse('Unable to get cleanup suggestions: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Execute cleanup for a given type.
     */
    public function cleanup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:cache,temp,old_backups'],
        ]);

        try {
            $result = $this->storageService->executeCleanup($validated['type']);

            app(AuditService::class)->log('storage.cleanup', null, [], [
                'type' => $validated['type'],
                'files_removed' => $result['files_removed'],
                'bytes_freed' => $result['bytes_freed'],
            ]);

            return $this->successResponse('Cleanup completed.', [
                'files_removed' => $result['files_removed'],
                'bytes_freed' => $result['bytes_freed'],
                'bytes_freed_formatted' => $this->formatBytesForResponse($result['bytes_freed']),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->errorResponse('Cleanup failed.', 500);
        }
    }

    /**
     * Get storage analytics (by type, top files, recent files).
     */
    public function analytics(): JsonResponse
    {
        try {
            return $this->dataResponse($this->storageService->getAnalytics());
        } catch (\Throwable $e) {
            return $this->errorResponse('Unable to retrieve storage analytics: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get storage usage statistics.
     */
    public function stats(): JsonResponse
    {
        try {
            return $this->dataResponse($this->storageService->getStats());
        } catch (\Exception $e) {
            return $this->errorResponse('Unable to retrieve storage statistics: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Simple byte formatting for controller-level response decoration.
     */
    private function formatBytesForResponse(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
