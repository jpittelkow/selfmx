<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Services\ApiKeyService;
use App\Services\AuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GraphQLUsageTracker
{
    public function __construct(
        private ApiKeyService $apiKeyService,
        private AuditService $auditService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $token = $request->attributes->get('api_token');
        if ($token instanceof ApiToken) {
            $body = $request->input();
            $queryName = $this->extractQueryName($body);

            $this->apiKeyService->recordUsage($token, ['query_name' => $queryName]);

            $action = $this->isMutation($body) ? 'api.mutation' : 'api.query';
            $this->auditService->log(
                $action,
                null,
                [],
                ['key_id' => $token->id, 'query_name' => $queryName],
                $token->user_id
            );
        }

        return $response;
    }

    private function extractQueryName(array $body): string
    {
        $operationName = $body['operationName'] ?? null;
        if ($operationName) {
            return $operationName;
        }

        $query = $body['query'] ?? '';
        if (preg_match('/(?:query|mutation)\s+(\w+)/', $query, $matches)) {
            return $matches[1];
        }

        return 'anonymous';
    }

    private function isMutation(array $body): bool
    {
        $query = $body['query'] ?? '';
        // Strip leading whitespace and comments before checking the operation type
        $stripped = preg_replace('/^\s*(#[^\n]*\n\s*)*/', '', $query);
        return str_starts_with($stripped, 'mutation');
    }
}
