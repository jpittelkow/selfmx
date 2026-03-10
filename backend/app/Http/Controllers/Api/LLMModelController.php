<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Services\LLMModelDiscoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LLMModelController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private LLMModelDiscoveryService $discovery
    ) {}

    /**
     * Validate API key (or Ollama host) for a provider.
     * POST /llm-settings/test-key
     */
    public function testKey(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', 'in:openai,claude,gemini,ollama,azure,bedrock'],
            'api_key' => ['nullable', 'string'],
            'host' => ['nullable', 'string'],
            'endpoint' => ['nullable', 'string'],
            'region' => ['nullable', 'string'],
            'access_key' => ['nullable', 'string'],
            'secret_key' => ['nullable', 'string'],
            'provider_id' => ['nullable', 'integer'],
        ]);

        $credentials = $this->credentialsFromRequest($validated);
        $credentials = $this->mergeStoredCredentials($request, $validated, $credentials);

        try {
            $valid = $this->discovery->validateCredentials($validated['provider'], $credentials);
            return $this->dataResponse([
                'valid' => $valid,
                'error' => $valid ? null : 'No models returned. Check your API key or host.',
            ]);
        } catch (\Throwable $e) {
            return $this->dataResponse([
                'valid' => false,
                'error' => $this->sanitizeErrorMessage($e->getMessage()),
            ]);
        }
    }

    /**
     * Discover available models for a provider using the given credentials.
     * POST /llm-settings/discover-models
     */
    public function discover(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', 'in:openai,claude,gemini,ollama,azure,bedrock'],
            'api_key' => ['nullable', 'string'],
            'host' => ['nullable', 'string'],
            'endpoint' => ['nullable', 'string'],
            'region' => ['nullable', 'string'],
            'access_key' => ['nullable', 'string'],
            'secret_key' => ['nullable', 'string'],
            'provider_id' => ['nullable', 'integer'],
        ]);

        $credentials = $this->credentialsFromRequest($validated);
        $credentials = $this->mergeStoredCredentials($request, $validated, $credentials);

        try {
            $models = $this->discovery->discoverModels($validated['provider'], $credentials);
            return $this->dataResponse([
                'models' => $models,
                'provider' => $validated['provider'],
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->dataResponse([
                'error' => 'Invalid request',
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Throwable $e) {
            return $this->dataResponse([
                'error' => 'Failed to fetch models',
                'message' => $this->sanitizeErrorMessage($e->getMessage()),
            ], 400);
        }
    }

    /**
     * @param array{provider: string, api_key?: string, host?: string, endpoint?: string, region?: string, access_key?: string, secret_key?: string} $validated
     * @return array{api_key?: string, host?: string, endpoint?: string, region?: string, access_key?: string, secret_key?: string}
     */
    private function credentialsFromRequest(array $validated): array
    {
        $credentials = [];
        if (isset($validated['api_key']) && $validated['api_key'] !== null && $validated['api_key'] !== '') {
            $credentials['api_key'] = $validated['api_key'];
        }
        if (isset($validated['host']) && $validated['host'] !== null && $validated['host'] !== '') {
            $credentials['host'] = $validated['host'];
        }
        if (isset($validated['endpoint']) && $validated['endpoint'] !== null && $validated['endpoint'] !== '') {
            $credentials['endpoint'] = $validated['endpoint'];
        }
        if (isset($validated['region']) && $validated['region'] !== null && $validated['region'] !== '') {
            $credentials['region'] = $validated['region'];
        }
        if (isset($validated['access_key']) && $validated['access_key'] !== null && $validated['access_key'] !== '') {
            $credentials['access_key'] = $validated['access_key'];
        }
        if (isset($validated['secret_key']) && $validated['secret_key'] !== null && $validated['secret_key'] !== '') {
            $credentials['secret_key'] = $validated['secret_key'];
        }
        if ($validated['provider'] === 'ollama' && empty($credentials['host'])) {
            $credentials['host'] = 'http://localhost:11434';
        }
        return $credentials;
    }

    /**
     * If credentials are missing and a provider_id is given, fall back to
     * the stored (encrypted) credentials from the user's existing AI provider.
     */
    private function mergeStoredCredentials(Request $request, array $validated, array $credentials): array
    {
        $providerId = $validated['provider_id'] ?? null;
        if (!$providerId) {
            return $credentials;
        }

        $stored = $request->user()->aiProviders()->find($providerId);
        if (!$stored) {
            return $credentials;
        }

        $settings = $stored->settings ?? [];

        // Fall back to stored api_key if none provided in the request
        if (empty($credentials['api_key']) && !empty($stored->api_key)) {
            $credentials['api_key'] = $stored->api_key;
        }

        // Fall back to stored host (Ollama)
        if (empty($credentials['host']) && !empty($settings['base_url'])) {
            $credentials['host'] = $settings['base_url'];
        }

        // Fall back to stored endpoint (Azure)
        if (empty($credentials['endpoint']) && !empty($settings['endpoint'])) {
            $credentials['endpoint'] = $settings['endpoint'];
        }

        // Fall back to stored region (Bedrock)
        if (empty($credentials['region']) && !empty($settings['region'])) {
            $credentials['region'] = $settings['region'];
        }

        // Fall back to stored access_key (Bedrock)
        if (empty($credentials['access_key']) && (!empty($settings['access_key']) || !empty($settings['access_key_id']))) {
            $credentials['access_key'] = $settings['access_key'] ?? $settings['access_key_id'];
        }

        // Fall back to stored secret_key (Bedrock)
        if (empty($credentials['secret_key']) && (!empty($settings['secret_key']) || !empty($settings['secret_access_key']))) {
            $credentials['secret_key'] = $settings['secret_key'] ?? $settings['secret_access_key'];
        }

        return $credentials;
    }

    private function sanitizeErrorMessage(string $message): string
    {
        if (str_contains($message, 'api.openai.com')) {
            return 'OpenAI API error. Check your API key.';
        }
        if (str_contains($message, 'api.anthropic.com')) {
            return 'Anthropic API error. Check your API key.';
        }
        if (str_contains($message, 'generativelanguage.googleapis.com')) {
            return 'Gemini API error. Check your API key.';
        }
        if (str_contains($message, 'Azure OpenAI')) {
            return 'Azure OpenAI error. Check endpoint and API key.';
        }
        if (str_contains($message, 'Bedrock') || str_contains($message, 'bedrock')) {
            return 'AWS Bedrock error. Check region and IAM credentials.';
        }
        if (str_contains($message, 'Connection') || str_contains($message, 'timed out')) {
            return 'Connection failed. Check host (e.g. http://localhost:11434 for Ollama).';
        }
        return strlen($message) > 200 ? substr($message, 0, 197) . '...' : $message;
    }
}
