# Integration Usage Dashboard

**Date:** 2026-02-14
**Status:** Complete (all 4 phases)

## Summary

Implemented a unified Integration Usage Dashboard to track, aggregate, and visualize costs across all paid integrations (LLM, Email, SMS, Storage, Broadcasting).

## What Changed

### Phase 1: Backend Usage Tracking
- Created `integration_usage` table with migration
- Created `IntegrationUsage` model with scopes (byIntegration, byProvider, byDateRange, byUser)
- Created `UsageTrackingService` with record() and convenience methods (recordLLM, recordEmail, recordSMS, recordStorage, recordBroadcast)
- Instrumented `LLMOrchestrator.logRequest()` to record token usage after each successful LLM call
- Instrumented `EmailChannel.send()` to record email sends
- Instrumented `TwilioChannel`, `VonageChannel`, `SNSChannel` to record SMS sends with country metadata
- Instrumented `StorageService.uploadFile()` and `downloadFile()` for cloud providers only

### Phase 2: Usage Stats API
- Added `USAGE_VIEW` permission to Permission enum
- Added `usage` settings schema group (LLM pricing, per-integration budgets, alert threshold)
- Created `UsageStatsService` with getStats(), getBreakdown(), export() methods
- Created `UsageController` with stats, breakdown, and CSV export endpoints
- Added `/api/usage/stats`, `/api/usage/breakdown`, `/api/usage/export` routes

### Phase 3: Frontend Dashboard
- Created Configuration > Usage & Costs page at `/configuration/usage`
- Built `UsageCostChart` component (stacked area chart using Recharts + shadcn chart wrapper)
- Built `UsageProviderTable` component (sortable table with totals row)
- Date range selector with preset buttons (7d, 30d, 90d, this/last month)
- Integration filter toggles for chart/stat card visibility
- Summary stat cards for Total Cost and per-integration costs
- Empty state for when no usage data exists
- Added "Usage & Costs" nav item to Logs & Monitoring group
- Added search page entries (frontend fallback + backend Meilisearch)

### Phase 4: Cost Alerts & Export
- Created `UsageAlertService` with daily budget check
- Created `CheckUsageBudgetsCommand` artisan command (`usage:check-budgets`)
- Added daily scheduled task for budget alerts
- CSV export button integrated into the dashboard page
- Per-user breakdown support in the breakdown API endpoint
- Created `UsageDashboardWidget` with cost summary cards and full-width area chart with time range toggles (7d/14d/30d)
- Added widget to admin dashboard

## Files Created
- `backend/database/migrations/2026_02_15_000001_create_integration_usage_table.php`
- `backend/app/Models/IntegrationUsage.php`
- `backend/app/Services/UsageTrackingService.php`
- `backend/app/Services/UsageStatsService.php`
- `backend/app/Services/UsageAlertService.php`
- `backend/app/Http/Controllers/Api/UsageController.php`
- `backend/app/Console/Commands/CheckUsageBudgetsCommand.php`
- `frontend/app/(dashboard)/configuration/usage/page.tsx`
- `frontend/components/usage/usage-cost-chart.tsx`
- `frontend/components/usage/usage-provider-table.tsx`
- `frontend/components/usage/usage-dashboard-widget.tsx`

## Files Modified
- `backend/app/Enums/Permission.php` - Added USAGE_VIEW
- `backend/config/settings-schema.php` - Added usage group
- `backend/routes/api.php` - Added usage route group
- `backend/routes/console.php` - Added budget check schedule
- `backend/app/Services/LLM/LLMOrchestrator.php` - Instrumented
- `backend/app/Services/Notifications/Channels/EmailChannel.php` - Instrumented
- `backend/app/Services/Notifications/Channels/TwilioChannel.php` - Instrumented
- `backend/app/Services/Notifications/Channels/VonageChannel.php` - Instrumented
- `backend/app/Services/Notifications/Channels/SNSChannel.php` - Instrumented
- `backend/app/Services/StorageService.php` - Instrumented
- `frontend/app/(dashboard)/configuration/layout.tsx` - Added nav item + permission
- `frontend/lib/search-pages.ts` - Added search entry
- `backend/config/search-pages.php` - Added search entry
- `frontend/app/(dashboard)/dashboard/page.tsx` - Added usage widget
- `docs/features.md` - Documented feature
- `docs/roadmaps.md` - Moved to completed

## Code Review Fixes
- Fixed SQLite-specific `strftime()` in `UsageStatsService.getDateFormat()` -- now supports MySQL and PostgreSQL
- Fixed `metadata->model` JSON path for LLM breakdown -- uses proper `json_extract()` / `JSON_UNQUOTE(JSON_EXTRACT())` / `->>'model'` per database driver
- Added LLM cost estimation from `pricing_llm` settings with default rates for common models (GPT-4, Claude, Gemini)
- Changed CSV export from loading all records into memory to chunked streaming
- Aligned dashboard widget permission check with `usage.view` instead of just `isAdmin`
- Extracted duplicated `formatCurrency` to shared `frontend/lib/utils.ts`

## Design Decisions
- Unified `integration_usage` table rather than querying `ai_request_logs` directly, for consistency across all integration types
- Usage tracking uses fire-and-forget with try/catch to never disrupt primary operations
- Broadcasting instrumentation is lower priority and uses a simple event listener approach (deferred)
- LLM cost is split proportionally between input/output token records
- Storage tracking only applies to cloud providers (local disk is free)
- Budget alerts use the existing notification orchestrator to reach admins via their configured channels
- Default LLM pricing rates built-in for common models; overridable via `pricing_llm` setting
