# Recipe: Extend Logging

Add logging to new features or extend the application logging system.

## Prerequisites

- Read [Logging](../../logging.md) – logging standards and configuration
- Read [Logging Pattern](../patterns/logging.md)

## Add Logging to a Backend Service

1. **Import the Log facade**

```php
use Illuminate\Support\Facades\Log;
```

2. **Choose the level**

- **info**: Normal operations (backup created, email sent, job completed)
- **warning**: Recoverable issues (one provider failed, password reset failed)
- **error**: Failures (send failed, backup failed)

3. **Log with structured context**

```php
Log::info('Backup created', [
    'filename' => $filename,
    'size' => $size,
]);

Log::warning('LLM provider failed', [
    'provider' => $providerConfig->provider,
    'error' => $e->getMessage(),
]);

Log::error('Email send failed', [
    'user_id' => $user->id,
    'to' => $user->email,
    'error' => $e->getMessage(),
]);
```

4. **Do not log** secrets, passwords, full request bodies, or PII beyond IDs/emails where necessary.

## Add Logging to a Controller

Same as services: use `Log::info`, `Log::warning`, or `Log::error` with a short message and a context array. The context processor adds `correlation_id`, `user_id`, `ip_address`, and `request_uri` automatically in HTTP context.

```php
use Illuminate\Support\Facades\Log;

// After a successful operation
Log::info('Password reset link requested', ['email' => $request->email]);

// On validation or business rule failure
Log::warning('Password reset failed', ['email' => $request->email, 'status' => $status]);
```

## Add Frontend Error Reporting to a Component

1. **Import errorLogger**

```ts
import { errorLogger } from "@/lib/error-logger";
```

2. **In catch blocks**, report the error with optional context

```ts
try {
  await api.get("/something");
} catch (error) {
  errorLogger.report(
    error instanceof Error ? error : new Error("Failed to fetch something"),
    { source: "my-page" }
  );
  toast.error("Something went wrong");
}
```

3. **For non-error messages** (warnings, info), use `captureMessage`

```ts
errorLogger.captureMessage("No fields to update", "warning");
errorLogger.captureMessage("Feature disabled", "info", { feature: "x" });
```

4. **Do not use** `console.error` or `console.warn` for user-facing or operational failures; use `errorLogger` so reports reach the backend.

## Add a New Log Channel

1. **Edit `backend/config/logging.php`**

Add a new channel (e.g. external service) under `channels`:

```php
'external' => [
    'driver' => 'monolog',
    'level' => env('LOG_LEVEL', 'debug'),
    'handler' => YourHandler::class,
    'with' => [
        // handler config
    ],
    'tap' => [AddContextProcessorTap::class], // optional: add correlation_id, etc.
],
```

2. **Add environment variables** in `backend/.env.example` if the channel needs credentials or options.

3. **Include the channel in the stack** by adding it to `LOG_STACK` (e.g. `LOG_STACK=single,json,external`).

## Live log streaming (admin)

To stream application logs to **Configuration > Application Logs** in real time:

1. Add a `broadcast` channel to `LOG_STACK` (e.g. `LOG_STACK=single,broadcast`).
2. Set `LOG_BROADCAST_ENABLED=true` and `LOG_BROADCAST_LEVEL=info` (or `warning`/`error`).
3. Configure Reverb (`BROADCAST_CONNECTION=reverb`). Admin users can then enable **Live** on the Application Logs page.

See [Logging](../../logging.md#live-console-log-viewer-admin) and `backend/app/Logging/BroadcastLogHandler.php`.

## Application log export (admin)

Admins can export application log files (from `storage/logs/laravel*.log`) as CSV or JSON Lines:

- **Endpoint**: `GET /api/app-logs/export?date_from=&date_to=&level=&correlation_id=&format=csv|json`
- **UI**: Configuration > Application Logs – Export card (date range, level, correlation ID, format) and Export button.
- **Backend**: `AppLogExportService` (file discovery, line parsing, filtering), `AppLogExportController`.

## Log retention and cleanup

- **Retention**: Configuration > Log retention – app (1–365 days), audit (30–730 days), access (6 years min for HIPAA). Stored in system settings group `logging`; env fallback: `LOG_APP_RETENTION_DAYS`, `AUDIT_LOG_RETENTION_DAYS`, `ACCESS_LOG_RETENTION_DAYS`.
- **HIPAA toggle**: Configuration > Log retention – toggle "Enable HIPAA access logging". When disabled, no new access logs are created; "Delete all access logs" is available (with HIPAA violation warning). See [Logging](../../logging.md#hipaa-access-logging).
- **Cleanup**: `php artisan log:cleanup` (deletes old audit/access rows and old daily log files). Use `--dry-run` to preview, `--archive` to export audit/access to CSV in `storage/app/log-archive/` before deleting. Admins can also run it from **Configuration > Jobs** (Run Now for `log:cleanup`).
- **Key files**: `LogRetentionController`, `LogCleanupCommand`, `config/logging.php` (retention key), `ConfigServiceProvider::injectLoggingConfig()`.

## Suspicious activity alerting

The system checks for 5+ failed logins in 15 minutes and 10+ data export actions in 1 hour. When detected, admins are notified (in-app + email). Scheduled: `log:check-suspicious` every 15 minutes. Admins can also run it manually from **Configuration > Jobs** (Run Now). Dashboard shows an alert banner when current checks detect suspicious activity. API: `GET /api/suspicious-activity` (admin). Key files: `SuspiciousActivityService`, `CheckSuspiciousActivityCommand`, `SuspiciousActivityController`.

## Related

- **PHI access logging (HIPAA)**: When adding features that read or modify user data, use access logging (middleware or `AccessLogService`). See [Add access logging](add-access-logging.md).

## Verification

- [ ] Log messages appear at the correct level (info/warning/error)
- [ ] Context includes useful identifiers (user_id, provider, duration_ms, etc.)
- [ ] No secrets or full request bodies in logs
- [ ] Frontend errors reach the backend (check logs after triggering a client error)
- [ ] Correlation ID is present in backend logs when the request passed through the API
