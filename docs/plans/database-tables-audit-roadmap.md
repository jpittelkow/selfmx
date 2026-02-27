# Database Tables Audit Roadmap

**Priority:** MEDIUM
**Dependencies:** None
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

- [ ] List all migration files and their creation order
- [ ] Document each table: name, purpose, columns, relationships
- [ ] Identify which models correspond to which tables
- [ ] Check for tables that exist in migrations but have no corresponding model
- [ ] Check for models that reference tables not created by migrations
- [ ] Verify `user_id` scoping is applied where needed (per project conventions)

## Phase 2: Migration Health

Verify migrations are clean and correct.

- [ ] Run full migration from scratch (`migrate:fresh`) and verify no errors
- [ ] Check for duplicate or conflicting migrations
- [ ] Verify all `down()` methods work correctly (rollback test)
- [ ] Look for raw SQL that may break cross-database compatibility
- [ ] Check migration naming conventions are consistent
- [ ] Verify no migrations modify the same column redundantly

## Phase 3: Index Audit

Review indexes for performance and correctness.

- [ ] Identify frequently queried columns (from controllers/services)
- [ ] Verify indexes exist on foreign key columns
- [ ] Check for missing indexes on columns used in `WHERE`, `ORDER BY`, and `JOIN` clauses
- [ ] Identify unused or redundant indexes
- [ ] Review composite indexes for correct column order
- [ ] Verify unique constraints are applied where business logic requires them

## Phase 4: Relationships & Constraints

Audit foreign keys and data integrity.

- [ ] Verify all Eloquent relationships have corresponding foreign keys in migrations
- [ ] Check `onDelete` cascade/restrict behavior is appropriate for each relationship
- [ ] Identify any orphan records possible due to missing constraints
- [ ] Verify nullable columns are intentionally nullable
- [ ] Check default values are appropriate

## Phase 5: Column Types & Data Integrity

Review column definitions for correctness.

- [ ] Verify string column lengths are appropriate (not too short, not wasteful)
- [ ] Check that boolean columns use `boolean` type (not `tinyInteger`)
- [ ] Verify JSON/array columns work correctly on SQLite (per project gotcha)
- [ ] Check timestamp columns use consistent format
- [ ] Verify enum/status columns have appropriate allowed values
- [ ] Review `text` vs `string` usage for appropriate content sizes

## Phase 6: Cleanup & Optimization

Address issues found during audit.

- [ ] Remove any orphaned or unused tables
- [ ] Add missing indexes identified in Phase 3
- [ ] Add missing foreign key constraints identified in Phase 4
- [ ] Fix any column type issues identified in Phase 5
- [ ] Create a single migration for all cleanup changes
- [ ] Verify cleanup migration works on all supported databases

## Phase 7: Documentation

Document the schema for ongoing maintenance.

- [ ] Create or update a database schema reference document
- [ ] Document any non-obvious design decisions (why certain tables exist, etc.)
- [ ] Note any known limitations or technical debt
- [ ] Add schema diagram if practical

## Files to Audit

| Category | Location |
|----------|----------|
| Migrations | `backend/database/migrations/` |
| Models | `backend/app/Models/` |
| Seeders | `backend/database/seeders/` |
| Factories | `backend/database/factories/` |
| Controllers | `backend/app/Http/Controllers/Api/` |
| Services | `backend/app/Services/` |

## Success Criteria

- All migrations run cleanly from scratch on SQLite, MySQL, and PostgreSQL
- Every table has a clear purpose and corresponding model
- Indexes cover all common query patterns
- Foreign keys enforce data integrity
- No orphaned or redundant tables remain
- Schema is documented for future reference
