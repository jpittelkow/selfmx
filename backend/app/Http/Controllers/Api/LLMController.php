<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLLMProviderRequest;
use App\Http\Requests\UpdateLLMConfigRequest;
use App\Http\Traits\ApiResponseTrait;
use App\Services\LLM\LLMOrchestrator;
use App\Services\UrlValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LLMController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private LLMOrchestrator $orchestrator,
        private UrlValidationService $urlValidator
    ) {}

    /**
     * Get available LLM providers.
     */
    public function providers(): JsonResponse
    {
        $providers = collect(config('llm.providers'))
            ->map(fn ($config, $key) => [
                'id' => $key,
                'name' => $config['name'],
                'enabled' => $config['enabled'],
                'supports_vision' => $config['supports_vision'] ?? false,
                'supports_tools' => $config['supports_tools'] ?? false,
            ]);

        return $this->dataResponse([
            'providers' => $providers,
            'current_mode' => config('llm.mode'),
            'primary' => config('llm.primary'),
        ]);
    }

    /**
     * Get user's LLM configuration.
     */
    public function config(Request $request): JsonResponse
    {
        $user = $request->user();

        $providers = $user->aiProviders()->get()->map(fn ($p) => $this->formatProvider($p));

        $mode = $user->getSetting('defaults', 'llm_mode', config('llm.mode'));

        return $this->dataResponse([
            'mode' => $mode,
            'providers' => $providers,
        ]);
    }

    /**
     * Update user's LLM configuration.
     */
    public function updateConfig(UpdateLLMConfigRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $this->orchestrator->updateUserConfig(
            $request->user(),
            $validated['mode'] ?? null,
            $validated['providers'] ?? null
        );

        return $this->successResponse('LLM configuration updated');
    }

    /**
     * Add a new LLM provider.
     */
    public function storeProvider(StoreLLMProviderRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = $request->user();

        // Check if provider already exists for this user
        $existing = $user->aiProviders()->where('provider', $validated['provider'])->first();
        if ($existing) {
            return $this->errorResponse('Provider already exists', 400);
        }

        $settings = $this->buildProviderSettings($validated, $validated['provider']);

        $provider = $user->aiProviders()->create([
            'provider' => $validated['provider'],
            'model' => $validated['model'],
            'api_key' => $validated['api_key'] ?? null,
            'is_enabled' => true,
            'is_primary' => $user->aiProviders()->count() === 0,
            'settings' => !empty($settings) ? $settings : null,
        ]);

        return $this->createdResponse('Provider added', [
            'provider' => $this->formatProvider($provider),
        ]);
    }

    /**
     * Update an LLM provider.
     */
    public function updateProvider(Request $request, int $provider): JsonResponse
    {
        $user = $request->user();
        $providerModel = $user->aiProviders()->findOrFail($provider);

        $validated = $request->validate([
            'model' => ['sometimes', 'string'],
            'api_key' => ['sometimes', 'nullable', 'string'],
            'base_url' => ['sometimes', 'nullable', 'string'],
            'endpoint' => ['sometimes', 'nullable', 'string'],
            'region' => ['sometimes', 'nullable', 'string'],
            'access_key' => ['sometimes', 'nullable', 'string'],
            'secret_key' => ['sometimes', 'nullable', 'string'],
            'is_enabled' => ['sometimes', 'boolean'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);

        // Handle primary flag (ensure only one primary)
        if (isset($validated['is_primary']) && $validated['is_primary']) {
            $user->aiProviders()
                ->where('id', '!=', $provider)
                ->update(['is_primary' => false]);
        }

        // Merge provider-specific fields into the settings JSON column
        $settingsFields = ['base_url', 'endpoint', 'region', 'access_key', 'secret_key'];
        $hasSettingsUpdate = collect($settingsFields)->contains(fn ($f) => isset($validated[$f]));

        if ($hasSettingsUpdate || (isset($validated['model']) && $providerModel->provider === 'azure')) {
            $settings = $this->buildProviderSettings($validated, $providerModel->provider, $providerModel->settings ?? []);
            $validated['settings'] = !empty($settings) ? $settings : null;

            // Remove settings fields from $validated so they don't get passed to update()
            foreach ($settingsFields as $field) {
                unset($validated[$field]);
            }
        }

        $providerModel->update($validated);

        return $this->successResponse('Provider updated', [
            'provider' => $this->formatProvider($providerModel->fresh()),
        ]);
    }

    /**
     * Delete an LLM provider.
     */
    public function destroyProvider(Request $request, int $provider): JsonResponse
    {
        $user = $request->user();
        $providerModel = $user->aiProviders()->findOrFail($provider);
        $wasPrimary = $providerModel->is_primary;

        $providerModel->delete();

        // If deleted provider was primary, assign primary to first remaining
        if ($wasPrimary) {
            $user->aiProviders()->first()?->update(['is_primary' => true]);
        }

        return $this->deleteResponse('Provider removed');
    }

    /**
     * Build the settings JSON array from validated input.
     */
    private function buildProviderSettings(array $validated, string $providerName, array $existing = []): array
    {
        $settings = $existing;

        foreach (['base_url', 'endpoint', 'region'] as $field) {
            if (isset($validated[$field])) {
                if (!empty($validated[$field])) {
                    $settings[$field] = $validated[$field];
                } else {
                    unset($settings[$field]);
                }
            }
        }

        if (isset($validated['access_key'])) {
            if (!empty($validated['access_key'])) {
                $settings['access_key'] = $validated['access_key'];
                if ($providerName === 'bedrock') {
                    $settings['access_key_id'] = $validated['access_key'];
                }
            } else {
                unset($settings['access_key'], $settings['access_key_id']);
            }
        }

        if (isset($validated['secret_key'])) {
            if (!empty($validated['secret_key'])) {
                $settings['secret_key'] = $validated['secret_key'];
                if ($providerName === 'bedrock') {
                    $settings['secret_access_key'] = $validated['secret_key'];
                }
            } else {
                unset($settings['secret_key'], $settings['secret_access_key']);
            }
        }

        if (isset($validated['model']) && $providerName === 'azure') {
            $settings['deployment'] = $validated['model'];
        }

        return $settings;
    }

    /**
     * Format provider for API response.
     */
    private function formatProvider($provider): array
    {
        $settings = $provider->settings ?? [];

        return [
            'id' => $provider->id,
            'provider' => $provider->provider,
            'model' => $provider->model,
            'api_key_set' => !empty($provider->api_key),
            'is_enabled' => $provider->is_enabled,
            'is_primary' => $provider->is_primary,
            'last_test_at' => $provider->last_test_at?->toISOString(),
            'last_test_success' => $provider->last_test_success,
            'base_url' => $settings['base_url'] ?? null,
            'endpoint' => $settings['endpoint'] ?? null,
            'region' => $settings['region'] ?? null,
            'access_key_set' => !empty($settings['access_key']) || !empty($settings['access_key_id']),
            'secret_key_set' => !empty($settings['secret_key']) || !empty($settings['secret_access_key']),
        ];
    }

    /**
     * Test an LLM provider.
     */
    public function testProvider(Request $request, string $provider): JsonResponse
    {
        $user = $request->user();
        $providerConfig = $user->aiProviders()->where('provider', $provider)->first();

        if (!$providerConfig) {
            return $this->errorResponse('Provider not configured', 400);
        }

        try {
            $result = $this->orchestrator->testProvider($user, $provider);

            $providerConfig->update([
                'last_test_at' => now(),
                'last_test_success' => $result['success'],
            ]);

            return $this->dataResponse($result);
        } catch (\Exception $e) {
            $providerConfig->update([
                'last_test_at' => now(),
                'last_test_success' => false,
            ]);

            return $this->dataResponse([
                'success' => false,
                'message' => 'Test failed',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Execute an LLM query.
     */
    public function query(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prompt' => ['required', 'string', 'max:50000'],
            'system_prompt' => ['sometimes', 'string', 'max:10000'],
            'mode' => ['sometimes', 'in:single,aggregation,council'],
            'provider' => ['sometimes', 'string'],
        ]);

        $user = $request->user();

        try {
            $result = $this->orchestrator->query(
                user: $user,
                prompt: $validated['prompt'],
                systemPrompt: $validated['system_prompt'] ?? null,
                mode: $validated['mode'] ?? null,
                provider: $validated['provider'] ?? null,
            );

            return $this->dataResponse($result);
        } catch (\Exception $e) {
            return $this->dataResponse([
                'success' => false,
                'message' => 'Query failed',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Execute an LLM vision query (with image).
     */
    public function visionQuery(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prompt' => ['required', 'string', 'max:50000'],
            'image' => ['required_without:image_url'],
            'image_url' => ['required_without:image', 'url'],
            'system_prompt' => ['sometimes', 'string', 'max:10000'],
            'mode' => ['sometimes', 'in:single,aggregation,council'],
            'provider' => ['sometimes', 'string'],
        ]);

        try {
            [$imageData, $mimeType] = $this->orchestrator->resolveImageInput($request, $this->urlValidator);
        } catch (\InvalidArgumentException $e) {
            return $this->dataResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }

        try {
            $result = $this->orchestrator->visionQuery(
                user: $request->user(),
                prompt: $validated['prompt'],
                imageData: $imageData,
                mimeType: $mimeType ?? 'image/jpeg',
                systemPrompt: $validated['system_prompt'] ?? null,
                mode: $validated['mode'] ?? null,
                provider: $validated['provider'] ?? null,
            );

            return $this->dataResponse($result);
        } catch (\Exception $e) {
            return $this->dataResponse([
                'success' => false,
                'message' => 'Vision query failed',
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}
