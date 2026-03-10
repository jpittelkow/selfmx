# ADR-029: Usage Tracking & Budget Alerts

## Status

Accepted

## Date

2026-03-04

## Context

The application integrates with multiple paid external services (LLM providers, email, SMS, storage, broadcasting, payments). Operators need visibility into usage costs and the ability to set budget thresholds with automated alerts.

## Decision

Implement a three-service usage tracking architecture:

### Services

1. **`UsageTrackingService`** — Records individual usage events. Provides typed helper methods for each integration type (`recordLLM`, `recordEmail`, `recordSMS`, `recordStorage`, `recordBroadcast`, `recordPayment`). Includes LLM cost estimation from configurable pricing or built-in defaults.

2. **`UsageStatsService`** — Aggregates usage data for dashboards and reporting. Supports filtering by date range, integration, provider, and grouping by day/week/month. Includes per-user and per-model breakdowns, CSV export with chunked streaming, and API-specific stats for GraphQL admin.

3. **`UsageAlertService`** — Checks monthly budgets against configurable thresholds and notifies admins. Runs on a schedule via `ScheduledTaskService`. Sends notifications via `NotificationOrchestrator` when usage reaches the alert threshold (default 80%) or exceeds 100%.

### Data Model

`IntegrationUsage` model with fields: `integration`, `provider`, `metric`, `quantity`, `estimated_cost`, `metadata` (JSON), `user_id` (nullable). Scoped query methods: `byIntegration()`, `byProvider()`, `byDateRange()`, `byUser()`.

Integration types: `llm`, `email`, `sms`, `storage`, `broadcasting`, `payments`.

### Settings (via SettingService)

| Setting | Purpose |
|---------|---------|
| `usage.alert_threshold` | Percentage to trigger warning (default 80%) |
| `usage.budget_llm` | Monthly budget for LLM |
| `usage.budget_email` | Monthly budget for email |
| `usage.budget_sms` | Monthly budget for SMS |
| `usage.budget_storage` | Monthly budget for storage |
| `usage.budget_broadcasting` | Monthly budget for broadcasting |
| `usage.budget_payments` | Monthly budget for payments |
| `usage.pricing_llm` | JSON pricing table for LLM cost estimation |

### API Endpoints

| Method | Endpoint | Purpose |
|--------|----------|---------|
| `GET` | `/api/usage/stats` | Aggregated stats with filters |
| `GET` | `/api/usage/breakdown` | Single-integration breakdown |
| `GET` | `/api/usage/export` | CSV download (streamed) |

### LLM Cost Estimation

`UsageTrackingService::recordLLM()` estimates costs when not provided:
1. Check `usage.pricing_llm` settings for exact or partial model name match
2. Fall back to built-in defaults for common models (GPT-4o, Claude 3, Gemini)
3. Return `null` if no pricing found

### Database Compatibility

Stats queries use driver-aware SQL (`SQLite`, `MySQL`, `PostgreSQL`) for date formatting, JSON extraction, and grouping.

## Consequences

### Positive

- Unified cost tracking across all integrations
- Proactive budget alerts prevent surprise bills
- CSV export enables external reporting
- LLM cost estimation works out-of-box with sensible defaults
- Database-agnostic queries

### Negative

- Usage recording adds a database write per tracked event (mitigated by try/catch + Log::warning on failure)
- No batching/queueing of usage records — each event is recorded immediately

### Neutral

- Budget checks run on schedule, not in real-time
- Alert notifications use the existing notification system (NotificationOrchestrator)
- Only admin users receive budget alerts

## Related Decisions

- [ADR-005](./005-notification-system-architecture.md) — alerts sent via NotificationOrchestrator
- [ADR-014](./014-database-settings-env-fallback.md) — budget settings stored via SettingService
- [ADR-006](./006-llm-orchestration-modes.md) — LLM usage recorded after orchestration

## Notes

- Key files: `backend/app/Services/UsageTrackingService.php`, `backend/app/Services/UsageStatsService.php`, `backend/app/Services/UsageAlertService.php`, `backend/app/Http/Controllers/Api/UsageController.php`
- `IntegrationUsage::INTEGRATIONS` defines the canonical list of integration types

## Implementation Journal

- [Integration Usage Dashboard (2026-02-14)](../journal/2026-02-14-integration-usage-dashboard.md)
