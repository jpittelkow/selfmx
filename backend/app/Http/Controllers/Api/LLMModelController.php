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
            'api_key' => ['required_unless:provider,ollama,bedrock', 'nullable', 'string'],
            'host' => ['required_if:provider,ollama', 'nullable', 'string'],
            'endpoint' => ['required_if:provider,azure', 'nullable', 'string'],
            'region' => ['required_if:provider,bedrock', 'nullable', 'string'],
            'access_key' => ['required_if:provider,bedrock', 'nullable', 'string'],
            'secret_key' => ['required_if:provider,bedrock', 'nullable', 'string'],
        ]);

        $credentials = $this->credentialsFromRequest($validated);

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
            'api_key' => ['required_unless:provider,ollama,bedrock', 'nullable', 'string'],
            'host' => ['required_if:provider,ollama', 'nullable', 'string'],
            'endpoint' => ['required_if:provider,azure', 'nullable', 'string'],
            'region' => ['required_if:provider,bedrock', 'nullable', 'string'],
            'access_key' => ['required_if:provider,bedrock', 'nullable', 'string'],
            'secret_key' => ['required_if:provider,bedrock', 'nullable', 'string'],
        ]);

        $credentials = $this->credentialsFromRequest($validated);

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
