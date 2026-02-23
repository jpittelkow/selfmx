<?php

namespace App\GraphQL\ErrorHandlers;

use GraphQL\Error\Error;
use Nuwave\Lighthouse\Execution\ErrorHandler;

class GraphQLErrorHandler implements ErrorHandler
{
    public function __invoke(?Error $error, \Closure $next): ?array
    {
        if ($error === null) {
            return $next(null);
        }

        $previous = $error->getPrevious();
        $extensions = $error->getExtensions() ?? [];

        if (!isset($extensions['code'])) {
            if ($previous instanceof \Illuminate\Auth\AuthenticationException) {
                $extensions['code'] = 'UNAUTHENTICATED';
            } elseif ($previous instanceof \Illuminate\Auth\Access\AuthorizationException) {
                $extensions['code'] = 'FORBIDDEN';
            } elseif ($previous instanceof \Illuminate\Validation\ValidationException) {
                $extensions['code'] = 'VALIDATION_ERROR';
                $extensions['validation'] = $previous->errors();
            }
        }

        // Never leak stack traces in production
        if (app()->isProduction()) {
            $error = new Error(
                $error->getMessage(),
                $error->getNodes(),
                $error->getSource(),
                $error->getPositions(),
                $error->getPath(),
                null,
                $extensions
            );
        }

        return $next($error);
    }
}
