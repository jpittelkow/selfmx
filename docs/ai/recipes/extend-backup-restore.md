# Recipe: Extend Backup and Restore Features

How to add new backup/restore functionality: new settings, restore behavior, scheduling, notifications, or UI behavior. Use this when the change is not “add a new destination” (use [add-backup-destination.md](add-backup-destination.md) for that).

**Backup documentation hub:** [Backup & Restore](../../backup.md) – user, admin, and developer docs; key files; all backup-related ADRs and recipes.

## Prerequisites

- [ADR-007: Backup System Design](../../adr/007-backup-system-design.md)
- [ADR-014: Database Settings with Environment Fallback](../../adr/014-database-settings-env-fallback.md)
- Backup flow: `BackupService` (create/restore), `BackupSettingController` (settings API), `ConfigServiceProvider` (inject backup config), frontend `configuration/backup/page.tsx` (Backups + Settings tabs)

## Decision: What are you adding?

| Kind of change | Where to implement | Recipe section |
|----------------|--------------------|----------------|
| New backup **setting** (e.g. option, flag, credential) | Schema, ConfigServiceProvider, BackupSettingController, frontend Settings tab | [New backup setting](#new-backup-setting) |
| New **restore** behavior (e.g. selective restore, validation) | BackupService, optional API/UI | [New restore behavior](#new-restore-behavior) |
| **Scheduling** (cron, frequency, destinations) | Schema + config + scheduler; already partially in place | [Scheduling](#scheduling) |
| **Notifications** (on success/failure) | BackupService + NotificationOrchestrator; settings already exist | [Notifications](#notifications) |
| New **backup content** (e.g. include new data) | BackupService (create/restore), manifest | [New backup content](#new-backup-content) |
| **UI-only** (e.g. filters, bulk actions) | Frontend backup page only | [UI-only changes](#ui-only-changes) |

---

## New backup setting

Use this when you add a configurable value that affects backup/restore (e.g. new option, timeout, path).

1. **Schema** – In `backend/config/settings-schema.php`, under the `backup` group, add entries with `env`, `default`, and optionally `encrypted`:
   ```php
   'my_setting' => ['env' => 'BACKUP_MY_SETTING', 'default' => 'value'],
   'my_secret' => ['env' => 'BACKUP_MY_SECRET', 'default' => null, 'encrypted' => true],
   ```
2. **Config** – In `backend/config/backup.php`, add the key with `env()` so defaults exist before DB injection.
3. **ConfigServiceProvider** – In `injectBackupConfig()`, map the flat key to the nested config path used by BackupService/destinations:
   ```php
   if (array_key_exists('my_setting', $settings)) {
       config(['backup.some.nested.my_setting' => $settings['my_setting'] ?? config('backup.some.nested.my_setting')]);
   }
   ```
4. **BackupSettingController** – In `update()` validation, add rules for the new keys. In `injectBackupConfigFromSettings()` (used by Test Connection), add the same flat → nested mapping if the setting affects destinations.
5. **Frontend** – In `frontend/app/(dashboard)/configuration/backup/page.tsx`: extend `backupSettingsSchema`, `BackupSettingsForm`, `defaultBackupSettings`, the fetch mapping, and add a FormField/SettingsSwitchRow in the appropriate Settings tab Card.

**Pattern:** All backup settings that are stored in the DB live in the `backup` group; ConfigServiceProvider is the single place that maps flat schema keys to nested `config('backup.*')`.

---

## New restore behavior

Use this when you change what happens during restore (e.g. selective restore, extra validation, hooks).

1. **BackupService** – Implement or change logic in `restoreFromFile()`, `restoreFromUpload()`, or the private `performRestore()`. Keep DB transaction and manifest validation; add new steps (e.g. validate backup version, restore only certain parts).
2. **Manifest** – If you add new backup content or restore options, extend the manifest in `create()` and read it in `performRestore()` (see [New backup content](#new-backup-content)).
3. **API** – If the restore API must accept new parameters (e.g. `restore_options`), extend `BackupController::restore()` validation and pass options into BackupService.
4. **Frontend** – If the user must choose options (e.g. “Restore only database”), add UI in the restore flow and send the new parameters in the restore request.

**Pattern:** Restore logic stays in BackupService; controller validates input and calls the service.

---

## Scheduling

Scheduling is already represented in settings (`schedule_enabled`, `schedule_frequency`, `schedule_time`, `schedule_day`, `schedule_date`, `scheduled_destinations`). To extend it:

1. **New schedule-related setting** – Follow [New backup setting](#new-backup-setting) (schema, config, ConfigServiceProvider, BackupSettingController, frontend).
2. **Actual cron/job** – The app may run a scheduled command or job that calls BackupService. That command should read `config('backup.schedule')` and `config('backup.scheduled.destinations')` (already injected from DB) and create backups, then optionally push to remote destinations. Implement or extend the scheduler entry (e.g. Laravel scheduler in `routes/console.php` or a job class) and document it in [ADR-007](../../adr/007-backup-system-design.md) or the deployment docs.

---

## Notifications

Backup success/failure notifications are already configured via `notify_success` and `notify_failure` in the backup settings. To send them:

1. **BackupService** – After a backup is created (or fails), resolve the notification channels and call `NotificationOrchestrator::send()` (or the appropriate notification API). Use a semantic type (e.g. `backup.completed`, `backup.failed`) and include minimal context (e.g. filename, size). See [Trigger Notifications](trigger-notifications.md).
2. **Recipients** – Decide who gets notified (e.g. admins, or users who have backup notifications enabled). Implement that in the same place you call the orchestrator.
3. **Settings** – If you add a new notification option (e.g. “Notify on schedule only”), add it as a new backup setting and use it when deciding whether to send.

---

## New backup content

Use this when backups should include or exclude new kinds of data (e.g. a new table, a new file tree).

1. **Manifest** – In `BackupService::create()`, extend the manifest array (e.g. `contents.new_section => true`) and add the actual backup step (e.g. export to a file and add it to the ZIP).
2. **Restore** – In `performRestore()`, read the manifest and, if the new section is present, restore it (e.g. read the new file from the ZIP and apply it). Keep the existing transaction and error handling.
3. **Versioning** – If the backup format changes, consider bumping `config('backup.format_version')` and handling old manifests in `performRestore()` so old backups still restore.

**Pattern:** Manifest is the contract between create and restore; document new keys in ADR-007 or inline.

---

## UI-only changes

Use this for changes that only affect the backup/restore UI (filters, bulk actions, copy, etc.).

1. **Frontend only** – Edit `frontend/app/(dashboard)/configuration/backup/page.tsx`. Use existing API: list backups (`GET /backup`), create (`POST /backup/create`), download (`GET /backup/download/{filename}`), restore (`POST /backup/restore`), delete (`DELETE /backup/{filename}`).
2. **State and UX** – Add local state (e.g. selected rows, filter) and handlers that call the same endpoints. Keep loading and error handling consistent with the rest of the page (e.g. toast, disabled buttons).
3. **Settings tab** – If you add a purely UI preference (e.g. “Default sort”), you can store it in user settings or local state; only add to backup settings schema if it must be system-wide and stored in DB.

---

## Quick reference – key files

| Purpose | File |
|---------|------|
| Backup/restore logic | `backend/app/Services/Backup/BackupService.php` |
| Destination contract | `backend/app/Services/Backup/Destinations/DestinationInterface.php` |
| Backup settings schema | `backend/config/settings-schema.php` (backup group) |
| Backup config structure | `backend/config/backup.php` |
| Inject DB → config | `backend/app/Providers/ConfigServiceProvider.php` (`injectBackupConfig`) |
| Settings API + test | `backend/app/Http/Controllers/Api/BackupSettingController.php` |
| Backup operations API | `backend/app/Http/Controllers/Api/BackupController.php` |
| Backup UI (Backups + Settings tabs) | `frontend/app/(dashboard)/configuration/backup/page.tsx` |

---

## Related

- [Add backup destination](add-backup-destination.md) – add a new storage destination
- [Trigger notifications](trigger-notifications.md) – send backup success/failure notifications
- [ADR-007: Backup System Design](../../adr/007-backup-system-design.md)
- [Backup & Restore patterns](../patterns/backup-restore.md)
