# ADR-023: Audit Logging System

## Status

Accepted

## Date

2026-01-31

## Context

Sourdough needs comprehensive audit logging for:
- Security and compliance (who did what, when)
- Troubleshooting user-reported issues
- HIPAA/SOC2 compliance requirements
- Real-time monitoring of system activity

Audit logging is distinct from application logging (errors, debugging) and access logging (HIPAA PHI access tracking).

## Decision

We implement a **database-backed audit logging system** with real-time broadcasting and severity levels.

### Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                   Audit Logging Architecture                     │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  Application Code                                                │
│  ┌───────────────────────────────────────────────────┐          │
│  │  AuditService::log($action, $model, $old, $new)   │          │
│  │  AuditLogging trait (auto-log model changes)       │          │
│  └────────────────────┬──────────────────────────────┘          │
│                       │                                          │
│                       ▼                                          │
│  ┌───────────────────────────────────────────────────┐          │
│  │  AuditLog Model                                    │          │
│  │  - Stores event with metadata                      │          │
│  │  - Masks sensitive data                            │          │
│  │  - Adds correlation ID                             │          │
│  └────────────────────┬──────────────────────────────┘          │
│                       │                                          │
│          ┌────────────┴────────────┐                            │
│          ▼                         ▼                            │
│  ┌──────────────┐         ┌──────────────┐                     │
│  │   Database   │         │  Broadcast   │                     │
│  │  audit_logs  │         │  (Reverb)    │                     │
│  └──────────────┘         └──────────────┘                     │
│                                    │                            │
│                                    ▼                            │
│                           Real-time Dashboard                   │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### Audit Log Structure

```sql
audit_logs
├── id (UUID)
├── user_id (FK → users, nullable for system events)
├── action (string, e.g., 'user.created', 'settings.updated')
├── auditable_type (polymorphic model type, nullable)
├── auditable_id (polymorphic model ID, nullable)
├── old_values (JSON, nullable)
├── new_values (JSON, nullable)
├── severity (enum: info, warning, error, critical)
├── ip_address
├── user_agent
├── correlation_id (links related events)
├── created_at
```

### Severity Levels

| Level | Use Case | Examples |
|-------|----------|----------|
| `info` | Normal operations | User login, settings changed |
| `warning` | Unusual but not harmful | Multiple login failures |
| `error` | Failed operations | Backup failed, email send failed |
| `critical` | Security events | Admin access, password changed |

### AuditService

Core service for logging:

```php
class AuditService
{
    public function log(
        string $action,
        ?Model $auditable = null,
        array $oldValues = [],
        array $newValues = [],
        ?int $userId = null,
        ?Request $request = null,
        string $severity = AuditLog::SEVERITY_INFO
    ): ?AuditLog;
}
```

### Sensitive Data Masking

Certain keys are automatically masked in `old_values` and `new_values`:
- `password`, `password_confirmation`
- `token`, `secret`
- `api_key`, `api_secret`
- `access_token`, `refresh_token`

Values are replaced with `***` before storage.

### Common Audit Actions

```php
// Authentication
'login'                    // User logged in
'logout'                   // User logged out
'login_failed'             // Failed login attempt
'password_changed'         // Password updated
'two_factor_enabled'       // 2FA setup
'passkey_registered'       // Passkey added

// User Management
'user.created'             // New user created
'user.updated'             // User profile updated
'user.deleted'             // User deleted
'user.disabled'            // User account disabled

// Settings
'settings.updated'         // System settings changed
'backup.created'           // Backup created
'backup.restored'          // Backup restored

// Admin Actions
'group.created'            // User group created
'permission.granted'       // Permission assigned
```

### Real-time Broadcasting

Audit events are broadcast via Laravel Echo/Reverb:

```php
// AuditLogCreated event
public function broadcastOn(): Channel
{
    return new PrivateChannel('audit-logs');
}
```

Frontend subscribes for live dashboard updates.

### Audit Dashboard

Admin UI at Configuration > Audit provides:
- Live streaming audit log viewer
- Filters by action, user, severity, date range
- Severity distribution charts
- Trend analysis over time
- Export to CSV

### API Endpoints

```
GET  /api/audit-logs              - List audit logs (paginated)
GET  /api/audit-logs/stats        - Audit statistics
GET  /api/audit-logs/export       - Export to CSV
```

Real-time streaming uses WebSocket via Laravel Echo/Reverb on a private `audit-logs` channel (not SSE). The frontend subscribes using `use-audit-stream.ts`.

## Consequences

### Positive

- Complete audit trail for compliance requirements
- Real-time visibility into system activity
- Sensitive data automatically protected
- Correlation IDs enable request tracing
- Severity levels enable prioritization

### Negative

- Database growth over time (requires retention policy)
- Broadcast overhead for high-volume systems
- Must be careful not to log sensitive data in custom events

### Neutral

- Audit logs are immutable (no update/delete via API)
- Retention policy can be configured (default: 365 days)
- External log aggregation can consume via export

## Key Files

- `backend/app/Services/AuditService.php` - Audit logging service
- `backend/app/Models/AuditLog.php` - Audit log model
- `backend/app/Events/AuditLogCreated.php` - Broadcast event
- `backend/app/Http/Controllers/Api/AuditLogController.php` - Audit API
- `backend/app/Traits/AuditLogging.php` - Model trait for auto-logging
- `backend/database/migrations/2024_01_01_000009_create_audit_logs_table.php`
- `frontend/app/(dashboard)/configuration/audit/page.tsx` - Audit dashboard
- `frontend/lib/use-audit-stream.ts` - Real-time stream hook
- `frontend/components/audit/audit-stats-card.tsx` - Statistics display
- `frontend/components/audit/audit-trends-chart.tsx` - Trend visualization
- `frontend/components/audit/audit-severity-chart.tsx` - Severity distribution

## Related Decisions

- [ADR-002: Authentication Architecture](./002-authentication-architecture.md) - Auth events are audited
- [ADR-018: Passkey/WebAuthn Authentication](./018-passkey-webauthn.md) - Passkey events are audited

## Notes

### Audit vs Access Logging

- **Audit logs**: User actions and system events (this ADR)
- **Access logs**: HIPAA PHI access tracking (separate AccessLogService)
- **Application logs**: Errors, debugging, operational events (Laravel Log)

### Log Retention

Configure retention via `AUDIT_LOG_RETENTION_DAYS` environment variable or admin settings (default: 365 days). Logs older than retention period are automatically purged by the scheduled cleanup job.
