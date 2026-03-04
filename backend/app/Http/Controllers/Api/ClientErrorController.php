<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ClientErrorController extends Controller
{
    use ApiResponseTrait;
    /**
     * Store a client-side error report. Logs to application log (not database).
     * Rate limited to prevent abuse.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'stack' => ['nullable', 'string', 'max:10000'],
            'component_stack' => ['nullable', 'string', 'max:5000'],
            'url' => ['nullable', 'string', 'max:2048'],
            'user_agent' => ['nullable', 'string', 'max:500'],
            'timestamp' => ['nullable', 'string'],
            'level' => ['nullable', 'string', 'in:info,warning,error'],
            'context' => ['nullable', 'array'],
        ]);

        $level = $validated['level'] ?? 'error';
        $correlationId = $request->header('X-Correlation-ID');

        $context = array_filter([
            'client_error' => true,
            'correlation_id' => $correlationId,
            'url' => $validated['url'] ?? null,
            'user_agent' => Str::limit($validated['user_agent'] ?? '', 200),
            'stack' => $validated['stack'] ?? null,
            'component_stack' => $validated['component_stack'] ?? null,
            'context' => $validated['context'] ?? null,
        ]);

        $message = 'Client error: ' . $validated['message'];

        match ($level) {
            'info' => Log::info($message, $context),
            'warning' => Log::warning($message, $context),
            default => Log::error($message, $context),
        };

        return $this->successResponse('Error reported');
    }
}
