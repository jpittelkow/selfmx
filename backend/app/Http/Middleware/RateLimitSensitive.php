<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class RateLimitSensitive
{
    /**
     * Handle an incoming request.
     * 
     * Apply strict rate limiting to sensitive endpoints like login,
     * password reset, and 2FA verification to prevent brute force attacks.
     */
    public function handle(Request $request, Closure $next, string $limitKey = 'sensitive'): Response
    {
        $key = $this->resolveRateLimitKey($request, $limitKey);
        $maxAttempts = $this->getMaxAttempts($limitKey);
        $decaySeconds = $this->getDecaySeconds($limitKey);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($key);

            return response()->json([
                'message' => 'Too many attempts. Please try again later.',
                'retry_after' => $retryAfter,
            ], 429)->withHeaders([
                'Retry-After' => $retryAfter,
                'X-RateLimit-Limit' => $maxAttempts,
                'X-RateLimit-Remaining' => 0,
            ]);
        }

        $response = $next($request);

        // Only count failed attempts (4xx/5xx) toward the rate limit.
        // Never clear the counter on success — an attacker with one valid
        // credential could otherwise reset the counter between brute-force runs.
        if ($response->getStatusCode() >= 400) {
            RateLimiter::hit($key, $decaySeconds);
        }

        // Add rate limit headers to response
        return $response->withHeaders([
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => RateLimiter::remaining($key, $maxAttempts),
        ]);
    }

    /**
     * Resolve the rate limit key based on the request.
     */
    private function resolveRateLimitKey(Request $request, string $limitKey): string
    {
        // Use IP address combined with the limit key type
        $ip = $request->ip();
        
        // For login attempts, also include the email to prevent enumeration
        if ($limitKey === 'login' && $request->has('email')) {
            return "rate_limit:{$limitKey}:{$ip}:" . strtolower($request->input('email'));
        }

        // For 2FA, include session ID if available
        if ($limitKey === '2fa' && $request->session()->has('2fa:user_id')) {
            return "rate_limit:{$limitKey}:{$ip}:" . $request->session()->get('2fa:user_id');
        }

        return "rate_limit:{$limitKey}:{$ip}";
    }

    /**
     * Get maximum attempts allowed for the rate limit key.
     */
    private function getMaxAttempts(string $limitKey): int
    {
        return match ($limitKey) {
            'login' => 5,           // 5 login attempts
            '2fa' => 5,             // 5 2FA verification attempts
            'password_reset' => 3,  // 3 password reset requests
            'register' => 3,        // 3 registration attempts
            default => 10,          // Default for other sensitive operations
        };
    }

    /**
     * Get decay time in seconds for the rate limit key.
     */
    private function getDecaySeconds(string $limitKey): int
    {
        return match ($limitKey) {
            'login' => 300,          // 5 minutes lockout
            '2fa' => 300,            // 5 minutes lockout
            'password_reset' => 3600, // 1 hour lockout
            'register' => 3600,      // 1 hour lockout
            default => 60,           // 1 minute default
        };
    }
}
