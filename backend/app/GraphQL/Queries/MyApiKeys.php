<?php

namespace App\GraphQL\Queries;

use App\Services\ApiKeyService;
use Illuminate\Support\Facades\Auth;

class MyApiKeys
{
    public function __construct(
        private ApiKeyService $apiKeyService
    ) {}

    public function __invoke($root, array $args, $context): array
    {
        $user = Auth::guard('api-key')->user();

        return $user->apiTokens()
            ->withTrashed()
            ->whereNotNull('key_prefix')
            ->where('key_prefix', 'like', 'sk_%')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($token) {
                $token->status = $this->apiKeyService->getKeyStatus($token);
                return $token;
            })
            ->toArray();
    }
}
