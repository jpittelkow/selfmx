# AuditService Pattern

Use `AuditService` to log significant user actions for compliance and debugging. The service is registered as a singleton; inject it in controllers or use the `AuditLogging` trait.

## Usage

```php
use App\Services\AuditService;
use App\Http\Traits\AuditLogging;

// Option 1: Inject AuditService
public function __construct(private AuditService $auditService) {}

$this->auditService->log('user.created', $user, [], ['name' => $user->name]);
$this->auditService->logAuth('login', $user);
$this->auditService->logAuth('login_failed', null, ['email' => $email], 'warning');
$this->auditService->logSettings('mail', $oldValues, $newValues, $request->user()->id);
$this->auditService->logUserAction('custom.action', null, null, 'info');
$this->auditService->logModelChange($model, 'resource.updated', $oldValues, $newValues);

// Option 2: Use AuditLogging trait (forwards to AuditService::log)
use AuditLogging;
$this->audit('user.created', $user, [], ['name' => $user->name], null, 'info');
```

- **When to use**: Log auth events (login, logout, failed login, 2FA), user management (create, update, delete, disable, admin toggle), settings changes, backup operations, and any other action that should be auditable.
- **Action naming**: Use `{resource}.{action}` (e.g. `auth.login`, `user.created`, `settings.updated`, `backup.restored`). Severity: `info`, `warning`, `error`, `critical`.
- **Sensitive data**: `AuditService::log()` and `logSettings()` automatically mask keys containing `password`, `token`, `secret`, `api_key`, etc. (values replaced with `***`). Do not pass raw secrets in `old_values`/`new_values`.
- **Error resilience**: If writing the audit log fails, the service logs to Laravel Log and returns null; the request is not broken.
- **Request context**: If you do not pass `Request`, the service resolves it from the container when available (for IP and user agent).

**Key files:** `backend/app/Services/AuditService.php`, `backend/app/Http/Traits/AuditLogging.php`, `backend/app/Models/AuditLog.php`, `backend/app/Http/Controllers/Api/AuditLogController.php`.

**Related:** [Recipe: Trigger audit logging](../recipes/trigger-audit-logging.md), [Recipe: Add auditable action](../recipes/add-auditable-action.md).
