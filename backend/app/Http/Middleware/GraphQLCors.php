<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GraphQLCors
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('OPTIONS')) {
            $response = response('', 204);
        } else {
            $response = $next($request);
        }

        $allowedOrigins = config('graphql.cors_allowed_origins', config('app.frontend_url', 'http://localhost:3000'));
        $origin = $request->header('Origin', '');

        if ($allowedOrigins === '*') {
            $response->headers->set('Access-Control-Allow-Origin', '*');
        } else {
            $origins = array_map('trim', explode(',', $allowedOrigins));
            if (in_array($origin, $origins, true)) {
                $response->headers->set('Access-Control-Allow-Origin', $origin);
                $response->headers->set('Vary', 'Origin');
            }
        }

        $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, Accept');
        $response->headers->set('Access-Control-Max-Age', '86400');
        $response->headers->remove('Access-Control-Allow-Credentials');

        return $response;
    }
}
