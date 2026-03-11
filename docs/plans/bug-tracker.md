# Bug Tracker

Suspected bugs and issues to investigate. Claude logs items here when something looks wrong during development. Review periodically and promote confirmed bugs to issues or fix them directly.

## How to Use

- **Suspected**: Observed during development but not yet confirmed or root-caused
- **Confirmed**: Reproduced and understood, needs a fix
- **Investigating**: Actively being looked into
- **Fixed**: Resolved (move to Fixed section with date and brief note)

## Suspected Bugs

### `GET /storage-settings` returns incomplete data
- **Observed**: 2026-03-08
- **Status**: Suspected
- **Context**: ADR & API audit (batch 3) of ADR-022 (Storage Provider System)
- **Symptoms**: `StorageSettingController::show()` uses `settingService->getGroup('storage')` which only returns schema-defined alert settings (4 keys), not the driver or provider credentials. The storage system itself works because `StorageService` reads directly from `SystemSetting::getGroup()` (bypassing schema).
- **Files involved**: `backend/app/Http/Controllers/Api/StorageSettingController.php`, `backend/app/Services/SettingService.php`, `backend/config/settings-schema.php`
- **Notes**: Low severity — storage works correctly; only the admin API response is incomplete. Users updating storage settings via the UI won't notice, but API consumers expecting full config in the GET response will get a partial response.

### Changelog page throws "Server Error" (Malformed UTF-8)
- **Observed**: 2026-03-09
- **Status**: Suspected
- **Context**: User navigated to `/configuration/changelog` in production
- **Symptoms**: Page fails to load with "Server Error". Backend logs show `InvalidArgumentException: Malformed UTF-8 characters, possibly incorrectly encoded` at `JsonResponse.php:90`. The changelog data being serialized to JSON contains invalid UTF-8 bytes.
- **Files involved**: Changelog controller/service (likely `ChangelogController` or similar), `JsonResponse.php:90`
- **Notes**: Happens consistently on page load. Likely a changelog entry contains non-UTF-8 characters (e.g., from git log output or a VERSION/CHANGELOG file with encoding issues). Need to sanitize or re-encode the data before JSON serialization.

### VAPID private key decryption fails
- **Observed**: 2026-03-09
- **Status**: Suspected
- **Context**: Appears on page load — `notifications.vapid_private_key` setting fails to decrypt
- **Symptoms**: Warning log: `Failed to decrypt setting notifications.vapid_private_key {"error":"The payload is invalid."}`. The encrypted value in the database can't be decrypted, possibly due to an APP_KEY change or the value was stored corrupted.
- **Files involved**: `backend/app/Services/SettingService.php`, notifications settings
- **Notes**: Low severity if push notifications aren't actively used, but will cause issues when attempting to send web push notifications. Fix may require re-saving the VAPID keys or regenerating them.

### SES provider: `dkim_rotation` and `events` capabilities report `false` despite implementing interfaces
- **Observed**: 2026-03-10
- **Status**: Confirmed
- **Context**: Code review during email provider documentation
- **Symptoms**: `SesProvider` implements `HasDkimManagement` (with a working disable/re-enable workaround) and `HasEventLog` (returns empty with CloudWatch note), but `getCapabilities()` reports both as `false`. The controller's `requireCapability()` check blocks access to these endpoints even though the code works. The DKIM workaround is genuinely useful; the EventLog stub is debatable.
- **Files involved**: `backend/app/Services/Email/SesProvider.php` (lines 41-52)
- **Notes**: Two options: (1) Set `dkim_rotation => true` since the implementation works. Keep `events => false` since it returns no data. (2) Remove `HasEventLog` from SES since the stub provides no value. The anti-pattern docs warn against implementing interfaces for capabilities reported as `false`.
- **Resolution**: Fixed 2026-03-10 — Set `dkim_rotation => true`, removed `HasEventLog` interface and stub method.

<!-- Template:
### [Short description]
- **Observed**: [date]
- **Status**: Suspected / Confirmed / Investigating
- **Context**: [What was happening when this was noticed]
- **Symptoms**: [What went wrong or looked wrong]
- **Files involved**: [Relevant files if known]
- **Notes**: [Any additional context]
-->

## Confirmed Bugs

_None yet._

## Fixed

### Ambiguous `id` column in UserController::updateGroups on SQLite
- **Observed**: 2026-03-05
- **Fixed**: 2026-03-05
- **Fix**: Changed `pluck('id')` to `pluck('user_groups.id')` in `UserController.php:290` to qualify the column name in the join query.
