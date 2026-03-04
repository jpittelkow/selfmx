<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Models\ApiToken;
use App\Services\ApiKeyService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiKeyController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private ApiKeyService $apiKeyService
    ) {}

    /**
     * List the user's API keys.
     */
    public function index(Request $request): JsonResponse
    {
        $tokens = $request->user()
            ->apiTokens()
            ->withTrashed()
            ->whereNotNull('key_prefix')
            ->where('key_prefix', 'like', 'sk_%')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($token) {
                return [
                    'id' => $token->id,
                    'name' => $token->name,
                    'key_prefix' => $token->key_prefix,
                    'created_at' => $token->created_at,
                    'last_used_at' => $token->last_used_at,
                    'expires_at' => $token->expires_at,
                    'revoked_at' => $token->revoked_at,
                    'status' => $this->getTokenStatus($token),
                ];
            });

        return $this->dataResponse(['keys' => $tokens]);
    }

    /**
     * Create a new API key.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ]);

        try {
            $result = $this->apiKeyService->create(
                $request->user(),
                $validated['name'],
                isset($validated['expires_at']) ? Carbon::parse($validated['expires_at']) : null
            );
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->createdResponse('API key created successfully', [
            'key' => $result['plaintext'],
            'api_key' => [
                'id' => $result['token']->id,
                'name' => $result['token']->name,
                'key_prefix' => $result['token']->key_prefix,
                'created_at' => $result['token']->created_at,
                'expires_at' => $result['token']->expires_at,
            ],
        ]);
    }

    /**
     * Update an API key's name or expiration.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $token = $this->resolveUserToken($request, $id);

        if (!$token) {
            return $this->errorResponse('API key not found', 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ]);

        $token->update($validated);

        return $this->successResponse('API key updated successfully', [
            'api_key' => [
                'id' => $token->id,
                'name' => $token->name,
                'key_prefix' => $token->key_prefix,
                'expires_at' => $token->expires_at,
            ],
        ]);
    }

    /**
     * Revoke (soft-delete) an API key.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $token = $this->resolveUserToken($request, $id);

        if (!$token) {
            return $this->errorResponse('API key not found', 404);
        }

        $this->apiKeyService->revoke($token);

        return $this->deleteResponse('API key revoked successfully');
    }

    /**
     * Rotate an API key: create a replacement and return the new plaintext key.
     */
    public function rotate(Request $request, int $id): JsonResponse
    {
        $token = $this->resolveUserToken($request, $id);

        if (!$token) {
            return $this->errorResponse('API key not found', 404);
        }

        if ($token->isRevoked()) {
            return $this->errorResponse('Cannot rotate a revoked key', 422);
        }

        $result = $this->apiKeyService->rotate($token);

        return $this->successResponse('API key rotated successfully', [
            'key' => $result['plaintext'],
            'api_key' => [
                'id' => $result['token']->id,
                'name' => $result['token']->name,
                'key_prefix' => $result['token']->key_prefix,
                'created_at' => $result['token']->created_at,
                'expires_at' => $result['token']->expires_at,
            ],
        ]);
    }

    /**
     * Resolve a token belonging to the authenticated user.
     */
    private function resolveUserToken(Request $request, int $id): ?ApiToken
    {
        return $request->user()
            ->apiTokens()
            ->where('id', $id)
            ->whereNotNull('key_prefix')
            ->where('key_prefix', 'like', 'sk_%')
            ->first();
    }

    private function getTokenStatus(ApiToken $token): string
    {
        if ($token->revoked_at) {
            return 'revoked';
        }
        if ($token->trashed()) {
            return 'deleted';
        }
        if ($token->isExpired()) {
            return 'expired';
        }
        if ($token->expires_at && $token->expires_at->isBefore(now()->addDays(7))) {
            return 'expiring_soon';
        }
        return 'active';
    }
}
