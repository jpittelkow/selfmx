<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiTokenController extends Controller
{
    /**
     * Get user's API tokens.
     */
    public function index(Request $request): JsonResponse
    {
        $tokens = $request->user()
            ->apiTokens()
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($token) {
                // Use key_prefix when available (new tokens), fall back to hash prefix (legacy)
                $token->token_preview = $token->key_prefix
                    ? $token->key_prefix . '...'
                    : substr($token->token, 0, 8) . '...';
                return $token->makeHidden(['token']);
            });

        return response()->json([
            'tokens' => $tokens,
        ]);
    }

    /**
     * Create a new API token.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['sometimes', 'array'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $token = ApiToken::generate();

        $apiToken = $request->user()->apiTokens()->create([
            'name' => $validated['name'],
            'token' => hash('sha256', $token),
            'abilities' => $validated['abilities'] ?? ['*'],
            'expires_at' => $validated['expires_at'] ?? null,
        ]);

        // Return the plain text token only once
        return response()->json([
            'message' => 'API token created successfully',
            'token' => $token, // Plain text token - only shown once
            'api_token' => $apiToken->makeHidden(['token']),
        ], 201);
    }

    /**
     * Delete an API token.
     */
    public function destroy(Request $request, ApiToken $token): JsonResponse
    {
        // Ensure the token belongs to the user
        if ($token->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 403);
        }

        $token->delete();

        return response()->json([
            'message' => 'API token deleted successfully',
        ]);
    }
}
