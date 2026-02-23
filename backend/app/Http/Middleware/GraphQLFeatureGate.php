<?php

namespace App\Http\Middleware;

use App\Services\SettingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GraphQLFeatureGate
{
    public function __construct(private SettingService $settingService) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (!filter_var($this->settingService->get('graphql', 'enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            abort(404);
        }

        return $next($request);
    }
}
