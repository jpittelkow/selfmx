<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Services\AuditService;
use App\Services\Backup\BackupService;
use App\Services\Backup\Destinations\S3Destination;
use App\Services\Backup\Destinations\SFTPDestination;
use App\Services\Backup\Destinations\GoogleDriveDestination;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BackupSettingController extends Controller
{
    use ApiResponseTrait;

    private const GROUP = 'backup';

    private const DESTINATION_CLASSES = [
        's3' => S3Destination::class,
        'sftp' => SFTPDestination::class,
        'google_drive' => GoogleDriveDestination::class,
    ];

    public function __construct(
        private AuditService $auditService,
        private SettingService $settingService,
        private BackupService $backupService
    ) {}

    /**
     * Get all backup settings.
     */
    public function show(): JsonResponse
    {
        $settings = $this->settingService->getGroupMasked(self::GROUP);

        return $this->dataResponse([
            'settings' => $settings,
        ]);
    }

    /**
     * Update backup settings.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'disk' => ['sometimes', 'string', 'max:64'],
            'retention_enabled' => ['sometimes', 'boolean'],
            'retention_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'retention_count' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'min_backups' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'schedule_enabled' => ['sometimes', 'boolean'],
            'schedule_frequency' => ['sometimes', 'string', 'in:daily,weekly,monthly'],
            'schedule_time' => ['sometimes', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'schedule_day' => ['sometimes', 'integer', 'min:0', 'max:6'],
            'schedule_date' => ['sometimes', 'integer', 'min:1', 'max:31'],
            'scheduled_destinations' => ['sometimes', 'string', 'max:255'],
            's3_enabled' => ['sometimes', 'boolean'],
            's3_bucket' => ['sometimes', 'nullable', 'string', 'max:255'],
            's3_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            's3_access_key_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            's3_secret_access_key' => ['sometimes', 'nullable', 'string'],
            's3_region' => ['sometimes', 'nullable', 'string', 'max:64'],
            's3_endpoint' => ['sometimes', 'nullable', 'string', 'max:512'],
            'sftp_enabled' => ['sometimes', 'boolean'],
            'sftp_host' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sftp_port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'sftp_username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sftp_password' => ['sometimes', 'nullable', 'string'],
            'sftp_private_key' => ['sometimes', 'nullable', 'string'],
            'sftp_passphrase' => ['sometimes', 'nullable', 'string'],
            'sftp_path' => ['sometimes', 'nullable', 'string', 'max:512'],
            'gdrive_enabled' => ['sometimes', 'boolean'],
            'gdrive_client_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'gdrive_client_secret' => ['sometimes', 'nullable', 'string'],
            'gdrive_refresh_token' => ['sometimes', 'nullable', 'string'],
            'gdrive_folder_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'encryption_enabled' => ['sometimes', 'boolean'],
            'encryption_password' => ['sometimes', 'nullable', 'string'],
            'notify_success' => ['sometimes', 'boolean'],
            'notify_failure' => ['sometimes', 'boolean'],
        ]);

        $userId = $request->user()->id;
        $oldSettings = $this->settingService->getGroup(self::GROUP);
        $this->settingService->setGroup(self::GROUP, $validated, $userId);
        $this->auditService->logSettings(self::GROUP, $oldSettings, $validated, $userId);

        return $this->successResponse('Backup settings updated successfully');
    }

    /**
     * Reset a backup setting to env default.
     */
    public function reset(Request $request, string $key): JsonResponse
    {
        $schema = config('settings-schema.backup', []);
        if (!array_key_exists($key, $schema)) {
            return $this->errorResponse('Unknown setting key', 422);
        }
        $this->settingService->reset(self::GROUP, $key);

        return $this->successResponse('Setting reset to default');
    }

    /**
     * Test connectivity to a backup destination (s3, sftp, google_drive).
     */
    public function testDestination(Request $request, string $destination): JsonResponse
    {
        $destination = strtolower($destination);
        if (!array_key_exists($destination, self::DESTINATION_CLASSES)) {
            return $this->errorResponse('Unknown destination. Use: s3, sftp, google_drive', 422);
        }

        $this->backupService->applySettingsToConfig($this->settingService);

        try {
            $class = self::DESTINATION_CLASSES[$destination];
            $instance = new $class();
            $available = $instance->isAvailable();
            if ($available) {
                return $this->successResponse('Connection successful');
            }
            return $this->errorResponse('Connection failed: destination not available or not configured', 400);
        } catch (\Throwable $e) {
            return $this->errorResponse('Connection failed: ' . $e->getMessage(), 400);
        }
    }
}
