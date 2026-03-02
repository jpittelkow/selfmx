# Backup & Restore

**Central documentation for backup and restore functionality.**

Backup and restore is a core feature for self-hosted deployments. This page is the main entry point for users, admins, and developers. For extending or implementing backup features, use the linked recipes and patterns.

---

## For Everyone

### What gets backed up

- **Database** – All application data (users, settings, notifications, etc.). SQLite: file copy; MySQL/PostgreSQL: JSON export with SHA-256 integrity hash for tamper detection.
- **Files** – Uploaded files under `storage/app/public` (avatars, attachments, etc.).
- **Settings** – Application settings stored in the database (exported; sensitive values handled securely).

Backups are ZIP archives with a `manifest.json` that describes version and contents ([ADR-007: Backup System Design](adr/007-backup-system-design.md)).

### Security

- **SQL Injection protection:** MySQL/PostgreSQL backups use JSON format instead of raw SQL. Restore uses parameterized queries via `DB::table()->updateOrInsert()`.
- **Integrity verification:** JSON backups include SHA-256 hash; restore verifies hash before importing data.
- **Legacy support:** Old SQL-format backups can still be restored via strict parsing into parameterized queries (backwards compatibility).

### Where backups live

- **Local** – Stored on the server (default disk: `backups`).
- **Remote (optional)** – Admins can configure S3, SFTP, or Google Drive. Settings are in **Configuration > Backup > Settings**.

---

## For Users (End Users)

**Location:** **Configuration > Backup** (admin only). If you are an admin, see [User Guide – Backup & Restore](user/README.md#backup--restore).

- **Backups tab:** Create a backup, download existing backups, restore from a backup, or delete a backup.
- **Restore** overwrites current data; confirm carefully. Type **RESTORE** to confirm in the dialog.

---

## For Admins

**Location:** **Configuration > Backup** (requires admin and `manage-backups` for operations; `manage-settings` for Backup Settings).

### Backups tab

- **Create Backup** – Creates a full backup (database, files, settings). Filename format: `selfmx-backup-YYYY-MM-DD_HH-mm-ss.zip`.
- **Download** – Download a backup file to keep off-server.
- **Restore** – Restore from an existing backup (by filename) or by uploading a `.zip` file. Restore runs in a transaction and rolls back on error.
- **Delete** – Remove a backup from the server.

### Settings tab

Backup configuration is stored in the database with environment fallback ([ADR-014](adr/014-database-settings-env-fallback.md)). Changes take effect without restart.

- **Retention & storage** – Storage disk name, retention policy (enabled, days, keep count, minimum backups).
- **Schedule** – Enable scheduled backups, frequency (daily/weekly/monthly), time, day of week/month, scheduled destinations (comma-separated: e.g. `local,s3`).
- **Amazon S3** – Enable, bucket, path, access key, secret key, region, custom endpoint. **Test Connection** to verify.
- **SFTP** – Enable, host, port, username, password or private key, path. **Test Connection** to verify.
- **Google Drive** – Enable, client ID, client secret, refresh token, folder ID. **Test Connection** to verify.
- **Encryption** – Optional backup file encryption with a password.
- **Notifications** – Notify on backup success and/or failure (when notification system is configured).

Save changes with **Save Changes**. Test Connection uses the **saved** settings; save before testing if you just edited credentials.

---

## For Developers

### Key files

| Purpose | File |
|--------|------|
| Backup/restore logic | `backend/app/Services/Backup/BackupService.php` |
| Destination contract | `backend/app/Services/Backup/Destinations/DestinationInterface.php` |
| Destinations | `backend/app/Services/Backup/Destinations/{S3,SFTP,GoogleDrive,Local}Destination.php` |
| Backup config structure | `backend/config/backup.php` |
| Backup settings (DB + env) | `backend/config/settings-schema.php` (group `backup`) |
| Config injection (boot) | `backend/app/Providers/ConfigServiceProvider.php` (`injectBackupConfig`) |
| Operations API | `backend/app/Http/Controllers/Api/BackupController.php` |
| Settings API + Test | `backend/app/Http/Controllers/Api/BackupSettingController.php` |
| Backup UI | `frontend/app/(dashboard)/configuration/backup/page.tsx` |

### Cross-Database Restore Compatibility

| Backup Source | Restore Target | Supported? | Notes |
|--------------|---------------|------------|-------|
| SQLite | SQLite | Yes | File copy, clean |
| MySQL | MySQL | Yes | JSON import, clean |
| PostgreSQL | PostgreSQL | Yes | JSON import, clean |
| SQLite | MySQL/PostgreSQL | **No** | Different backup formats (file copy vs JSON) |
| MySQL/PostgreSQL | SQLite | **No** | JSON import targets the same driver type |
| MySQL | PostgreSQL (or reverse) | **Untested** | JSON format is driver-agnostic in theory, but column types/auto-increment behavior differ |

**Rule:** Restore to the same database driver that created the backup. The manifest records the database driver, and restore should validate this before proceeding.

### Architecture decisions

- [ADR-007: Backup System Design](adr/007-backup-system-design.md) – Format, manifest, destinations, security.
- [ADR-014: Database Settings with Environment Fallback](adr/014-database-settings-env-fallback.md) – Backup settings in DB with env fallback.
- [ADR-015: Environment-Only Settings](adr/015-env-only-settings.md) – What must stay in `.env` (e.g. `APP_KEY`, `DB_*`).

### Extending backup and restore

Use these so new features stay consistent and well-documented:

1. **Add a new backup destination** (e.g. new cloud or protocol)  
   → [Recipe: Add backup destination](ai/recipes/add-backup-destination.md)  
   Implement `DestinationInterface`, add schema + config + ConfigServiceProvider + BackupSettingController + Settings tab UI.

2. **Add a new backup setting, restore behavior, scheduling, or notifications**  
   → [Recipe: Extend backup and restore features](ai/recipes/extend-backup-restore.md)  
   Covers: new setting, new restore behavior, scheduling, notifications, new backup content, UI-only changes.

3. **Follow shared patterns**  
   → [Patterns: Backup & Restore](ai/patterns/backup-restore.md)  
   How settings flow (schema → config → destinations), settings API, Test Connection, and UI structure.

### API reference

- **Operations:** [API README – Backup & Restore (Admin)](api/README.md#backup--restore-admin)
- **Settings:** [API README – Backup Settings (Admin)](api/README.md#backup-settings-admin)

### Context loading (AI / developers)

When working on backup-related code, load context as in [Context loading – Backup system](ai/context-loading.md#backup-system-work). Quick entry: [AI README – Backup & Restore row](ai/README.md) (table “Quick Context Loading”).

---

## Summary

| Audience | Entry point |
|---------|-------------|
| End users | [User Guide – Backup & Restore](user/README.md#backup--restore) |
| Admins | **Configuration > Backup** (Backups + Settings tabs); this page for reference |
| Developers | This page → Key files, ADRs, [Add backup destination](ai/recipes/add-backup-destination.md), [Extend backup/restore](ai/recipes/extend-backup-restore.md), [Backup patterns](ai/patterns/backup-restore.md) |
