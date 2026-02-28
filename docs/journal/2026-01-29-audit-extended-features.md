# Audit Extended Features (Real-time Streaming & Structured Logging) - 2026-01-29

## Overview

Implemented two extensions to the audit logging system: real-time audit log streaming to the frontend via Laravel Echo/Pusher, and structured JSON application logging with correlation IDs and request context for tracing and observability.

## Implementation Approach

### Real-time Audit Log Streaming

- **Backend**: Created `AuditLogCreated` event implementing `ShouldBroadcastNow`; broadcasts on private channel `audit-logs` with full log payload (id, action, severity, user, old/new values, IP, etc.). Channel authorization in `routes/channels.php` restricts subscription to admin users (`$user->is_admin`). `AuditService::log()` dispatches the event after creating each audit log (with `$log->load('user')`).
- **Frontend**: Added `useAuditStream(enabled, onNewLog)` hook in `frontend/lib/use-audit-stream.ts` that subscribes to `private-audit-logs` and listens for `.AuditLogCreated`. Returns `status`: connected, connecting, disconnected, or unavailable (when Pusher is not configured). Audit page (`configuration/audit/page.tsx`) has a "Live" toggle; when enabled, new logs are prepended to the list with a 3-second highlight animation; connection status (Live / Unavailable / Connecting…) is shown next to the toggle.

### Structured JSON Logging with Correlation IDs

- **AddCorrelationId middleware**: New middleware at the start of the API stack. Generates a UUID or uses `X-Correlation-ID` from the request; binds it in the app container as `correlation_id`; adds it to the response header for client-side tracing.
- **ContextProcessor**: Monolog processor that adds `correlation_id`, `user_id` (when authenticated), `ip_address`, and `request_uri` to every log record’s `extra` array. Safe when run outside HTTP context (e.g. console).
- **AddContextProcessorTap**: Tap class that pushes `ContextProcessor` onto the Monolog logger; used by the `single` and `json` channels so all file and JSON logs include context.
- **JSON channel**: New `json` channel in `config/logging.php` using `JsonFormatter`, writing to `php://stderr`. Optional; add `json` to `LOG_STACK` (e.g. `LOG_STACK=single,json`) for structured output in production or containers.
- **Environment**: Updated `backend/.env.example` with comments for `LOG_CHANNEL`, `LOG_STACK`, and using `json` for structured logging.

## Challenges Encountered

- **Tap API**: Laravel’s logging tap receives `Illuminate\Log\Logger`, not `Monolog\Logger`; the tap must call `$logger->getLogger()->pushProcessor(...)` to attach the context processor to the underlying Monolog instance.
- **Monolog 3**: Context processor implements `ProcessorInterface` and receives/returns `LogRecord`; extra context is written to `$record->extra`.

## Observations

- Real-time streaming reuses the existing Reverb/Echo setup and follows the same pattern as `NotificationSent` and the user notification channel.
- Correlation ID in the response header allows frontend or API clients to correlate requests with server logs when debugging.
- JSON logs with correlation_id, user_id, and request_uri are suitable for log aggregation tools (e.g. Datadog, ELK) without further middleware.

## Trade-offs

- Live stream only works when `BROADCAST_CONNECTION=reverb` and Reverb is configured; otherwise the UI shows "Unavailable" and no error is thrown.
- Context processor runs on every log call; cost is minimal (a few array lookups and auth check).

## Next Steps (Future Considerations)

- Optional: persist correlation_id in audit_logs for direct link between application logs and audit entries.
- Alerting for suspicious activity patterns (roadmap item) remains unimplemented.

## Testing Notes

- As admin, enable "Live" on Configuration > Audit; trigger an action (e.g. login, change setting); confirm new log appears at top with brief highlight; confirm status shows "Live" when Reverb is configured.
- Without Reverb (or with BROADCAST_CONNECTION=null), confirm "Live" shows "Unavailable".
- Check response headers for `X-Correlation-ID` on any API request; send `X-Correlation-ID: my-id` and confirm same value in response.
- Set `LOG_STACK=single,json`, trigger a log (e.g. failed login); confirm stderr has a JSON line with `correlation_id`, `user_id`, `ip_address`, `request_uri`.
