<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Services\UserSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserSettingController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly UserSettingService $settingService
    ) {}

    /**
     * Get user personal preferences.
     */
    public function show(Request $request): JsonResponse
    {
        return $this->dataResponse(
            $this->settingService->getPreferences($request->user())
        );
    }

    /**
     * Update user personal preferences.
     */
    public function update(Request $request): JsonResponse
    {
        $validTimezones = \DateTimeZone::listIdentifiers();

        $validated = $request->validate([
            'theme' => ['sometimes', 'nullable', 'string', 'in:light,dark,system'],
            'color_theme' => ['sometimes', 'nullable', 'string', 'max:50'],
            'default_llm_mode' => ['sometimes', 'nullable', 'string', 'in:single,aggregation,council'],
            'notification_channels' => ['sometimes', 'nullable', 'array'],
            'timezone' => ['sometimes', 'nullable', 'string', 'in:' . implode(',', $validTimezones)],
        ]);

        $user = $request->user();
        $this->settingService->applyPreferences($user, $validated);

        return $this->successResponse('Preferences updated successfully', [
            'preferences' => $this->settingService->getPreferences($user),
        ]);
    }

    /**
     * Auto-detect timezone from browser.
     *
     * Only sets the timezone if the user hasn't explicitly chosen one,
     * to avoid overwriting manual choices on every login.
     */
    public function detectTimezone(Request $request): JsonResponse
    {
        $validTimezones = \DateTimeZone::listIdentifiers();

        $validated = $request->validate([
            'timezone' => ['required', 'string', 'in:' . implode(',', $validTimezones)],
        ]);

        $user = $request->user();
        $current = $user->getSetting('general', 'timezone');

        if ($current === null) {
            $user->setSetting('general', 'timezone', $validated['timezone']);
        }

        return $this->dataResponse([
            'timezone' => $user->getSetting('general', 'timezone') ?? $validated['timezone'],
            'effective_timezone' => $user->getTimezone(),
            'was_set' => $current === null,
        ]);
    }
}
