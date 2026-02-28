# Audit Logs & Logging Improvements Roadmap

Improve the audit logs page UI/UX and enhance general console/application logging throughout the system.

**Implementation plan:** [audit-logs-implementation-plan.md](audit-logs-implementation-plan.md)

**Priority**: MEDIUM  
**Status**: Completed (2026-01-29)  
**Last Updated**: 2026-01-29

**Dependencies**:
- None

---

## Task Checklist

### Audit Logs Page Improvements (HIGH Priority)
- [x] Review current audit logs page and identify UX issues
- [x] Add filtering options (by user, action type, date range, resource)
- [x] Add search functionality for log entries
- [x] Improve log entry detail view (expandable rows or modal)
- [x] Add pagination with configurable page size
- [x] Add export functionality (CSV, JSON)
- [x] Add real-time log streaming option
- [x] Improve loading states and empty states
- [x] Add log severity indicators (info, warning, error, critical)

### Console/Application Logging (MEDIUM Priority)
- [x] Audit current logging practices across backend
- [x] Implement structured logging format (JSON)
- [x] Add correlation IDs for request tracing
- [x] Configure log levels per environment (debug, info, warn, error)
- [x] Add contextual information to logs (user ID, request ID, etc.)
- [x] Implement log rotation and retention policies
- [x] Add frontend error logging/reporting
- [x] Document logging standards and best practices

### Log Storage & Performance (MEDIUM Priority)
- [x] Evaluate log storage options (file, database, external service) — see [Log Storage Evaluation](log-storage-evaluation.md)
- [ ] Implement log archival for old entries
- [x] Add indexes for common query patterns (severity+created_at on audit_logs; action+created_at on access_logs)
- [ ] Consider log aggregation service integration (optional)

### Dashboard & Analytics (LOW Priority)
- [x] Add audit log summary to admin dashboard
- [x] Create charts for activity trends
- [x] Add alerting for suspicious activity patterns — log:check-suspicious (every 15 min), in-app/email to admins, dashboard banner; GET /api/suspicious-activity

---

## Current State

**Audit Logs Page**: Implemented (2026-01-29): user/action/severity/date filters, search, severity badges, detail modal, CSV export with severity. AuditService integrated across auth, user management, settings, and backup. See [implementation plan](audit-logs-implementation-plan.md) and [journal entry](../journal/2026-01-29-audit-logging-implementation.md).

**Dashboard & Analytics (Phase 2, 2026-01-29)**: Audit summary widget on admin dashboard with stats cards (total actions, warnings/errors), severity donut chart, activity trends area chart, and recent warnings list. Stats API extended with `daily_trends` and `recent_warnings`. See [journal entry](../journal/2026-01-29-audit-dashboard-analytics.md).

**Application Logging (2026-01-29)**: Structured JSON logging channel; AddCorrelationId middleware (X-Correlation-ID request/response); ContextProcessor adds correlation_id, user_id, ip_address, request_uri to all logs. Use LOG_STACK=single,json for JSON output. See [journal entry](../journal/2026-01-29-audit-extended-features.md).

**Console/App Logging (2026-01-29)**: Backend logging added to LLMOrchestrator, EmailChannel, BackupService, ScheduledBackup, AuthController. Per-environment LOG_LEVEL and LOG_DAILY_DAYS in .env.example; daily channel has context tap. Frontend: errorLogger (frontend/lib/error-logger.ts), ErrorBoundary, ErrorHandlerSetup, POST /api/client-errors. Ad-hoc console.* replaced with errorLogger. docs/logging.md, extend-logging recipe, patterns and context-loading updated; errorLogger in global-components.

**Real-time Audit Streaming (2026-01-29)**: AuditLogCreated event broadcasts on private `audit-logs` channel (admin-only); audit page has Live toggle and connection status; new logs prepend with highlight. Requires Reverb (BROADCAST_CONNECTION=reverb). See same journal entry.

---

## Key Files

- `frontend/app/(dashboard)/configuration/audit/page.tsx` - Audit logs page
- `frontend/app/(dashboard)/dashboard/page.tsx` - Dashboard (audit widget for admins)
- `frontend/components/audit/audit-dashboard-widget.tsx` - Audit summary widget
- `frontend/components/audit/audit-stats-card.tsx` - Stat card component
- `frontend/components/audit/audit-severity-chart.tsx` - Severity donut chart
- `frontend/components/audit/audit-trends-chart.tsx` - Activity trends area chart
- `frontend/components/ui/chart.tsx` - shadcn chart (Recharts)
- `backend/app/Services/AuditService.php` - Audit logging service
- `backend/app/Http/Traits/AuditLogging.php` - Controller trait
- `backend/app/Http/Controllers/Api/AuditLogController.php` - API (index, export, stats)
- `backend/app/Models/AuditLog.php` - Model
- `backend/app/Http/Middleware/AddCorrelationId.php` - Correlation ID middleware
- `backend/app/Events/AuditLogCreated.php` - Broadcast event for real-time audit stream
- `backend/app/Logging/ContextProcessor.php` - Log context (correlation_id, user_id, etc.)
- `backend/app/Logging/AddContextProcessorTap.php` - Tap to add processor to channels
- `frontend/lib/use-audit-stream.ts` - Hook for subscribing to audit-logs channel
- `backend/config/logging.php` - Laravel logging configuration (json channel, tap)

---

## Related Roadmaps

- [Security Compliance Review](security-compliance-roadmap.md) - Depends on audit logging for compliance
- [Admin Features](admin-features-roadmap.md) - Admin dashboard integration
