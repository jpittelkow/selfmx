<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RFC 8594 route deprecation middleware.
 *
 * Adds Deprecation and (optionally) Sunset headers to responses for
 * routes that are scheduled for removal.
 *
 * Usage in routes:
 *   ->middleware('deprecate')                       // Deprecation: true
 *   ->middleware('deprecate:2026-06-01')             // Deprecation: true + Sunset: Sat, 01 Jun 2026 00:00:00 GMT
 *   ->middleware('deprecate:2026-06-01,/api/v2/foo') // ... + Link: </api/v2/foo>; rel="successor-version"
 */
class DeprecateRoute
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ?string $sunsetDate = null, ?string $successor = null): Response
    {
        $response = $next($request);

        // RFC 8594 — mark the endpoint as deprecated
        $response->headers->set('Deprecation', 'true');

        // Optional Sunset header (HTTP date format per RFC 7231)
        if ($sunsetDate) {
            try {
                $date = new \DateTimeImmutable($sunsetDate);
                $response->headers->set('Sunset', $date->format(\DateTimeInterface::RFC7231));
            } catch (\Exception) {
                // Invalid date — skip the Sunset header silently
            }
        }

        // Optional successor link
        if ($successor) {
            $response->headers->set('Link', "<{$successor}>; rel=\"successor-version\"");
        }

        return $response;
    }
}
