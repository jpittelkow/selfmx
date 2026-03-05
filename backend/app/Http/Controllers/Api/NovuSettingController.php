<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateNovuSettingsRequest;
use App\Http\Traits\ApiResponseTrait;
use App\Models\NotificationTemplate;
use App\Services\AuditService;
use App\Services\NovuService;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NovuSettingController extends Controller
{
    use ApiResponseTrait;

    private const GROUP = 'novu';

    public function __construct(
        private NovuService $novuService,
        private SettingService $settingService,
        private AuditService $auditService
    ) {}

    /**
     * Get Novu settings (admin). API key is masked in response.
     */
    public function show(): JsonResponse
    {
        $masked = $this->settingService->getGroupMasked(self::GROUP);
        $masked['workflow_map'] = config('novu.workflow_map', []);

        return $this->dataResponse(['settings' => $masked]);
    }

    /**
     * Update Novu settings.
     */
    public function update(UpdateNovuSettingsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $userId = $request->user()->id;
        // Normalize empty strings to null before saving
        $normalized = array_map(fn ($v) => $v === '' ? null : $v, $validated);
        $this->settingService->setGroup(self::GROUP, $normalized, $userId);

        $this->settingService->clearCache();
        \Illuminate\Support\Facades\Cache::forget('system_settings_public');

        $this->auditService->log(
            'novu_settings.updated',
            null,
            [],
            ['keys_updated' => array_keys($validated)],
            $request->user()?->id
        );

        return $this->successResponse('Novu settings updated successfully');
    }

    /**
     * Test Novu connection and validate workflow mappings.
     */
    public function test(Request $request): JsonResponse
    {
        // Re-inject latest settings so test uses just-saved values
        $settings = $this->settingService->getGroup(self::GROUP);
        (new \App\Providers\ConfigServiceProvider(app()))->boot();
        $this->novuService = new NovuService();

        $result = $this->novuService->testConnection();

        if ($result['success']) {
            return $this->dataResponse([
                'message' => 'Connection successful',
                'workflows_found' => $result['workflows_found'] ?? [],
                'warnings' => $result['warnings'] ?? [],
            ]);
        }

        return $this->errorResponse($result['error'] ?? 'Connection failed', 400);
    }

    /**
     * Get workflow map and all known notification types.
     */
    public function workflowMap(): JsonResponse
    {
        $currentMap = config('novu.workflow_map', []);
        $types = NotificationTemplate::query()
            ->select('type')
            ->distinct()
            ->pluck('type')
            ->toArray();

        return $this->dataResponse([
            'workflow_map' => $currentMap,
            'notification_types' => $types,
        ]);
    }

    /**
     * Update the workflow map.
     */
    public function updateWorkflowMap(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'workflow_map' => ['required', 'array'],
            'workflow_map.*' => ['nullable', 'string', 'max:255'],
        ]);

        $map = array_filter($validated['workflow_map'], fn ($v) => ! empty($v));

        $userId = $request->user()->id;
        $this->settingService->set(self::GROUP, 'workflow_map', json_encode($map), $userId);
        $this->settingService->clearCache();

        $this->auditService->log(
            'novu_settings.workflow_map_updated',
            null,
            [],
            ['mapped_types' => array_keys($map)],
            $request->user()?->id
        );

        return $this->successResponse('Workflow map updated');
    }

    /**
     * Reset a Novu setting to env default.
     */
    public function resetKey(Request $request, string $key): JsonResponse
    {
        $schema = config('settings-schema.'.self::GROUP, []);
        if (! array_key_exists($key, $schema)) {
            return $this->errorResponse('Unknown setting key', 422);
        }
        $this->settingService->reset(self::GROUP, $key);
        $this->settingService->clearCache();
        \Illuminate\Support\Facades\Cache::forget('system_settings_public');

        return $this->successResponse('Setting reset to default');
    }
}
