<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Models\ApiToken;
use App\Models\IntegrationUsage;
use App\Models\User;
use App\Services\ApiKeyService;
use App\Services\AuditService;
use App\Services\SettingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GraphQLSettingController extends Controller
{
    use ApiResponseTrait;

    private const GROUP = 'graphql';

    public function __construct(
        private SettingService $settingService,
        private AuditService $auditService,
        private ApiKeyService $apiKeyService
    ) {}

    /**
     * Get GraphQL settings.
     */
    public function show(): JsonResponse
    {
        $settings = $this->settingService->getGroup(self::GROUP);

        return $this->dataResponse(['settings' => $settings]);
    }

    /**
     * Update GraphQL settings.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'max_keys_per_user' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'default_rate_limit' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'introspection_enabled' => ['sometimes', 'boolean'],
            'max_query_depth' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'max_query_complexity' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'max_result_size' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'key_rotation_grace_days' => ['sometimes', 'integer', 'min:0', 'max:90'],
            'cors_allowed_origins' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $userId = $request->user()->id;
        $oldSettings = $this->settingService->getGroup(self::GROUP);

        foreach ($validated as $key => $value) {
            $this->settingService->set(self::GROUP, $key, $value === '' ? null : $value, $userId);
        }

        $this->auditService->logSettings(self::GROUP, $oldSettings, $validated, $userId);

        return $this->successResponse('GraphQL settings updated successfully');
    }

    /**
     * List all API keys across all users (admin).
     */
    public function adminApiKeys(Request $request): JsonResponse
    {
        $query = ApiToken::withTrashed()
            ->whereNotNull('key_prefix')
            ->where('key_prefix', 'like', 'sk_%')
            ->with('user:id,name,email');

        if ($request->filled('status')) {
            match ($request->input('status')) {
                'active' => $query->active(),
                'expired' => $query->expired(),
                'revoked' => $query->revoked(),
                default => null,
            };
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        if ($request->filled('user')) {
            $search = str_replace(['%', '_'], ['\\%', '\\_'], $request->input('user'));
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->input('expiring_soon') === 'true') {
            $query->whereNotNull('expires_at')
                ->where('expires_at', '>', now())
                ->where('expires_at', '<=', now()->addDays(7))
                ->whereNull('revoked_at');
        }

        $query->orderByDesc('created_at');

        $keys = $query->paginate($request->input('per_page', 50));

        $keys->getCollection()->transform(function (ApiToken $token) {
            return [
                'id' => $token->id,
                'user' => $token->user ? [
                    'id' => $token->user->id,
                    'name' => $token->user->name,
                    'email' => $token->user->email,
                ] : null,
                'name' => $token->name,
                'key_prefix' => $token->key_prefix,
                'created_at' => $token->created_at?->toIso8601String(),
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
                'revoked_at' => $token->revoked_at?->toIso8601String(),
                'status' => $this->getKeyStatus($token),
            ];
        });

        return response()->json($keys);
    }

    /**
     * Get API key summary statistics (admin).
     */
    public function adminApiKeyStats(): JsonResponse
    {
        $baseQuery = ApiToken::withTrashed()
            ->whereNotNull('key_prefix')
            ->where('key_prefix', 'like', 'sk_%');

        $total = (clone $baseQuery)->count();

        $active = (clone $baseQuery)
            ->whereNull('revoked_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->whereNull('deleted_at')
            ->count();

        $expiringSoon = (clone $baseQuery)
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDays(7))
            ->whereNull('revoked_at')
            ->whereNull('deleted_at')
            ->count();

        $neverUsed = (clone $baseQuery)
            ->whereNull('last_used_at')
            ->whereNull('revoked_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->whereNull('deleted_at')
            ->count();

        return $this->dataResponse([
            'total' => $total,
            'active' => $active,
            'expiring_soon' => $expiringSoon,
            'never_used' => $neverUsed,
        ]);
    }

    /**
     * Revoke any user's API key (admin).
     */
    public function adminRevokeKey(Request $request, int $id): JsonResponse
    {
        // Use withTrashed so we can distinguish "not found" from "already revoked"
        $token = ApiToken::withTrashed()
            ->whereNotNull('key_prefix')
            ->where('key_prefix', 'like', 'sk_%')
            ->find($id);

        if (! $token) {
            return $this->errorResponse('API key not found', 404);
        }

        if ($token->isRevoked()) {
            return $this->errorResponse('API key is already revoked', 422);
        }

        $this->apiKeyService->revoke($token);

        return $this->successResponse('API key revoked successfully');
    }

    /**
     * Get API usage statistics.
     */
    public function usageStats(Request $request): JsonResponse
    {
        $days = (int) $request->input('days', 30);
        $days = min(max($days, 1), 365);

        $total7d = IntegrationUsage::byIntegration('api')
            ->where('created_at', '>=', now()->subDays(7))
            ->sum('quantity');

        $total30d = IntegrationUsage::byIntegration('api')
            ->where('created_at', '>=', now()->subDays(30))
            ->sum('quantity');

        // Daily breakdown for chart
        $daily = IntegrationUsage::byIntegration('api')
            ->where('created_at', '>=', now()->subDays($days))
            ->selectRaw("DATE(created_at) as date, SUM(quantity) as count")
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('count', 'date')
            ->toArray();

        // Top 10 users by request count
        $topUserRows = IntegrationUsage::byIntegration('api')
            ->where('created_at', '>=', now()->subDays($days))
            ->whereNotNull('user_id')
            ->selectRaw('user_id, SUM(quantity) as total_requests')
            ->groupBy('user_id')
            ->orderByDesc('total_requests')
            ->limit(10)
            ->get();

        $userIds = $topUserRows->pluck('user_id')->all();
        $users = User::select('id', 'name', 'email')
            ->whereIn('id', $userIds)
            ->get()
            ->keyBy('id');

        $topUsers = $topUserRows->map(function ($row) use ($users) {
            $user = $users->get($row->user_id);

            return [
                'user_id' => $row->user_id,
                'name' => $user?->name ?? "User #{$row->user_id}",
                'email' => $user?->email,
                'total_requests' => (int) $row->total_requests,
            ];
        });

        // Top 10 query names — use DB-appropriate JSON extraction
        $driver = IntegrationUsage::query()->getConnection()->getDriverName();
        $jsonExpr = match ($driver) {
            'pgsql' => "metadata->>'query_name'",
            default => "JSON_EXTRACT(metadata, '$.query_name')",
        };
        $topQueries = IntegrationUsage::byIntegration('api')
            ->where('created_at', '>=', now()->subDays($days))
            ->whereNotNull('metadata')
            ->selectRaw("{$jsonExpr} as query_name, SUM(quantity) as total_requests")
            ->groupBy('query_name')
            ->orderByDesc('total_requests')
            ->limit(10)
            ->get()
            ->filter(fn ($row) => $row->query_name !== null)
            ->map(fn ($row) => [
                // SQLite's JSON_EXTRACT returns double-quoted strings; trim them
                'query_name' => trim($row->query_name, '"'),
                'total_requests' => (int) $row->total_requests,
            ])
            ->values();

        return $this->dataResponse([
            'total_7d' => (int) $total7d,
            'total_30d' => (int) $total30d,
            'daily' => $daily,
            'top_users' => $topUsers,
            'top_queries' => $topQueries,
        ]);
    }

    private function getKeyStatus(ApiToken $token): string
    {
        return $this->apiKeyService->getKeyStatus($token);
    }
}
