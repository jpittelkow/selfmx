# GraphQL API Roadmap

Add a GraphQL API layer to Sourdough with user-managed API keys. Users generate and manage their own API keys in Security (user menu), enabling programmatic access to their data via GraphQL. Admin-configurable: GraphQL can be enabled/disabled from the Configuration > Integrations > GraphQL API page (no env var required).

## Architecture

- **GraphQL server** via [Lighthouse PHP](https://lighthouse-php.com/) (Laravel-native GraphQL framework) — schema-first approach, integrates with existing Eloquent models and Gates
- **User API keys** — extend the existing `ApiToken` model (`backend/app/Models/ApiToken.php`) with key prefix display, soft deletes, and rate limit support rather than creating a new table. The current model already has `user_id`, `name`, `token` (SHA-256 hashed), `abilities`, `last_used_at`, `expires_at` — add missing columns via migration. Keep the existing SHA-256 hashing approach (same as Sanctum — required for O(1) indexed token lookup; bcrypt cannot be indexed)
- **Authentication** — API key passed via `Authorization: Bearer <key>` header; resolved to the owning user with all their permissions intact (same Gate checks as session auth). Separate `api-key` guard from Sanctum session auth — registered in `config/auth.php`. Sanctum's `HasApiTokens` trait and `PersonalAccessToken` model remain for session-based auth; the custom `ApiToken` model is for user-managed API keys only. Both systems coexist without conflict
- **Rate limiting** — layered strategy: per-key request count (default 60/min, admin-configurable) plus query complexity scoring (expensive queries cost more against the limit). Tracked via Laravel's built-in rate limiter
- **Query security** — depth limiting, complexity scoring, and max result sizes to prevent DoS via nested/expensive queries
- **Feature-gated** — entire module gated behind `graphql.enabled` DB setting (toggle on Configuration > GraphQL API page); routes return 404 and Security page API keys section hidden when disabled. No env var required
- **Usage tracking** — API key usage (request count, last used timestamp) tracked and visible to the user; integrated with `UsageTrackingService` for admin visibility and `AuditService` for compliance logging
- **CORS** — separate CORS policy for GraphQL endpoint (cross-origin allowed for API key requests) vs session auth (same-origin only). Configured per-path in `config/cors.php` using Laravel's built-in `paths` array
- **Subscriptions** — out of scope for initial release. WebSocket-based GraphQL subscriptions may be added in a future phase if demand warrants it

## Resource Prioritization

All 23 existing models evaluated for GraphQL exposure. Phased rollout:

### Phase 3A — User-Facing (Read + Write)

| Type | Source Model | Queries | Mutations | Auth |
|------|-------------|---------|-----------|------|
| `User` (self) | `User` | `me` | `updateProfile`, `updatePassword` | Owner only |
| `Notification` | `Notification` | `myNotifications` | `markAsRead`, `deleteNotification` | Owner only |
| `ApiKey` | `ApiToken` | `myApiKeys` | `createApiKey`, `updateApiKey`, `revokeApiKey`, `rotateApiKey` | Owner only |
| `NotificationSetting` | `Setting` (notifications group) | `myNotificationSettings` | `updateNotificationSettings`, `updateTypePreferences` | Owner only |

### Phase 3B — Admin Read-Only

| Type | Source Model | Queries | Auth |
|------|-------------|---------|------|
| `AuditLog` | `AuditLog` | `auditLogs` | `audit.view` |
| `AccessLog` | `AccessLog` | `accessLogs` | `audit.view` |
| `NotificationDelivery` | `NotificationDelivery` | `notificationDeliveries` | `notification_deliveries.view` |
| `Payment` | `Payment` | `payments` | `payments.view` |
| `IntegrationUsage` | `IntegrationUsage` | `usageStats`, `usageBreakdown` | `usage.view` |
| `UserGroup` | `UserGroup` | `userGroups` | `groups.view` |
| `UserAdmin` | `User` | `users` | `users.view` |

### Phase 3C — Admin Read + Write (Future)

Deferred. See **Future Extensions** appendix at the bottom of this roadmap.

### Not Exposed via GraphQL

| Model | Reason |
|-------|--------|
| `SocialAccount` | Internal OAuth state, no user value |
| `GroupPermission` | Exposed as nested field on `UserGroup` |
| `TaskRun` | Internal scheduler state |
| `UserOnboarding` | Session-specific wizard state |
| `StripeCustomer` | Internal Stripe mapping, exposed as field on `Payment` |
| `StripeWebhookEvent` | Internal webhook processing log |
| `AIRequestLog` | Aggregated via `IntegrationUsage`, raw logs too verbose |

## Phase 1: API Key Management (Backend)

### Migration & Model

- [ ] Add migration to extend `api_tokens` table — new columns: `key_prefix` (string, first 8 chars of plaintext for display), `rate_limit` (integer, nullable override), `rotated_from_id` (foreign key, nullable, self-referencing), `revoked_at` (timestamp, nullable), `deleted_at` (soft delete). The existing `token` column already stores SHA-256 hashes — no hashing change needed
- [ ] Update `ApiToken` model: add soft deletes, `key_prefix` attribute, `rotated_from_id` relationship, scopes (`active`, `expired`, `revoked`)
- [ ] Update `ApiTokenController.index()` to use `$token->key_prefix` instead of `substr($token->token, 0, 8)` (current preview shows hash prefix, not plaintext prefix)
- [ ] Preserve backward compatibility — existing tokens continue to work; new tokens additionally store `key_prefix` for display

### Service Layer

- [ ] Create `ApiKeyService` — generate key (returns plaintext once), hash and store, validate against hash, revoke, prune expired
- [ ] Key rotation — `rotate($keyId)`: create new key linked via `rotated_from_id`, old key remains valid for configurable grace period (default 7 days), auto-revoke after grace period via scheduled command
- [ ] Add `artisan api-keys:prune-expired` command — soft-delete expired keys and auto-revoke rotated keys past grace period. Register in scheduler (daily)
- [ ] Key format — prefix with `sk_` for identification (e.g., `sk_a1b2c3d4e5f6...`), 64 random chars after prefix

### Controller & Routes

- [ ] Create `ApiKeyController` — CRUD under `/api/user/api-keys` (session auth required, users manage only their own keys)
  - `GET /api/user/api-keys` — list user's keys (prefix, name, created, last used, expires, revoked status — never returns full key)
  - `POST /api/user/api-keys` — create key (returns plaintext key once in response)
  - `PUT /api/user/api-keys/{id}` — update name, expiration
  - `DELETE /api/user/api-keys/{id}` — revoke (soft delete + set `revoked_at`)
  - `POST /api/user/api-keys/{id}/rotate` — generate replacement key, return new plaintext once

### Authentication Guard

- [ ] Create `ApiKeyGuard` — registered in `config/auth.php` as `api-key` guard, separate from Sanctum session guard
- [ ] Resolve `Authorization: Bearer sk_*` tokens: SHA-256 hash the provided token, look up by `token` column, verify not expired/revoked, set authenticated user, update `last_used_at`
- [ ] Middleware stack for GraphQL routes: `api-key` guard → rate limiter → query complexity checker → correlation ID tagger

### Permissions & Settings

- [ ] Add `API_KEYS_MANAGE` permission to `Permission` enum (for admin to manage any user's keys) and add to `categories()` under new 'API' category
- [ ] Add `graphql` group to `settings-schema.php`:
  - `enabled` (boolean, default false)
  - `max_keys_per_user` (integer, default 5)
  - `default_rate_limit` (integer, default 60 requests/min)
  - `introspection_enabled` (boolean, default false)
  - `max_query_depth` (integer, default 12)
  - `max_query_complexity` (integer, default 200)
  - `max_result_size` (integer, default 100, max items per list query)
  - `key_rotation_grace_days` (integer, default 7)
  - `cors_allowed_origins` (string, comma-separated, default `*`)

### Feature Flag Plumbing

- [x] Add `injectGraphQLConfig()` method to `ConfigServiceProvider` following the Stripe pattern, and wire it in `boot()` — **File:** `backend/app/Providers/ConfigServiceProvider.php`
- [x] Add `graphql_enabled` to the `features` array in `SystemSettingController::publicSettings()` — **File:** `backend/app/Http/Controllers/Api/SystemSettingController.php`
- [x] Removed `GRAPHQL_ENABLED` env var — `graphql.enabled` is now a DB-only toggle (set `env: null` in settings-schema.php). Admins enable/disable from Configuration > GraphQL API page
- [x] `GraphQLFeatureGate` middleware reads from `SettingService` instead of `config()` — **File:** `backend/app/Http/Middleware/GraphQLFeatureGate.php`
- [ ] Register `api-keys:prune-expired` in `ScheduledTaskService` command whitelist and in `routes/console.php` schedule

### Audit & Usage Integration

- [ ] Integrate with `AuditService` — log `api_key.created`, `api_key.revoked`, `api_key.rotated` actions with key_id and user_id context
- [ ] Integrate with `UsageTrackingService` — record API key requests as `integration: 'api', provider: 'graphql'` with user attribution

### Rate Limiting

- [ ] Per-key rate limiting via Laravel's `RateLimiter` — keyed by `api_key:{id}`, configurable per-key override or fall back to admin default
- [ ] Query complexity cost — use Lighthouse's built-in `@complexity` directive with admin-configurable thresholds. Expensive queries cost more against the rate limit budget

### Tests

- [ ] Key generation, hashing, prefix extraction
- [ ] Authentication guard — valid key, expired key, revoked key, rotated key within grace period, rotated key past grace period
- [ ] Rate limiting — under limit, at limit, over limit, per-key override
- [ ] CRUD operations — create, list (no plaintext leak), update, revoke, rotate
- [ ] Permission checks — user can only manage own keys, admin with `API_KEYS_MANAGE` can manage any

## Phase 2: API Key Management (Frontend)

### Frontend Feature Flag Plumbing

- [x] Add `graphqlEnabled?: boolean` to `AppConfigFeatures` interface and map from `features.graphql_enabled` — **File:** `frontend/lib/app-config.tsx`
- [x] Removed `featureFlag: "graphql"` from Configuration nav — GraphQL config page is always visible to admins with `settings.view` permission. The enable/disable switch on the page itself controls the feature — **File:** `frontend/app/(dashboard)/configuration/layout.tsx`

### Migrate Tokens to User Security

API keys are managed by each user in their Security page (User menu > Security), gated behind `graphqlEnabled`.

- [x] Remove the "API Tokens" tab from `/configuration/api/page.tsx` — keep only the Webhooks tab
- [x] Renamed the Configuration nav item from "API & Webhooks" to "Webhooks" — **File:** `frontend/app/(dashboard)/configuration/layout.tsx`
- [x] API keys section moved from Preferences to **User Security** page — **File:** `frontend/app/(dashboard)/user/security/page.tsx`
- [x] Gate visibility: read `graphqlEnabled` from `useAppConfig()` hook; hide API Keys card when disabled

### User Security Section

- [x] Add **"API Keys"** Card section to `/user/security` page (follows existing Card-based layout pattern)
- [x] Gate visibility: read `graphqlEnabled` from `useAppConfig()` hook; hide entire Card when disabled
- [x] Key list table inside Card — columns: name, key prefix (`sk_a1b2c3...`), created date, last used (relative time), expiration status badge (active/expired/revoked/expiring soon), actions column

### Create Key Dialog

- [ ] "Create API Key" button opens shadcn/ui `Dialog`
- [ ] Form fields: name input (required), optional expiration date picker
- [ ] On success: show full key in monospace font with copy-to-clipboard button (shadcn `CopyButton` pattern) and prominent warning banner: "This key will only be shown once. Copy it now."
- [ ] Dialog cannot be dismissed until user clicks "Done" (prevent accidental close before copying)

### Key Actions

- [ ] Revoke — shadcn/ui `AlertDialog` confirmation with key name displayed. On confirm, soft-delete via `DELETE` endpoint
- [ ] Rotate — shadcn/ui `AlertDialog` explaining old key stays valid for grace period. On confirm, call `POST .../rotate`, show new key in same one-time display dialog
- [ ] Copy prefix — click prefix text to copy to clipboard (for reference in logs)

### States & Feedback

- [ ] Empty state: icon + "API keys let you access Sourdough programmatically via the GraphQL API." with link to documentation and "Create your first key" CTA
- [ ] Loading state with skeleton rows
- [ ] Error state with retry
- [ ] Toast feedback for create/revoke/rotate actions

### In-App Help

- [ ] Add `HelpLink` in Card header linking to API Keys help article
- [ ] Add help article to `frontend/lib/help/help-content.ts`: what API keys are, how to use them, security best practices

## Phase 3: GraphQL Server (Backend)

### Dependencies & Configuration

- [ ] Add `nuwave/lighthouse` dependency via Composer
- [ ] Create `config/lighthouse.php` — route at `/graphql`, `api-key` guard, rate limiter middleware, correlation ID middleware. Lighthouse publishes its own config with route, middleware, guard, and security settings — no separate `config/graphql.php` needed
- [ ] Configure CORS per-path in `config/cors.php` — add `/graphql` path with cross-origin allowed for API key requests (separate from session CORS which is same-origin)
- [ ] Register GraphQL routes conditionally — return 404 when `graphql.enabled` is false

### Schema — Phase 3A (User-Facing)

- [ ] Create `backend/graphql/schema.graphql` with:
  - **Types**: `User`, `Notification`, `ApiKey`, `NotificationSetting`, `TypePreference`
  - **Queries**: `me`, `myNotifications(first: Int, page: Int, category: String, unreadOnly: Boolean)`, `myApiKeys`
  - **Mutations**: `updateProfile(input: UpdateProfileInput!)`, `markNotificationAsRead(id: ID!)`, `deleteNotifications(ids: [ID!]!)`, `updateNotificationSettings(input: NotificationSettingsInput!)`, `updateTypePreferences(input: TypePreferencesInput!)`
- [ ] All DateTime fields use ISO 8601 format with timezone (e.g., `2026-02-22T14:30:00Z`)

### Schema — Phase 3B (Admin Read-Only)

- [ ] Extend schema with admin types:
  - **Types**: `AuditLog`, `AccessLog`, `NotificationDelivery`, `Payment`, `UsageStat`, `UserGroup`, `UserAdmin`
  - **Queries**: `auditLogs(first: Int, page: Int, filters: AuditLogFilter)`, `accessLogs(...)`, `notificationDeliveries(...)`, `payments(...)`, `usageStats(dateFrom: Date, dateTo: Date, integration: String)`, `userGroups`, `users(first: Int, page: Int, search: String)`
- [ ] All admin queries protected by appropriate `@can` directives matching REST permissions

### Schema — Phase 3C (Admin Mutations)

Deferred to future release. See **Future Extensions** appendix at the bottom of this roadmap.

### Authorization

- [ ] Use Lighthouse `@can` directive on queries/mutations matching existing Gate permissions
- [ ] Field-level authorization via `@can` on sensitive fields:
  - `User.email` — visible to self (`@can(ability: "view", model: "User")`) or users with `users.view`
  - `Payment.stripe_customer_id` — only `payments.manage`
  - `NotificationDelivery.error` — only `notification_deliveries.view`
- [ ] Encrypted/sensitive model fields (passwords, tokens, secrets) — intentionally excluded from the GraphQL schema entirely (not defined in types). Document the full exclusion list in the ADR appendix
- [ ] PII fields — integrate with `AccessLogService` for HIPAA logging when PII fields are queried (email, phone, etc.)

### Query Security

- [ ] **Depth limiting** — configure `max_query_depth: 12` in Lighthouse config (admin-adjustable via settings)
- [ ] **Complexity scoring** — use Lighthouse's built-in `@complexity` directive with admin-configurable max threshold (default 200). Specific cost formulas deferred to implementation
- [ ] **Max result size** — all paginated queries enforce `first` param with max value from settings (default 100). Queries without pagination limited to 100 items
- [ ] **Persisted queries** — consider enabling for production (clients send query hash, server resolves from allow-list). Document as optional hardening step
- [ ] Log queries exceeding 80% of complexity threshold as warnings

### Pagination

- [ ] Standardize on **offset-based pagination** (`first`/`page` args) using Lighthouse's `@paginate` directive — simpler for API consumers, consistent with REST API patterns
- [ ] All list queries return `PaginatorInfo` type: `count`, `currentPage`, `lastPage`, `perPage`, `total`, `hasMorePages`
- [ ] Default page size: 25, max: value from `max_result_size` setting

### Filtering & Sorting

- [ ] Use Lighthouse `@orderBy` directive for sortable fields on admin queries
- [ ] Create `Input` filter types for admin queries (e.g., `AuditLogFilter { action: String, userId: ID, dateFrom: Date, dateTo: Date }`)
- [ ] User queries support basic filters (e.g., `myNotifications(category: String, unreadOnly: Boolean)`)

### Error Handling

- [ ] Use GraphQL-standard error format with `extensions.code` for machine-readable errors:
  - `UNAUTHENTICATED` — invalid/missing API key
  - `FORBIDDEN` — valid key but insufficient permissions
  - `RATE_LIMITED` — rate limit exceeded (include `retryAfter` in extensions)
  - `COMPLEXITY_EXCEEDED` — query too complex
  - `DEPTH_EXCEEDED` — query too deep
  - `VALIDATION_ERROR` — invalid input (include `field` and `message` in extensions)
  - `NOT_FOUND` — resource doesn't exist or not accessible
- [ ] Never leak stack traces or internal errors in production

### Usage & Audit Integration

- [ ] Record each GraphQL request via `UsageTrackingService` — `integration: 'api'`, `provider: 'graphql'`, `metric: 'request'`, with query name in metadata
- [ ] Record via `AuditService` — `api.query` or `api.mutation` action, with key_id, user_id, query name, complexity score
- [ ] Failed authorization attempts logged as `api.unauthorized` with field/query context
- [ ] GraphQL mutations must dispatch the same webhook events as their REST equivalents (e.g., `deleteNotification` mutation fires same event as REST `DELETE /notifications/{id}`)

### Tests

- [ ] Query resolution — each query returns expected data shape
- [ ] Authorization — field-level access denied for unauthorized users, admin queries blocked for non-admins
- [ ] Query depth — queries exceeding max depth rejected with `DEPTH_EXCEEDED` error
- [ ] Query complexity — queries exceeding max complexity rejected with `COMPLEXITY_EXCEEDED` error
- [ ] Pagination — default page size, max page size enforced, `PaginatorInfo` correct
- [ ] Rate limiting — requests counted, over-limit returns `RATE_LIMITED` with `retryAfter`
- [ ] Error format — all error types return correct `extensions.code`
- [ ] Introspection — disabled by default, enabled when setting is true
- [ ] Feature gate — all routes 404 when `graphql.enabled` is false

## Phase 4: GraphQL Server (Frontend — Admin)

### Configuration Page

- [x] Add **Configuration > GraphQL API** page at `/configuration/graphql` (admin, `settings.edit` permission)
- [x] Add to Configuration navigation under Integrations group with `settings.view` permission (no feature flag — always visible to admins) — **File:** `frontend/app/(dashboard)/configuration/layout.tsx`
- [x] Add search page entry for `/configuration/graphql` — **Files:** `backend/config/search-pages.php` and `frontend/lib/search-pages.ts`
- [x] Settings form (Card layout matching other config pages):
  - Enable/disable GraphQL toggle
  - Max API keys per user (number input)
  - Default rate limit (requests/minute, number input)
  - Allow introspection toggle (with helper text: "Enables schema exploration for developer tools. Disable in production for security.")
  - Max query depth (number input, default 12)
  - Max query complexity (number input, default 200)
  - Max result size per query (number input, default 100)
  - Key rotation grace period (days, number input, default 7)
  - CORS allowed origins (text input, comma-separated)

### API Key Admin Management

- [x] "API Keys" Card section — table of all keys across all users (admin with `API_KEYS_MANAGE` permission)
- [x] Columns: user, key name, prefix, created, last used, expiration, status (active/expired/revoked)
- [x] Filters: by user, by status, by expiration
- [x] Admin can revoke any key (with confirmation dialog)
- [x] Key metrics summary cards: total keys, active keys, keys expiring within 7 days, keys never used

### Usage Stats

- [x] API usage stats Card — sourced from `UsageTrackingService` filtered to `integration: 'api'`
- [x] Stats: total requests (7d/30d), requests per day chart (Recharts AreaChart), top 10 users by API calls, top 10 query names
- [x] Link to audit logs filtered by `api.query` / `api.mutation` actions

## Phase 5: Documentation & Developer Experience

### GraphQL Playground

- [ ] Use Lighthouse's built-in GraphiQL playground route (configurable in `config/lighthouse.php`) — no custom page needed
- [ ] Gate behind the `introspection_enabled` setting; Lighthouse returns helpful error when disabled
- [ ] Configure default headers to include `Authorization: Bearer` field for easy key entry

### In-App Help

- [ ] Add help article in `frontend/lib/help/help-content.ts`: "GraphQL API" covering what it is, how to enable, how to create keys, example queries
- [ ] Add help article: "API Key Security" covering best practices:
  - Never commit keys to version control
  - Rotate keys regularly
  - Use expiration dates
  - Use scoped permissions when available
  - Monitor usage in audit logs
  - Revoke unused keys

### User Documentation

- [ ] Add API key section to `docs/user/README.md`
- [ ] Document authentication (Bearer header), rate limits, error codes
- [ ] Include code examples for common queries (curl, JavaScript fetch, Python requests):
  - Fetch my profile
  - List my notifications
  - List my API keys
  - Mark notification as read
  - Admin: query audit logs with filters and pagination

### AI Development Docs

**Recipes** (step-by-step guides for common tasks):

- [ ] `docs/ai/recipes/add-graphql-type.md` — Adding a new GraphQL type: define type in schema, create resolver, add `@can` directives, exclude sensitive fields, add pagination, write tests. Follows pattern of `add-api-endpoint.md`
- [ ] `docs/ai/recipes/add-graphql-mutation.md` — Adding a new mutation: define input type, create mutation resolver, add validation, add authorization, add audit logging, write tests
- [ ] `docs/ai/recipes/add-graphql-query-filter.md` — Adding filters/sorting to an existing query: define filter input type, add `@orderBy`, wire to Eloquent scopes
- [ ] `docs/ai/recipes/expose-model-via-graphql.md` — End-to-end guide for exposing an existing Eloquent model via GraphQL: type definition, field-level auth, PII/encrypted field handling, pagination, filtering, tests. Checklist format covering all the steps a developer should not skip

**Patterns** (reference docs for established conventions):

- [ ] `docs/ai/patterns/graphql-auth.md` — How the `api-key` guard works, field-level authorization with `@can`, PII access logging, encrypted field exclusion strategy
- [ ] `docs/ai/patterns/graphql-schema.md` — Schema conventions: naming (camelCase fields, PascalCase types), pagination shape (`PaginatorInfo`), filter input types, DateTime format (ISO 8601), error extensions format
- [ ] `docs/ai/patterns/graphql-security.md` — Query depth limiting, complexity scoring with `@complexity`, max result sizes, rate limiting strategy, CORS policy, introspection controls

**Anti-patterns** (common mistakes to avoid — add to `docs/ai/patterns/graphql-schema.md`):

- [ ] Document anti-patterns in the schema pattern file:
  - Exposing encrypted fields (passwords, API keys, tokens) — exclude from schema entirely
  - Returning full key/token values — only return prefix
  - Unbounded list queries without pagination — always use `@paginate`
  - Missing `@can` on admin-only fields — every sensitive field needs explicit auth
  - Nested relations without `@complexity` cost — causes N+1 and DoS
  - Hardcoding filter values instead of using input types
  - Adding mutations without audit logging — every write must log via `AuditService`
  - Skipping tests for authorization — every `@can` directive needs a negative test case

### AI & Codebase Documentation Updates

- [ ] Add "GraphQL / API Key Work" section to `docs/ai/context-loading.md` listing all relevant files
- [ ] Add GraphQL row to the Quick Context Loading table in `docs/ai/README.md`
- [ ] Add GraphQL API entry to `docs/features.md`

### Architecture Decision Record

- [ ] Add ADR for GraphQL API architecture decisions:
  - Why Lighthouse PHP (Laravel-native, schema-first, Gate integration)
  - Why extend `ApiToken` model instead of new table
  - Why SHA-256 hashing (not bcrypt) for bearer token lookup
  - Why Sanctum `HasApiTokens` and custom `ApiToken` coexist (session auth vs API keys)
  - Why offset pagination over cursor-based (simplicity for API consumers)
  - Why separate auth guard from Sanctum
  - Why use Lighthouse's built-in playground (not custom page)
  - Query security strategy (depth + complexity + result size)
  - CORS per-path configuration rationale
  - Appendix: full list of model fields excluded from GraphQL schema with reasons

### Schema Versioning Strategy

- [ ] Document approach: **no versioning** — use GraphQL's built-in `@deprecated` directive for fields being phased out. Breaking changes (field removal) require a deprecation period of at least 2 minor versions
- [ ] Add schema changelog section to ADR — track breaking changes with dates and migration guidance

## Phase Parallelism

Some phases can be worked on concurrently:

- **Phases 2 + 3A** — frontend key management and GraphQL server user-facing schema have no dependencies on each other (both depend on Phase 1)
- **Phase 4 config form** — can start after Phase 1 (settings schema exists); usage stats and key admin table need Phase 3
- **Phase 5 docs** — can start incrementally alongside any phase

## Key Files

| Purpose | Path |
|---------|------|
| API Token model (extended) | `backend/app/Models/ApiToken.php` |
| API Key service | `backend/app/Services/ApiKeyService.php` |
| API Key controller | `backend/app/Http/Controllers/Api/ApiKeyController.php` |
| API Key guard | `backend/app/Auth/ApiKeyGuard.php` |
| API Token migration | `backend/database/migrations/YYYY_MM_DD_add_graphql_columns_to_api_tokens_table.php` |
| GraphQL schema | `backend/graphql/schema.graphql` |
| Lighthouse config | `backend/config/lighthouse.php` |
| CORS config | `backend/config/cors.php` (add `/graphql` path) |
| Settings schema | `backend/config/settings-schema.php` (graphql group) |
| Config injection | `backend/app/Providers/ConfigServiceProvider.php` (injectGraphQLConfig) |
| Public features | `backend/app/Http/Controllers/Api/SystemSettingController.php` (graphql_enabled) |
| Feature gate middleware | `backend/app/Http/Middleware/GraphQLFeatureGate.php` (reads from SettingService) |
| Permission enum | `backend/app/Enums/Permission.php` |
| Frontend app config | `frontend/lib/app-config.tsx` (graphqlEnabled) |
| Frontend user API keys | `frontend/app/(dashboard)/user/security/page.tsx` |
| Configuration layout | `frontend/app/(dashboard)/configuration/layout.tsx` |
| Admin config page | `frontend/app/(dashboard)/configuration/graphql/page.tsx` |
| Help content | `frontend/lib/help/help-content.ts` |
| Search pages | `backend/config/search-pages.php`, `frontend/lib/search-pages.ts` |
| ADR | `docs/adr/0XX-graphql-api-architecture.md` |
| AI recipe | `docs/ai/recipes/add-graphql-type.md` |

## Future Extensions

### Phase 3C — Admin Read + Write Mutations

Lower priority. Implement after Phases 3A/3B are stable and battle-tested.

| Type | Source Model | Mutations | Auth |
|------|-------------|-----------|------|
| `NotificationTemplate` | `NotificationTemplate` | `updateTemplate`, `resetTemplate` | `settings.edit` |
| `EmailTemplate` | `EmailTemplate` | `updateEmailTemplate` | `settings.edit` |
| `SystemSetting` | `Setting` / `SystemSetting` | `updateSettings` | `settings.edit` |
| `AIProvider` | `AIProvider` | `updateProvider` | `settings.edit` |
| `Webhook` | `Webhook` | `createWebhook`, `updateWebhook`, `deleteWebhook` | `settings.edit` |
| `Backup` | (via BackupService) | `createBackup`, `restoreBackup` | `backups.create` / `backups.restore` |

### API Key Security Notifications

Consider adding notification templates for security-relevant API key events (`api_key.created`, `api_key.revoked`) using the existing `NotificationTemplate` system. Good security practice for alerting users when keys are created or compromised.

### API Usage Budget

Add `budget_api` to the `usage` group in `settings-schema.php` when API usage volume warrants it. Avoids premature UI complexity.
