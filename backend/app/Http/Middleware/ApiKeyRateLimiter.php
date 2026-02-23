<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Services\SettingService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyRateLimiter
{
    public function __construct(
        private SettingService $settingService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Read the token set by ApiKeyGuard during authentication
        $token = $request->attributes->get('api_token');

        if (!$token instanceof ApiToken) {
            return $next($request);
        }

        $maxAttempts = $token->rate_limit
            ?? (int) $this->settingService->get('graphql', 'default_rate_limit', 60);

        $key = 'api_key:' . $token->id;

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($key);

            return response()->json([
                'message' => 'Rate limit exceeded',
                'retry_after' => $retryAfter,
            ], 429)->withHeaders([
                'Retry-After' => $retryAfter,
                'X-RateLimit-Limit' => $maxAttempts,
                'X-RateLimit-Remaining' => 0,
            ]);
        }

        RateLimiter::hit($key, 60);

        $response = $next($request);

        return $response->withHeaders([
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => RateLimiter::remaining($key, $maxAttempts),
        ]);
    }
}
