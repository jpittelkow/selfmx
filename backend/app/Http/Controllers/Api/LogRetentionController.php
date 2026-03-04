<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Services\AuditService;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogRetentionController extends Controller
{
    use ApiResponseTrait;

    public const GROUP = 'logging';

    public function __construct(
        private SettingService $settingService,
        private AuditService $auditService
    ) {}

    /**
     * Get log retention settings.
     */
    public function show(): JsonResponse
    {
        $settings = $this->settingService->getGroup(self::GROUP);
        $schema = config('settings-schema.logging', []);
        $defaults = [
            'app_retention_days' => $schema['app_retention_days']['default'] ?? 90,
            'audit_retention_days' => $schema['audit_retention_days']['default'] ?? 365,
        ];
        $settings = array_merge($defaults, $settings);

        return $this->dataResponse([
            'settings' => $settings,
        ]);
    }

    /**
     * Update log retention settings.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'app_retention_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'audit_retention_days' => ['sometimes', 'integer', 'min:30', 'max:730'],
        ]);

        $user = $request->user();
        $oldSettings = $this->settingService->getGroup(self::GROUP);
        foreach ($validated as $key => $value) {
            $this->settingService->set(self::GROUP, $key, $value, $user->id);
        }

        $this->auditService->logSettings('log_retention', $oldSettings, $validated, $user->id);

        return $this->successResponse('Log retention settings updated.');
    }
}
