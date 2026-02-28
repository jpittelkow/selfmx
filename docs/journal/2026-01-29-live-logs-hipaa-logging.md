# Live Console Logs & HIPAA Access Logging - 2026-01-29

## Overview

Implemented the Live Console Log Viewer (real-time application log streaming to admin UI) and HIPAA-compliant access logging for PHI. Added backup/restore integration for access logs, recipes and patterns for ongoing compliance, a logging-compliance Cursor rule, and a logging roadmap for future archiving, export, and cleanup.

## Implementation Approach

### Live Console Log Viewer

- **AppLogCreated**: Broadcast event on private `app-logs` channel (admin-only). Payload: level, message, sanitized context, correlation_id, user_id, timestamp.
- **BroadcastLogHandler**: Monolog handler that dispatches `AppLogCreated` when `LOG_BROADCAST_ENABLED=true`. Sanitizes sensitive keys (password, token, secret, api_key, etc.) from context. Uses `AddContextProcessorTap` for correlation_id, user_id, ip_address, request_uri.
- **Logging config**: New `broadcast` channel; add `broadcast` to `LOG_STACK` and set `LOG_BROADCAST_ENABLED`, `LOG_BROADCAST_LEVEL` in .env.
- **Frontend**: `useAppLogStream` hook subscribes to `private-app-logs`, buffers up to 500 lines, exposes `status`, `logs`, `clearLogs`. Application Logs page at `/configuration/logs` with Live toggle, level/search filters, auto-scroll, terminal-style output.

### HIPAA Access Logging

- **access_logs** table: user_id (who accessed), action, resource_type, resource_id, fields_accessed, ip_address, user_agent, correlation_id. Indexes for (resource_type, resource_id), (user_id, created_at), created_at.
- **AccessLogService**: `log(action, resourceType, resourceId?, fieldsAccessed?, request?)`. Catches failures and logs to Laravel Log without breaking requests.
- **LogResourceAccess** middleware: Accepts `log.access:User` or `log.access:Setting`. Infers action from HTTP method (GET=view, POST=create, PUT/PATCH=update, DELETE=delete). Resolves resource_id from route params or path (profile → auth id, user list → null).
- **Routes**: Applied to profile, user settings, and user management. Access log API: `GET /access-logs`, `GET /access-logs/export`, `GET /access-logs/stats` (admin-only).
- **Frontend**: Access Logs page at `/configuration/access-logs` with filters (user, action, resource type, dates), table, CSV export, pagination.
- **Retention**: `ACCESS_LOG_RETENTION_DAYS=2190` (6 years) in .env.example; no automatic purge implemented (HIPAA requires retention).

### Backup & Restore

- **BackupService**: Exports `access_logs` to `access_logs.json`; manifest `contents.access_logs = true`. Restore reads JSON and imports with merge strategy: skip rows whose ID already exists.

### Recipes, Patterns, Rule

- **add-access-logging** recipe: When to use, middleware vs AccessLogService, verification steps.
- **AccessLogService** pattern in `patterns.md` with examples.
- **HIPAA / Access Logging** section in `context-loading.md`.
- **logging-compliance.mdc** Cursor rule: access logging, application logging, audit logging, frontend errorLogger, quick checklist.

### Documentation & Roadmap

- **docs/logging.md**: Live viewer and HIPAA sections, LOG_BROADCAST_*, ACCESS_LOG_RETENTION_DAYS, new key files.
- **docs/plans/logging-roadmap.md**: Future log storage/archival, export, cleanup (retention per type, scheduled cleanup, admin UI).
- **docs/roadmaps.md**: “Log Archival & Cleanup” in Next Up linking to logging roadmap.

## Challenges Encountered

- **Profile route**: API uses `profile` prefix, not `user/profile`; middleware resolves resource_id via `path()` contains `profile` for User.
- **User settings**: Path contains `user/`; middleware treats as Setting with resource_id = auth id.

## Observations

- Access logs are always included in backup (no opt-in); restore merge avoids duplicates when re-restoring.
- Live log broadcast is off by default; enable explicitly to avoid noise.

## Trade-offs

- Access log middleware logs after controller runs; we do not differentiate 2xx vs 4xx. HIPAA often requires logging both successful and failed access.
- Backup merges access_logs by “skip if ID exists”; new inserts get new auto-increment IDs, so restored logs may not preserve original IDs.

## Next Steps (Future Considerations)

- Implement archival, export, and cleanup per [Logging Roadmap](plans/logging-roadmap.md).
- Optional: purge access logs beyond retention only when explicitly configured and above 6 years.

## Testing Notes

- Live logs: Set `LOG_BROADCAST_ENABLED=true`, add `broadcast` to `LOG_STACK`, configure Reverb (`BROADCAST_CONNECTION=reverb`), open Application Logs, enable Live, trigger `Log::info()`; verify entry appears.
- HIPAA: Visit profile, users, or user settings; confirm rows in `access_logs` and in Access Logs UI.
- Backup: Create backup, confirm `access_logs.json` in ZIP and `access_logs` in manifest; restore and verify merge behavior.
