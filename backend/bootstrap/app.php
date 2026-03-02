<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Trust proxies (Cloudflare Tunnel, Nginx, Traefik, etc.)
        // Set TRUSTED_PROXIES=* to trust all proxies (safe when container is only reachable via proxy)
        // Use $_SERVER/$_ENV directly because env() is disabled after config:cache
        $trustedProxies = $_SERVER['TRUSTED_PROXIES'] ?? $_ENV['TRUSTED_PROXIES'] ?? '';
        $middleware->trustProxies(
            at: in_array(trim($trustedProxies), ['*', '**'], true)
                ? '*'
                : array_filter(array_map('trim', explode(',', $trustedProxies))),
        );

        $middleware->api(prepend: [
            \App\Http\Middleware\AddCorrelationId::class,
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->alias([
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
            '2fa' => \App\Http\Middleware\Ensure2FAVerified::class,
            '2fa.setup' => \App\Http\Middleware\Ensure2FASetupWhenRequired::class,
            'rate.sensitive' => \App\Http\Middleware\RateLimitSensitive::class,
        ]);

        // Exclude routes from CSRF verification:
        // - client-errors: rate-limited, no auth, logging only
        // - SSO routes: browser navigation routes that receive external OAuth redirects
        //   (GET requests bypass CSRF by default, but listed here for explicitness)
        $middleware->validateCsrfTokens(except: [
            'api/client-errors',
            'api/auth/sso/*',
            'api/auth/callback/*',
            'api/stripe/webhook',
            'api/graphql',  // GraphQL uses API key auth, not session cookies
        ]);

        // Enable stateful API authentication with session support.
        // Sanctum's EnsureFrontendRequestsAreStateful (prepended above) handles
        // EncryptCookies + StartSession automatically for requests from stateful
        // domains (configured in config/sanctum.php).
        //
        // NOTE: SSO browser navigation routes (redirect & callback) are in
        // routes/web.php (not api.php) because the OAuth callback comes from
        // an external provider — Sanctum won't recognize it as stateful.
        // See routes/web.php for details.
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
