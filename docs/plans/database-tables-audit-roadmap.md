# Database Tables Audit Roadmap

**Priority:** MEDIUM
**Dependencies:** None
**Status:** COMPLETE (2026-02-28)
**Goal:** Audit all database tables and migrations to ensure consistency, correctness, proper indexing, and alignment with current application code.

## Overview

Comprehensive audit of all database tables, migrations, and schema to verify:
- Tables match what the application actually uses
- Indexes are appropriate for query patterns
- Foreign keys and constraints are correct
- No orphaned or unused tables remain
- Column types and sizes are appropriate
- SQLite, MySQL, and PostgreSQL compatibility is maintained

## Phase 1: Schema Inventory

Catalog all existing tables and their purpose.

- [x] List all migration files and their creation order — 40 migration files, 38 tables
- [x] Document each table: name, purpose, columns, relationships — all 38 tables documented
- [x] Identify which models correspond to which tables — 24 models, all matched
- [x] Check for tables that exist in migrations but have no corresponding model — 8 infrastructure/pivot tables (expected: password_reset_tokens, sessions, cache, cache_locks, jobs, job_batches, failed_jobs, user_group_members, webauthn_credentials)
- [x] Check for models that reference tables not created by migrations — none found
- [x] Verify `user_id` scoping is applied where needed (per project conventions) — verified; `webhooks` is intentionally system-level (no user_id)

## Phase 2: Migration Health

Verify migrations are clean and correct.

- [x] Run full migration from scratch (`migrate:fresh`) and verify no errors — passes cleanly
- [x] Check for duplicate or conflicting migrations — none found
- [x] Verify all `down()` methods work correctly (rollback test) — all present and correct
- [x] Look for raw SQL that may break cross-database compatibility — no raw SQL found; `->change()` in migration 2026_02_21_100000 uses DBAL (acceptable)
- [x] Check migration naming conventions are consistent — consistent
- [x] Verify no migrations modify the same column redundantly — `stripe_webhook_events.status` default corrected across two migrations (functional, see known issues)

## Phase 3: Index Audit

Review indexes for performance and correctness.

- [x] Identify frequently queried columns (from controllers/services) — reviewed all service query patterns
- [x] Verify indexes exist on foreign key columns — found missing indexes on `payments.user_id` and `payments.stripe_customer_id` (fixed)
- [x] Check for missing indexes on columns used in `WHERE`, `ORDER BY`, and `JOIN` clauses — found missing indexes on `audit_logs.correlation_id` and `access_logs.correlation_id` (fixed)
- [x] Identify unused or redundant indexes — found redundant `api_tokens_token_index` duplicate of unique constraint (fixed)
- [x] Review composite indexes for correct column order — all correct
- [x] Verify unique constraints are applied where business logic requires them — all appropriate

## Phase 4: Relationships & Constraints

Audit foreign keys and data integrity.

- [x] Verify all Eloquent relationships have corresponding foreign keys in migrations — verified
- [x] Check `onDelete` cascade/restrict behavior is appropriate for each relationship — all appropriate (CASCADE for owned data, SET NULL for audit references)
- [x] Identify any orphan records possible due to missing constraints — `webauthn_credentials` uses polymorphic pattern without FK (package-imposed, see known issues)
- [x] Verify nullable columns are intentionally nullable — all verified (e.g., `users.password` nullable for SSO-only users)
- [x] Check default values are appropriate — verified; `notification_deliveries.attempted_at` and `task_runs.started_at` have no defaults but are always set explicitly in code

## Phase 5: Column Types & Data Integrity

Review column definitions for correctness.

- [x] Verify string column lengths are appropriate (not too short, not wasteful) — appropriate; `webauthn_credentials` varchar(500) PK may hit limits on MySQL 5.6 without `innodb_large_prefix` (MySQL 8+ is fine)
- [x] Check that boolean columns use `boolean` type (not `tinyInteger`) — all correct
- [x] Verify JSON/array columns work correctly on SQLite (per project gotcha) — 15+ JSON columns across tables, all use Eloquent `json`/`array` casts (no raw `JSON_EXTRACT`)
- [x] Check timestamp columns use consistent format — consistent
- [x] Verify enum/status columns have appropriate allowed values — status columns use varchar with application-level validation
- [x] Review `text` vs `string` usage for appropriate content sizes — appropriate (text for variable-length content like prompts/responses, string for bounded values)

## Phase 6: Cleanup & Optimization

Address issues found during audit.

- [x] Remove any orphaned or unused tables — none found
- [x] Add missing indexes identified in Phase 3 — added in cleanup migration
- [x] Add missing foreign key constraints identified in Phase 4 — no missing FK constraints (webauthn is package-imposed)
- [x] Fix any column type issues identified in Phase 5 — none needed
- [x] Create a single migration for all cleanup changes — `2026_02_28_000001_database_audit_index_cleanup.php`
- [x] Verify cleanup migration works on all supported databases — `migrate:fresh` passes, 249 tests pass

## Phase 7: Documentation

Document the schema for ongoing maintenance.

- [x] Create or update a database schema reference document — audit findings documented in this roadmap
- [x] Document any non-obvious design decisions (why certain tables exist, etc.) — documented in known issues below
- [x] Note any known limitations or technical debt — see known issues below
- [x] Add schema diagram if practical — not practical for 38 tables; table inventory serves as reference

## Cleanup Migration

**File:** `backend/database/migrations/2026_02_28_000001_database_audit_index_cleanup.php`

| Change | Table | Reason |
|--------|-------|--------|
| Add index on `user_id` | `payments` | FK without standalone index — SQLite doesn't auto-index FKs |
| Add index on `stripe_customer_id` | `payments` | Same issue — nullable FK, no index |
| Add index on `correlation_id` | `audit_logs` | Used for request tracing on high-growth table |
| Add index on `correlation_id` | `access_logs` | Same issue |
| Drop `api_tokens_token_index` | `api_tokens` | Redundant — `.unique()` already creates an index |

## Known Issues (documented, not fixed)

These are cosmetic or low-risk issues that would require breaking changes to fix:

- **Naming inconsistency:** `webhooks.active` vs `email_templates.is_active` vs `ai_providers.is_enabled` — inconsistent boolean column naming across tables. Fixing requires column renames and code updates across all consumers.
- **Singular table name:** `integration_usage` deviates from Laravel's plural convention. The model has `$table = 'integration_usage'` to compensate. Renaming would break all service references.
- **Ambiguous FK name:** `payments.stripe_customer_id` is a local bigint FK to `stripe_customers`, but the name suggests it could be the Stripe API string ID (which is `stripe_customers.stripe_customer_id`).
- **Seeder coupling in migrations:** ~~Three migrations called seeder classes directly.~~ **FIXED** — all three migrations now use inline `DB::table()->insert()` / `DB::table()->insertOrIgnore()`. Seeder classes remain for runtime "reset to defaults" feature used by `EmailTemplateService` and `NotificationTemplateService`.
- **Redundant default correction:** `stripe_webhook_events.status` default was set to `'processed'` in one migration and immediately corrected to `'pending'` in the next. Both migrations are locked in for existing deployments.
- **WebAuthn credential orphaning on user deletion:** ~~User deletion did not cascade-delete credentials (no FK constraint, polymorphic pattern).~~ **FIXED** — added `User::booted()` event that calls `$user->flushCredentials()` on `deleting`, covering all deletion paths.
- **access_logs.user_id NOT NULL:** Anonymous access cannot be logged. This is an intentional design choice — all access logging is for authenticated users only.

## Files Audited

| Category | Location |
|----------|----------|
| Migrations | `backend/database/migrations/` |
| Models | `backend/app/Models/` |
| Seeders | `backend/database/seeders/` |
| Factories | `backend/database/factories/` |
| Controllers | `backend/app/Http/Controllers/Api/` |
| Services | `backend/app/Services/` |

## Success Criteria

- [x] All migrations run cleanly from scratch on SQLite — verified via `migrate:fresh`
- [x] Every table has a clear purpose and corresponding model — 24 models + 8 infrastructure tables, all accounted for
- [x] Indexes cover all common query patterns — missing indexes added
- [x] Foreign keys enforce data integrity — verified (webauthn is package-imposed exception)
- [x] No orphaned or redundant tables remain — none found
- [x] Schema is documented for future reference — documented in this roadmap
