# GraphQL Phase 3 Implementation Plan

## Overview

This plan covers Phase 3 (GraphQL Server Backend) of the GraphQL API roadmap. It assumes Phases 1 and 2 are complete. The existing codebase has:
- `ApiKeyService`, `ApiKeyController`, `ApiKeyGuard` (Bearer token auth)
- `ApiToken` model with soft deletes, scopes, `key_prefix`
- `ApiKeyRateLimiter` middleware
- Settings schema with `graphql` group (all settings already in `settings-schema.php`)
- `ConfigServiceProvider.injectGraphQLConfig()` already wired
- Feature flag plumbing (`graphql_enabled` in public settings, DB-only toggle — no env var)
- `GraphQLFeatureGate` middleware reads from `SettingService` (not `config()`)
- Frontend API key management UI in User Security page

**Lighthouse PHP is NOT yet installed** — it must be added to `composer.json`.

## Implementation Order

The work is divided into 8 steps that must be executed sequentially (each depends on the prior step):

1. Install Lighthouse PHP and publish config
2. Configure Lighthouse (auth guard, middleware, security, CORS)
3. Create Phase 3A schema (user-facing types, queries, mutations)
4. Create Phase 3A resolvers
5. Create Phase 3B schema (admin read-only types, queries)
6. Create Phase 3B resolvers
7. Add usage/audit integration middleware
8. Write tests

---

## Step 1: Install Lighthouse PHP

**Goal**: Add the `nuwave/lighthouse` Composer dependency and publish its config file.

### Actions

1. Run from `backend/`:
   ```bash
   composer require nuwave/lighthouse
   php artisan vendor:publish --tag=lighthouse-config
   ```
   This creates `backend/config/lighthouse.php`.

2. Create the schema directory:
   ```bash
   mkdir -p backend/graphql
   ```

### Files created/modified
- `backend/composer.json` — `nuwave/lighthouse` added to `require`
- `backend/composer.lock` — updated
- `backend/config/lighthouse.php` — published (will be heavily modified in Step 2)

---

## Step 2: Configure Lighthouse

**Goal**: Configure `config/lighthouse.php` to use the `api-key` guard, wire rate-limiting middleware, enable security features from settings, configure CORS, and conditionally register routes.

### 2A: Modify `backend/config/lighthouse.php`

Replace the published config with the following key settings (keep Lighthouse defaults for everything not listed):

```php
<?php

return [
    'route' => [
        'uri' => '/graphql',
        'middleware' => [
            \App\Http\Middleware\GraphQLCors::class,
            \App\Http\Middleware\GraphQLFeatureGate::class,
            \App\Http\Middleware\AddCorrelationId::class,
            'auth:api-key',
            \App\Http\Middleware\ApiKeyRateLimiter::class,
            \App\Http\Middleware\GraphQLUsageTracker::class,
        ],
        'prefix' => 'api',
        // Final URL: /api/graphql
    ],

    'guard' => 'api-key',

    'schema_path' => base_path('graphql/schema.graphql'),

    'namespaces' => [
        'models' => ['App\\Models'],
        'queries' => ['App\\GraphQL\\Queries'],
        'mutations' => ['App\\GraphQL\\Mutations'],
        'subscriptions' => [],
        'interfaces' => [],
        'unions' => [],
        'scalars' => ['App\\GraphQL\\Scalars'],
        'directives' => ['App\\GraphQL\\Directives'],
    ],

    'security' => [
        'max_query_depth' => (int) config('graphql.max_query_depth', 12),
        'max_query_complexity' => (int) config('graphql.max_query_complexity', 200),
        'disable_introspection' => !config('graphql.introspection_enabled', false),
    ],

    'pagination' => [
        'default_count' => 25,
        'max_count' => (int) config('graphql.max_result_size', 100),
    ],

    'error_handlers' => [
        \Nuwave\Lighthouse\Execution\ExtensionErrorHandler::class,
        \App\GraphQL\ErrorHandlers\GraphQLErrorHandler::class,
    ],
];
```

### 2B: Create `backend/app/Http/Middleware/GraphQLFeatureGate.php`

Returns 404 when GraphQL is disabled. Reads from `SettingService` (DB-backed, no env var):

```php
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
```

### 2C: Create `backend/app/Http/Middleware/GraphQLUsageTracker.php`

Records each GraphQL request via `AuditService` and `UsageTrackingService`:

```php
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

            $this->apiKeyService->recordUsage($token, [
                'query_name' => $queryName,
            ]);

            $action = $this->isMutation($body) ? 'api.mutation' : 'api.query';
            $this->auditService->log(
                $action,
                null,
                [],
                [
                    'key_id' => $token->id,
                    'query_name' => $queryName,
                ],
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
        return str_starts_with(trim($query), 'mutation');
    }
}
```

### 2D: Create `backend/app/Http/Middleware/GraphQLCors.php`

Separate CORS for GraphQL (cross-origin allowed for API key requests, no cookies):

```php
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

        $allowedOrigins = config('graphql.cors_allowed_origins', '*');

        $response->headers->set('Access-Control-Allow-Origin', $allowedOrigins);
        $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, Accept');
        $response->headers->set('Access-Control-Max-Age', '86400');
        $response->headers->remove('Access-Control-Allow-Credentials');

        return $response;
    }
}
```

### 2E: Create `backend/app/Providers/GraphQLServiceProvider.php`

Override Lighthouse config at runtime after settings are loaded:

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class GraphQLServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        config([
            'lighthouse.security.max_query_depth' => (int) config('graphql.max_query_depth', 12),
            'lighthouse.security.max_query_complexity' => (int) config('graphql.max_query_complexity', 200),
            'lighthouse.security.disable_introspection' => !config('graphql.introspection_enabled', false),
            'lighthouse.pagination.max_count' => (int) config('graphql.max_result_size', 100),
        ]);
    }
}
```

Register in `backend/bootstrap/providers.php`.

### 2F: Modify `backend/bootstrap/app.php`

Add `'api/graphql'` to CSRF exceptions:

```php
$middleware->validateCsrfTokens(except: [
    // ... existing entries ...
    'api/graphql',  // GraphQL uses API key auth, not session
]);
```

### Files created
- `backend/config/lighthouse.php` (published, then customized)
- `backend/app/Http/Middleware/GraphQLFeatureGate.php`
- `backend/app/Http/Middleware/GraphQLUsageTracker.php`
- `backend/app/Http/Middleware/GraphQLCors.php`
- `backend/app/Providers/GraphQLServiceProvider.php`

### Files modified
- `backend/bootstrap/app.php` — add `api/graphql` to CSRF exceptions
- `backend/bootstrap/providers.php` — register `GraphQLServiceProvider`

---

## Step 3: Create Phase 3A Schema (User-Facing)

**Goal**: Define the GraphQL schema for user-facing types, queries, and mutations.

### Create `backend/graphql/schema.graphql`

```graphql
"A date-time string in ISO 8601 format with timezone."
scalar DateTime @scalar(class: "Nuwave\\Lighthouse\\Schema\\Types\\Scalars\\DateTime")

"A date string in Y-m-d format."
scalar Date @scalar(class: "Nuwave\\Lighthouse\\Schema\\Types\\Scalars\\Date")

"Arbitrary JSON data."
scalar JSON

type Query {
    "Get the currently authenticated user's profile."
    me: User! @guard(with: ["api-key"])

    "Get the authenticated user's notifications."
    myNotifications(
        category: String
        unreadOnly: Boolean
        first: Int! = 25 @rules(apply: ["min:1"])
        page: Int! = 1 @rules(apply: ["min:1"])
    ): NotificationPaginator! @guard(with: ["api-key"])

    "Get the authenticated user's API keys."
    myApiKeys: [ApiKey!]! @guard(with: ["api-key"])

    "Get the authenticated user's notification settings."
    myNotificationSettings: NotificationSettings! @guard(with: ["api-key"])

    # --- Phase 3B: Admin Read-Only Queries ---

    "List audit logs. Requires audit.view permission."
    auditLogs(
        first: Int! = 25 @rules(apply: ["min:1"])
        page: Int! = 1 @rules(apply: ["min:1"])
        filters: AuditLogFilter
        orderBy: [AuditLogOrderBy!]
    ): AuditLogPaginator! @guard(with: ["api-key"]) @can(ability: "audit.view")

    "List access logs. Requires audit.view permission."
    accessLogs(
        first: Int! = 25 @rules(apply: ["min:1"])
        page: Int! = 1 @rules(apply: ["min:1"])
        filters: AccessLogFilter
        orderBy: [AccessLogOrderBy!]
    ): AccessLogPaginator! @guard(with: ["api-key"]) @can(ability: "audit.view")

    "List notification deliveries. Requires notification_deliveries.view permission."
    notificationDeliveries(
        first: Int! = 25 @rules(apply: ["min:1"])
        page: Int! = 1 @rules(apply: ["min:1"])
        filters: NotificationDeliveryFilter
        orderBy: [NotificationDeliveryOrderBy!]
    ): NotificationDeliveryPaginator! @guard(with: ["api-key"]) @can(ability: "notification_deliveries.view")

    "List payments. Requires payments.view permission."
    payments(
        first: Int! = 25 @rules(apply: ["min:1"])
        page: Int! = 1 @rules(apply: ["min:1"])
        filters: PaymentFilter
        orderBy: [PaymentOrderBy!]
    ): PaymentPaginator! @guard(with: ["api-key"]) @can(ability: "payments.view")

    "Get integration usage statistics. Requires usage.view permission."
    usageStats(
        dateFrom: Date
        dateTo: Date
        integration: String
        first: Int! = 25 @rules(apply: ["min:1"])
        page: Int! = 1 @rules(apply: ["min:1"])
    ): UsageStatPaginator! @guard(with: ["api-key"]) @can(ability: "usage.view")

    "Get usage breakdown by integration. Requires usage.view permission."
    usageBreakdown(
        dateFrom: Date
        dateTo: Date
        integration: String
    ): [UsageBreakdownEntry!]! @guard(with: ["api-key"]) @can(ability: "usage.view")

    "List user groups. Requires groups.view permission."
    userGroups: [UserGroup!]! @guard(with: ["api-key"]) @can(ability: "groups.view")

    "List all users (admin). Requires users.view permission."
    users(
        first: Int! = 25 @rules(apply: ["min:1"])
        page: Int! = 1 @rules(apply: ["min:1"])
        search: String
    ): UserAdminPaginator! @guard(with: ["api-key"]) @can(ability: "users.view")
}

type Mutation {
    "Update the authenticated user's profile."
    updateProfile(input: UpdateProfileInput!): UpdateProfilePayload!
        @guard(with: ["api-key"])

    "Mark a notification as read."
    markNotificationAsRead(id: ID!): Notification!
        @guard(with: ["api-key"])

    "Delete one or more notifications."
    deleteNotifications(ids: [ID!]!): DeleteNotificationsPayload!
        @guard(with: ["api-key"])

    "Update the authenticated user's notification channel settings."
    updateNotificationSettings(input: NotificationSettingsInput!): NotificationSettings!
        @guard(with: ["api-key"])

    "Update per-type notification channel preferences."
    updateTypePreferences(input: TypePreferencesInput!): TypePreferencesPayload!
        @guard(with: ["api-key"])
}

# ========================================
# Phase 3A Types (User-Facing)
# ========================================

type User {
    id: ID!
    name: String!
    email: String!
    avatar: String
    emailVerifiedAt: DateTime @rename(attribute: "email_verified_at")
    twoFactorEnabled: Boolean! @rename(attribute: "two_factor_enabled")
    isAdmin: Boolean! @rename(attribute: "is_admin")
    createdAt: DateTime! @rename(attribute: "created_at")
    updatedAt: DateTime! @rename(attribute: "updated_at")
}

type Notification {
    id: ID!
    type: String!
    title: String!
    message: String
    data: JSON
    readAt: DateTime @rename(attribute: "read_at")
    createdAt: DateTime! @rename(attribute: "created_at")
}

type ApiKey {
    id: ID!
    name: String!
    keyPrefix: String! @rename(attribute: "key_prefix")
    lastUsedAt: DateTime @rename(attribute: "last_used_at")
    expiresAt: DateTime @rename(attribute: "expires_at")
    revokedAt: DateTime @rename(attribute: "revoked_at")
    status: String!
    createdAt: DateTime! @rename(attribute: "created_at")
}

type NotificationSettings {
    channels: [NotificationChannel!]!
    typePreferences: JSON!
}

type NotificationChannel {
    id: String!
    name: String!
    enabled: Boolean!
    configured: Boolean!
}

# ========================================
# Phase 3B Types (Admin Read-Only)
# ========================================

type AuditLog {
    id: ID!
    userId: ID @rename(attribute: "user_id")
    user: UserAdmin
    action: String!
    severity: String!
    auditableType: String @rename(attribute: "auditable_type")
    auditableId: String @rename(attribute: "auditable_id")
    oldValues: JSON @rename(attribute: "old_values")
    newValues: JSON @rename(attribute: "new_values")
    ipAddress: String @rename(attribute: "ip_address")
    userAgent: String @rename(attribute: "user_agent")
    correlationId: String @rename(attribute: "correlation_id")
    createdAt: DateTime! @rename(attribute: "created_at")
}

type AccessLog {
    id: ID!
    userId: ID @rename(attribute: "user_id")
    user: UserAdmin
    action: String!
    resourceType: String @rename(attribute: "resource_type")
    resourceId: String @rename(attribute: "resource_id")
    fieldsAccessed: JSON @rename(attribute: "fields_accessed")
    ipAddress: String @rename(attribute: "ip_address")
    userAgent: String @rename(attribute: "user_agent")
    correlationId: String @rename(attribute: "correlation_id")
    createdAt: DateTime! @rename(attribute: "created_at")
}

type NotificationDelivery {
    id: ID!
    userId: ID @rename(attribute: "user_id")
    user: UserAdmin
    notificationType: String! @rename(attribute: "notification_type")
    channel: String!
    status: String!
    error: String
    attempt: Int
    attemptedAt: DateTime @rename(attribute: "attempted_at")
    createdAt: DateTime! @rename(attribute: "created_at")
}

type Payment {
    id: ID!
    userId: ID @rename(attribute: "user_id")
    user: UserAdmin
    stripeCustomerId: String @field(resolver: "App\\GraphQL\\Types\\PaymentType@stripeCustomerId")
    stripePaymentIntentId: String @rename(attribute: "stripe_payment_intent_id")
    amount: Int!
    currency: String!
    status: String!
    description: String
    metadata: JSON
    applicationFeeAmount: Int @rename(attribute: "application_fee_amount")
    paidAt: DateTime @rename(attribute: "paid_at")
    refundedAt: DateTime @rename(attribute: "refunded_at")
    createdAt: DateTime! @rename(attribute: "created_at")
}

type UsageStat {
    id: ID!
    integration: String!
    provider: String!
    metric: String!
    quantity: Float!
    estimatedCost: Float @rename(attribute: "estimated_cost")
    metadata: JSON
    userId: ID @rename(attribute: "user_id")
    createdAt: DateTime! @rename(attribute: "created_at")
}

type UsageBreakdownEntry {
    integration: String!
    provider: String!
    totalQuantity: Float!
    totalCost: Float!
    count: Int!
}

type UserGroup {
    id: ID!
    name: String!
    slug: String!
    description: String
    isSystem: Boolean! @rename(attribute: "is_system")
    isDefault: Boolean! @rename(attribute: "is_default")
    memberCount: Int!
    permissions: [String!]!
    createdAt: DateTime! @rename(attribute: "created_at")
}

"Admin view of a user with all fields visible."
type UserAdmin {
    id: ID!
    name: String!
    email: String!
    avatar: String
    emailVerifiedAt: DateTime @rename(attribute: "email_verified_at")
    twoFactorEnabled: Boolean! @rename(attribute: "two_factor_enabled")
    isAdmin: Boolean! @rename(attribute: "is_admin")
    disabledAt: DateTime @rename(attribute: "disabled_at")
    createdAt: DateTime! @rename(attribute: "created_at")
    updatedAt: DateTime! @rename(attribute: "updated_at")
}

# ========================================
# Input Types
# ========================================

input UpdateProfileInput {
    name: String
    email: String
    avatar: String
}

input NotificationSettingsInput {
    channel: String!
    enabled: Boolean
    settings: JSON
}

input TypePreferencesInput {
    type: String!
    channel: String!
    enabled: Boolean!
}

input AuditLogFilter {
    action: String
    userId: ID
    severity: String
    dateFrom: Date
    dateTo: Date
}

input AccessLogFilter {
    action: String
    userId: ID
    resourceType: String
    dateFrom: Date
    dateTo: Date
}

input NotificationDeliveryFilter {
    notificationType: String
    channel: String
    status: String
    userId: ID
    dateFrom: Date
    dateTo: Date
}

input PaymentFilter {
    status: String
    userId: ID
    dateFrom: Date
    dateTo: Date
}

input AuditLogOrderBy {
    column: AuditLogOrderColumn!
    direction: SortDirection!
}

input AccessLogOrderBy {
    column: AccessLogOrderColumn!
    direction: SortDirection!
}

input NotificationDeliveryOrderBy {
    column: NotificationDeliveryOrderColumn!
    direction: SortDirection!
}

input PaymentOrderBy {
    column: PaymentOrderColumn!
    direction: SortDirection!
}

enum AuditLogOrderColumn {
    CREATED_AT
    ACTION
    SEVERITY
}

enum AccessLogOrderColumn {
    CREATED_AT
    ACTION
    RESOURCE_TYPE
}

enum NotificationDeliveryOrderColumn {
    CREATED_AT
    CHANNEL
    STATUS
}

enum PaymentOrderColumn {
    CREATED_AT
    AMOUNT
    STATUS
}

enum SortDirection {
    ASC
    DESC
}

# ========================================
# Paginator Types
# ========================================

type PaginatorInfo {
    count: Int!
    currentPage: Int!
    lastPage: Int!
    perPage: Int!
    total: Int!
    hasMorePages: Boolean!
}

type NotificationPaginator {
    data: [Notification!]!
    paginatorInfo: PaginatorInfo!
}

type AuditLogPaginator {
    data: [AuditLog!]!
    paginatorInfo: PaginatorInfo!
}

type AccessLogPaginator {
    data: [AccessLog!]!
    paginatorInfo: PaginatorInfo!
}

type NotificationDeliveryPaginator {
    data: [NotificationDelivery!]!
    paginatorInfo: PaginatorInfo!
}

type PaymentPaginator {
    data: [Payment!]!
    paginatorInfo: PaginatorInfo!
}

type UsageStatPaginator {
    data: [UsageStat!]!
    paginatorInfo: PaginatorInfo!
}

type UserAdminPaginator {
    data: [UserAdmin!]!
    paginatorInfo: PaginatorInfo!
}

# ========================================
# Mutation Payloads
# ========================================

type UpdateProfilePayload {
    user: User!
    emailVerificationSent: Boolean!
}

type DeleteNotificationsPayload {
    deletedCount: Int!
}

type TypePreferencesPayload {
    preferences: JSON!
}
```

---

## Step 4: Create Phase 3A Resolvers

**Goal**: Implement resolvers for user-facing queries and mutations. All resolvers are PHP classes in `backend/app/GraphQL/`.

### 4A: Create shared pagination trait `backend/app/GraphQL/Concerns/HandlesPagination.php`

```php
<?php

namespace App\GraphQL\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

trait HandlesPagination
{
    protected function paginatorResponse(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => $paginator->items(),
            'paginatorInfo' => [
                'count' => $paginator->count(),
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'hasMorePages' => $paginator->hasMorePages(),
            ],
        ];
    }

    protected function clampPerPage(int $requested): int
    {
        $max = (int) config('graphql.max_result_size', 100);
        return min(max($requested, 1), $max);
    }
}
```

### 4B: Create `backend/app/GraphQL/Queries/Me.php`

```php
<?php

namespace App\GraphQL\Queries;

use Illuminate\Support\Facades\Auth;

class Me
{
    public function __invoke($root, array $args, $context)
    {
        return Auth::guard('api-key')->user();
    }
}
```

### 4C: Create `backend/app/GraphQL/Queries/MyNotifications.php`

```php
<?php

namespace App\GraphQL\Queries;

use App\GraphQL\Concerns\HandlesPagination;
use App\Models\NotificationTemplate;
use Illuminate\Support\Facades\Auth;

class MyNotifications
{
    use HandlesPagination;

    public function __invoke($root, array $args, $context): array
    {
        $user = Auth::guard('api-key')->user();
        $query = $user->notifications()->orderBy('created_at', 'desc');

        if (!empty($args['unreadOnly'])) {
            $query->unread();
        }

        if (!empty($args['category'])) {
            $categoryTypes = $this->getCategoryTypeMap();
            $types = $categoryTypes[$args['category']] ?? [];
            if ($types) {
                $query->whereIn('type', $types);
            }
        }

        $perPage = $this->clampPerPage($args['first'] ?? 25);
        $paginator = $query->paginate($perPage, ['*'], 'page', $args['page'] ?? 1);

        return $this->paginatorResponse($paginator);
    }

    private function getCategoryTypeMap(): array
    {
        $types = cache()->remember('notification_category_type_map', 300, function () {
            return NotificationTemplate::query()
                ->select('type')
                ->distinct()
                ->pluck('type')
                ->toArray();
        });

        $map = [];
        foreach ($types as $type) {
            $parts = explode('.', $type);
            $category = match (true) {
                str_starts_with($type, 'backup.') => 'backup',
                str_starts_with($type, 'auth.') => 'auth',
                str_starts_with($type, 'system.') => 'system',
                str_starts_with($type, 'llm.') => 'llm',
                str_starts_with($type, 'storage.') => 'storage',
                str_starts_with($type, 'usage.') => 'usage',
                str_starts_with($type, 'payment.') => 'payment',
                $type === 'suspicious_activity' => 'security',
                default => $parts[0] ?? 'system',
            };
            $map[$category][] = $type;
        }

        return $map;
    }
}
```

### 4D: Create `backend/app/GraphQL/Queries/MyApiKeys.php`

```php
<?php

namespace App\GraphQL\Queries;

use Illuminate\Support\Facades\Auth;

class MyApiKeys
{
    public function __invoke($root, array $args, $context): array
    {
        $user = Auth::guard('api-key')->user();

        return $user->apiTokens()
            ->withTrashed()
            ->whereNotNull('key_prefix')
            ->where('key_prefix', 'like', 'sk_%')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($token) {
                $token->status = $this->getTokenStatus($token);
                return $token;
            })
            ->toArray();
    }

    private function getTokenStatus($token): string
    {
        if ($token->revoked_at) return 'revoked';
        if ($token->trashed()) return 'deleted';
        if ($token->isExpired()) return 'expired';
        if ($token->expires_at && $token->expires_at->isBefore(now()->addDays(7))) return 'expiring_soon';
        return 'active';
    }
}
```

### 4E: Create `backend/app/GraphQL/Queries/MyNotificationSettings.php`

```php
<?php

namespace App\GraphQL\Queries;

use Illuminate\Support\Facades\Auth;

class MyNotificationSettings
{
    public function __invoke($root, array $args, $context): array
    {
        $user = Auth::guard('api-key')->user();
        $userSettings = $user->settings()
            ->where('group', 'notifications')
            ->pluck('value', 'key')
            ->toArray();

        $channelConfig = config('notifications.channels');
        $channels = [];

        foreach ($channelConfig as $id => $config) {
            $channels[] = [
                'id' => $id,
                'name' => ucfirst(str_replace('_', ' ', $id)),
                'enabled' => (bool) ($userSettings["{$id}_enabled"] ?? false),
                'configured' => !empty($config['enabled'] ?? false),
            ];
        }

        $typePreferences = $user->getSetting('notifications', 'type_preferences', []);

        return [
            'channels' => $channels,
            'typePreferences' => is_array($typePreferences) ? $typePreferences : [],
        ];
    }
}
```

### 4F: Create `backend/app/GraphQL/Mutations/UpdateProfile.php`

```php
<?php

namespace App\GraphQL\Mutations;

use App\Models\User;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\Auth;

class UpdateProfile
{
    public function __invoke($root, array $args, $context): array
    {
        $user = Auth::guard('api-key')->user();
        $input = $args['input'];
        $emailChanged = false;

        if (isset($input['name'])) {
            $user->name = $input['name'];
        }

        if (isset($input['email']) && $input['email'] !== $user->email) {
            $exists = User::where('email', $input['email'])
                ->where('id', '!=', $user->id)
                ->exists();
            if ($exists) {
                throw new Error('The email has already been taken.',
                    extensions: ['code' => 'VALIDATION_ERROR', 'field' => 'email']);
            }
            $user->email = $input['email'];
            $user->email_verified_at = null;
            $emailChanged = true;
        }

        if (array_key_exists('avatar', $input)) {
            $user->avatar = $input['avatar'];
        }

        $user->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return [
            'user' => $user,
            'emailVerificationSent' => $emailChanged,
        ];
    }
}
```

### 4G: Create `backend/app/GraphQL/Mutations/MarkNotificationAsRead.php`

```php
<?php

namespace App\GraphQL\Mutations;

use GraphQL\Error\Error;
use Illuminate\Support\Facades\Auth;

class MarkNotificationAsRead
{
    public function __invoke($root, array $args, $context)
    {
        $user = Auth::guard('api-key')->user();

        $notification = $user->notifications()
            ->where('id', $args['id'])
            ->first();

        if (!$notification) {
            throw new Error('Notification not found.', extensions: ['code' => 'NOT_FOUND']);
        }

        $notification->markAsRead();

        return $notification;
    }
}
```

### 4H: Create `backend/app/GraphQL/Mutations/DeleteNotifications.php`

```php
<?php

namespace App\GraphQL\Mutations;

use GraphQL\Error\Error;
use Illuminate\Support\Facades\Auth;

class DeleteNotifications
{
    public function __invoke($root, array $args, $context): array
    {
        $user = Auth::guard('api-key')->user();
        $ids = $args['ids'];

        if (count($ids) > 100) {
            throw new Error('Cannot delete more than 100 notifications at once.',
                extensions: ['code' => 'VALIDATION_ERROR']);
        }

        $deleted = $user->notifications()
            ->whereIn('id', $ids)
            ->delete();

        return ['deletedCount' => $deleted];
    }
}
```

### 4I: Create `backend/app/GraphQL/Mutations/UpdateNotificationSettings.php`

```php
<?php

namespace App\GraphQL\Mutations;

use GraphQL\Error\Error;
use Illuminate\Support\Facades\Auth;

class UpdateNotificationSettings
{
    public function __invoke($root, array $args, $context): array
    {
        $user = Auth::guard('api-key')->user();
        $input = $args['input'];
        $channelId = $input['channel'];

        $channelConfig = config('notifications.channels');
        if (!isset($channelConfig[$channelId])) {
            throw new Error("Unknown notification channel: {$channelId}",
                extensions: ['code' => 'VALIDATION_ERROR']);
        }

        if (isset($input['enabled'])) {
            $user->setSetting('notifications', "{$channelId}_enabled", $input['enabled']);
        }

        if (isset($input['settings']) && is_array($input['settings'])) {
            foreach ($input['settings'] as $key => $value) {
                $user->setSetting('notifications', "{$channelId}_{$key}", (string) $value);
            }
        }

        // Re-read and return current settings
        return app(\App\GraphQL\Queries\MyNotificationSettings::class)(null, [], null);
    }
}
```

### 4J: Create `backend/app/GraphQL/Mutations/UpdateTypePreferences.php`

```php
<?php

namespace App\GraphQL\Mutations;

use App\Models\NotificationTemplate;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\Auth;

class UpdateTypePreferences
{
    public function __invoke($root, array $args, $context): array
    {
        $user = Auth::guard('api-key')->user();
        $input = $args['input'];

        $type = $input['type'];
        $channel = $input['channel'];
        $enabled = $input['enabled'];

        $channelConfig = config('notifications.channels');
        if (!isset($channelConfig[$channel])) {
            throw new Error("Unknown channel: {$channel}",
                extensions: ['code' => 'VALIDATION_ERROR']);
        }

        $knownTypes = cache()->remember('notification_known_types', 300, function () {
            return NotificationTemplate::query()->distinct()->pluck('type')->toArray();
        });

        if (!in_array($type, $knownTypes, true)) {
            throw new Error("Unknown notification type: {$type}",
                extensions: ['code' => 'VALIDATION_ERROR']);
        }

        $prefs = $user->getSetting('notifications', 'type_preferences', []);
        if (!is_array($prefs)) {
            $prefs = [];
        }

        if ($enabled) {
            unset($prefs[$type][$channel]);
            if (isset($prefs[$type]) && empty($prefs[$type])) {
                unset($prefs[$type]);
            }
        } else {
            if (!isset($prefs[$type])) {
                $prefs[$type] = [];
            }
            $prefs[$type][$channel] = false;
        }

        $user->setSetting('notifications', 'type_preferences', $prefs);

        return ['preferences' => $prefs];
    }
}
```

---

## Step 5: Create Phase 3B Resolvers (Admin Read-Only)

All admin resolvers follow the same pattern as `AuditLogs`. Use the `HandlesPagination` trait.

### 5A: Create `backend/app/GraphQL/Queries/AuditLogs.php`

```php
<?php

namespace App\GraphQL\Queries;

use App\GraphQL\Concerns\HandlesPagination;
use App\Models\AuditLog;

class AuditLogs
{
    use HandlesPagination;

    public function __invoke($root, array $args, $context): array
    {
        $query = AuditLog::with('user');

        if (!empty($args['filters'])) {
            $f = $args['filters'];
            if (!empty($f['action'])) $query->where('action', $f['action']);
            if (!empty($f['userId'])) $query->where('user_id', $f['userId']);
            if (!empty($f['severity'])) $query->where('severity', $f['severity']);
            if (!empty($f['dateFrom'])) $query->where('created_at', '>=', $f['dateFrom']);
            if (!empty($f['dateTo'])) $query->where('created_at', '<=', $f['dateTo'] . ' 23:59:59');
        }

        $this->applyOrderBy($query, $args['orderBy'] ?? [['column' => 'CREATED_AT', 'direction' => 'DESC']]);

        $perPage = $this->clampPerPage($args['first'] ?? 25);
        $paginator = $query->paginate($perPage, ['*'], 'page', $args['page'] ?? 1);

        return $this->paginatorResponse($paginator);
    }

    private function applyOrderBy($query, array $orderBy): void
    {
        $columnMap = [
            'CREATED_AT' => 'created_at',
            'ACTION' => 'action',
            'SEVERITY' => 'severity',
        ];

        foreach ($orderBy as $order) {
            $col = $columnMap[$order['column']] ?? 'created_at';
            $dir = strtolower($order['direction'] ?? 'desc');
            $query->orderBy($col, $dir);
        }
    }
}
```

### 5B-5G: Remaining admin resolvers (same pattern)

Create each following the AuditLogs pattern:

- **`backend/app/GraphQL/Queries/AccessLogs.php`** — queries `AccessLog::with('user')`, filters: `action`, `userId`, `resourceType`, `dateFrom`, `dateTo`, orderBy columns: `CREATED_AT`, `ACTION`, `RESOURCE_TYPE`
- **`backend/app/GraphQL/Queries/NotificationDeliveries.php`** — queries `NotificationDelivery::with('user')`, filters: `notificationType`, `channel`, `status`, `userId`, `dateFrom`, `dateTo`, orderBy columns: `CREATED_AT`, `CHANNEL`, `STATUS`
- **`backend/app/GraphQL/Queries/Payments.php`** — queries `Payment::with('user')`, filters: `status`, `userId`, `dateFrom`, `dateTo`, orderBy columns: `CREATED_AT`, `AMOUNT`, `STATUS`
- **`backend/app/GraphQL/Queries/UsageStats.php`** — queries `IntegrationUsage`, filters: `dateFrom`, `dateTo`, `integration`
- **`backend/app/GraphQL/Queries/UsageBreakdown.php`** — returns aggregated results: `selectRaw('integration, provider, SUM(quantity) as total_quantity, SUM(estimated_cost) as total_cost, COUNT(*) as count')`, grouped by `integration, provider`, filters: `dateFrom`, `dateTo`, `integration`. NOT paginated — returns array.
- **`backend/app/GraphQL/Queries/UserGroupsQuery.php`** — returns `UserGroup::with(['permissions', 'members'])->get()`, adds computed `memberCount` (count of members) and `permissions` (array of permission strings). NOT paginated.
- **`backend/app/GraphQL/Queries/UsersQuery.php`** — queries `User`, supports `search` filter (LIKE on name and email), paginated.

### 5H: Create `backend/app/GraphQL/Types/PaymentType.php`

Field-level auth for `Payment.stripeCustomerId`:

```php
<?php

namespace App\GraphQL\Types;

use App\Enums\Permission;
use Illuminate\Support\Facades\Auth;

class PaymentType
{
    public function stripeCustomerId($payment): ?string
    {
        $user = Auth::guard('api-key')->user();
        if ($user && $user->hasPermission(Permission::PAYMENTS_MANAGE)) {
            return $payment->stripe_customer_id;
        }
        return null;
    }
}
```

---

## Step 6: Create Error Handler

### Create `backend/app/GraphQL/ErrorHandlers/GraphQLErrorHandler.php`

```php
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
        $extensions = $error->getExtensions();

        if ($previous instanceof \Illuminate\Auth\AuthenticationException) {
            $extensions['code'] = 'UNAUTHENTICATED';
        } elseif ($previous instanceof \Illuminate\Auth\Access\AuthorizationException) {
            $extensions['code'] = 'FORBIDDEN';
        } elseif ($previous instanceof \Illuminate\Validation\ValidationException) {
            $extensions['code'] = 'VALIDATION_ERROR';
            $extensions['validation'] = $previous->errors();
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
```

---

## Step 7: Wire Everything Together

### 7A: Register GraphQLServiceProvider

Add to `backend/bootstrap/providers.php`:
```php
App\Providers\GraphQLServiceProvider::class,
```

### 7B: Add CSRF exception

In `backend/bootstrap/app.php`, add `'api/graphql'` to `validateCsrfTokens(except: [...])`.

---

## Step 8: Tests

All tests use Pest. Create the following test files:

### Test helper additions to `backend/tests/Pest.php`

```php
function createApiKey(\App\Models\User $user, string $name = 'Test Key'): array
{
    return app(\App\Services\ApiKeyService::class)->create($user, $name);
}

function graphQL(string $query, array $variables = [], ?string $bearerToken = null): \Illuminate\Testing\TestResponse
{
    $headers = [];
    if ($bearerToken) {
        $headers['Authorization'] = 'Bearer ' . $bearerToken;
    }
    return test()->postJson('/api/graphql', [
        'query' => $query,
        'variables' => $variables,
    ], $headers);
}
```

### Test files to create

| File | Tests |
|------|-------|
| `backend/tests/Feature/GraphQL/FeatureGateTest.php` | 404 when disabled, 200 when enabled, 401 without key |
| `backend/tests/Feature/GraphQL/UserQueriesTest.php` | `me`, `myNotifications`, `myApiKeys`, `myNotificationSettings` |
| `backend/tests/Feature/GraphQL/UserMutationsTest.php` | `updateProfile`, `markNotificationAsRead`, `deleteNotifications`, `updateNotificationSettings`, `updateTypePreferences` |
| `backend/tests/Feature/GraphQL/AdminQueriesTest.php` | All admin queries + FORBIDDEN for non-admin + filters + pagination |
| `backend/tests/Feature/GraphQL/SecurityTest.php` | Depth limiting, complexity, introspection toggle, max result size, rate limiting |
| `backend/tests/Feature/GraphQL/ErrorHandlingTest.php` | All error codes: UNAUTHENTICATED, FORBIDDEN, VALIDATION_ERROR, NOT_FOUND |

---

## Complete File List

### New files (27 files)

| File | Purpose |
|------|---------|
| `backend/graphql/schema.graphql` | Complete GraphQL schema |
| `backend/config/lighthouse.php` | Lighthouse configuration |
| `backend/app/Providers/GraphQLServiceProvider.php` | Runtime config overrides |
| `backend/app/Http/Middleware/GraphQLFeatureGate.php` | 404 when disabled |
| `backend/app/Http/Middleware/GraphQLUsageTracker.php` | Usage and audit recording |
| `backend/app/Http/Middleware/GraphQLCors.php` | Cross-origin for API key requests |
| `backend/app/GraphQL/Concerns/HandlesPagination.php` | Shared pagination trait |
| `backend/app/GraphQL/Queries/Me.php` | `me` resolver |
| `backend/app/GraphQL/Queries/MyNotifications.php` | `myNotifications` resolver |
| `backend/app/GraphQL/Queries/MyApiKeys.php` | `myApiKeys` resolver |
| `backend/app/GraphQL/Queries/MyNotificationSettings.php` | `myNotificationSettings` resolver |
| `backend/app/GraphQL/Queries/AuditLogs.php` | `auditLogs` resolver |
| `backend/app/GraphQL/Queries/AccessLogs.php` | `accessLogs` resolver |
| `backend/app/GraphQL/Queries/NotificationDeliveries.php` | `notificationDeliveries` resolver |
| `backend/app/GraphQL/Queries/Payments.php` | `payments` resolver |
| `backend/app/GraphQL/Queries/UsageStats.php` | `usageStats` resolver |
| `backend/app/GraphQL/Queries/UsageBreakdown.php` | `usageBreakdown` resolver |
| `backend/app/GraphQL/Queries/UserGroupsQuery.php` | `userGroups` resolver |
| `backend/app/GraphQL/Queries/UsersQuery.php` | `users` resolver |
| `backend/app/GraphQL/Mutations/UpdateProfile.php` | `updateProfile` resolver |
| `backend/app/GraphQL/Mutations/MarkNotificationAsRead.php` | `markNotificationAsRead` resolver |
| `backend/app/GraphQL/Mutations/DeleteNotifications.php` | `deleteNotifications` resolver |
| `backend/app/GraphQL/Mutations/UpdateNotificationSettings.php` | `updateNotificationSettings` resolver |
| `backend/app/GraphQL/Mutations/UpdateTypePreferences.php` | `updateTypePreferences` resolver |
| `backend/app/GraphQL/ErrorHandlers/GraphQLErrorHandler.php` | Error handler with codes |
| `backend/app/GraphQL/Types/PaymentType.php` | Field-level auth for Payment |
| `backend/tests/Feature/GraphQL/*.php` | 6 test files |

### Modified files (3 files)

| File | Change |
|------|--------|
| `backend/composer.json` | Add `nuwave/lighthouse` |
| `backend/bootstrap/app.php` | Add `api/graphql` to CSRF exceptions |
| `backend/bootstrap/providers.php` | Register `GraphQLServiceProvider` |
| `backend/tests/Pest.php` | Add `createApiKey()` and `graphQL()` helpers |

## Key Architectural Decisions

1. **Schema-first**: Using Lighthouse's `.graphql` schema files, not code-first
2. **Manual resolvers**: Explicit PHP resolver classes for full control over scoping, filtering, and auth (not relying on Lighthouse auto-resolution magic)
3. **Manual pagination**: Using `HandlesPagination` trait to enforce admin-configurable `max_result_size`
4. **Authorization**: `@can` directive for query-level admin gates + manual owner-scoping in user resolvers
5. **CORS**: Separate middleware for GraphQL (cross-origin, no cookies) vs session CORS
6. **Feature gate**: Middleware-based 404, not conditional route registration
7. **Fields excluded from schema** (never defined): `password`, `remember_token`, `two_factor_secret`, `two_factor_recovery_codes`, `token` (API key hash)
