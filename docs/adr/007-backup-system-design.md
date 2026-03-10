# ADR-007: Backup System Design

## Status

Accepted

## Date

2026-01-24

## Context

Self-hosted applications need robust backup and restore capabilities:
- Users must be able to backup their data easily
- Backups must be restorable on new installations
- Configuration and secrets need secure handling
- Large installations need scheduled automated backups
- Remote storage options for disaster recovery

## Decision

We will implement a **ZIP-based backup system** with a manifest file for versioning and validation.

### Backup Format

```
backup_selfmx_2026-01-24_143052.zip
├── manifest.json           # Backup metadata
├── database/
│   └── database.sqlite     # SQLite: file copy
│       OR
│   └── database.json       # MySQL/PostgreSQL: JSON export with SHA-256 integrity hash
├── storage/
│   └── app/                # All uploaded files
│       ├── avatars/
│       ├── attachments/
│       └── ...
└── config/
    └── settings.enc        # Encrypted settings export
```

**Note:** Only one database file is present per backup. SQLite backups contain `database.sqlite` (file copy). MySQL/PostgreSQL backups contain `database.json` (JSON export with integrity hash for tamper detection; see [ADR-024](024-security-hardening.md)).

### Manifest Structure

```json
{
  "version": "2.0",
  "app_version": "0.1.0",
  "created_at": "2026-01-24T14:30:52Z",
  "database": {
    "driver": "sqlite",
    "tables": 12,
    "total_rows": 1547
  },
  "storage": {
    "files": 234,
    "total_size_bytes": 52428800
  },
  "config": {
    "encrypted": true,
    "hash": "sha256:abc123..."
  },
  "checksum": "sha256:def456..."
}
```

### Architecture

```
┌──────────────────────────────────────────────────────────────────┐
│                       Backup System                               │
├──────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ┌──────────────────┐                                            │
│  │  BackupService   │                                            │
│  └────────┬─────────┘                                            │
│           │                                                       │
│           ├──► exportDatabase()                                   │
│           │         │                                             │
│           │         ├── SQLite: Copy file                        │
│           │         ├── MySQL: mysqldump                          │
│           │         └── PostgreSQL: pg_dump                       │
│           │                                                       │
│           ├──► exportStorage()                                    │
│           │         │                                             │
│           │         └── Copy storage/app/* to ZIP                │
│           │                                                       │
│           ├──► exportConfig()                                     │
│           │         │                                             │
│           │         └── Encrypt settings with backup key         │
│           │                                                       │
│           └──► createManifest()                                   │
│                     │                                             │
│                     └── Generate checksums, metadata             │
│                                                                   │
│  ┌──────────────────┐                                            │
│  │  RestoreService  │                                            │
│  └────────┬─────────┘                                            │
│           │                                                       │
│           ├──► validateBackup()                                   │
│           │         │                                             │
│           │         └── Check manifest, checksums, version       │
│           │                                                       │
│           ├──► restoreDatabase() (in transaction)                │
│           │                                                       │
│           ├──► restoreStorage()                                   │
│           │                                                       │
│           └──► restoreConfig()                                    │
│                                                                   │
└──────────────────────────────────────────────────────────────────┘
```

### Scheduled Backups

Using Laravel's scheduler:

```php
// app/Console/Kernel.php
$schedule->job(new CreateBackupJob)
    ->dailyAt('03:00')
    ->when(fn() => config('backup.schedule.enabled'));
```

Configuration options:
- Frequency: hourly, daily, weekly
- Retention: keep last N backups
- Destinations: local, S3, SFTP

### Backup Destinations

```
┌─────────────┐     ┌─────────────────────────────────────┐
│   Backup    │────►│         Destination Adapter          │
│   Service   │     ├─────────────────────────────────────┤
└─────────────┘     │  • LocalDisk - storage/app/backups  │
                    │  • S3 - AWS S3 or compatible        │
                    │  • SFTP - Remote server              │
                    │  • GoogleDrive - OAuth-based        │
                    └─────────────────────────────────────┘
```

### Database Backup Strategy

| Driver | Method | Format | Restoration |
|--------|--------|--------|-------------|
| SQLite | File copy | `database.sqlite` | Replace file, migrate |
| MySQL | JSON export | `database.json` + SHA-256 hash | `DB::table()->updateOrInsert()`, migrate |
| PostgreSQL | JSON export | `database.json` + SHA-256 hash | `DB::table()->updateOrInsert()`, migrate |

All restores run migrations after import to handle version differences.

### Security Considerations

1. **Sensitive Data Encryption**: Settings containing API keys are encrypted in backups
2. **Backup Encryption**: Optional full-backup encryption with user-provided password
3. **Checksum Validation**: SHA-256 checksums prevent tampering
4. **Version Compatibility**: Manifest version ensures compatible restores

### Configuration

```php
// config/backup.php
return [
    'enabled' => env('BACKUP_ENABLED', true),
    
    'schedule' => [
        'enabled' => env('BACKUP_SCHEDULE_ENABLED', false),
        'frequency' => env('BACKUP_FREQUENCY', 'daily'),
        'time' => env('BACKUP_TIME', '03:00'),
        'retention' => env('BACKUP_RETENTION', 7),
    ],
    
    'destinations' => [
        'local' => [
            'path' => storage_path('app/backups'),
        ],
        's3' => [
            'bucket' => env('BACKUP_S3_BUCKET'),
            'region' => env('BACKUP_S3_REGION', 'us-east-1'),
            'path' => env('BACKUP_S3_PATH', 'backups'),
        ],
    ],
    
    'encryption' => [
        'enabled' => env('BACKUP_ENCRYPT', false),
        // Key derived from APP_KEY + user password
    ],
];
```

### API Endpoints

**Backup operations** (admin; requires `can:backups.view` / `can:backups.create` / `can:backups.restore` / `can:backups.delete`):

```
GET    /api/backup                    - List available backups
POST   /api/backup/create             - Create new backup (body: include_database, include_files, include_settings)
GET    /api/backup/download/{filename} - Download backup file
POST   /api/backup/restore            - Restore (body: filename or multipart backup file)
DELETE /api/backup/{filename}         - Delete a backup
```

**Backup settings** (admin; requires `can:settings.view` / `can:settings.edit`):

```
GET    /api/backup-settings           - Get all backup settings (retention, schedule, S3/SFTP/Google Drive, encryption, notifications)
PUT    /api/backup-settings           - Update backup settings
POST   /api/backup-settings/reset/{key} - Reset one setting to env default
POST   /api/backup-settings/test/{destination} - Test connection (destination: s3, sftp, google_drive)
```

Backup configuration is stored in the database with environment fallback ([ADR-014](014-database-settings-env-fallback.md)). Sensitive values (passwords, keys) are encrypted at rest.

## Current Implementation (Key Files)

- **BackupService**: `backend/app/Services/Backup/BackupService.php` – create, list, download, restore, delete. Restore logic lives in the same service (no separate RestoreService).
- **Destinations**: `backend/app/Services/Backup/Destinations/DestinationInterface.php` – contract. Implementations: `LocalDestination`, `S3Destination`, `SFTPDestination`, `GoogleDriveDestination`. Each reads `config('backup.destinations.{id}.*')`; config is injected at boot from DB via ConfigServiceProvider.
- **Backup config**: `backend/config/backup.php` (structure and env defaults), `backend/config/settings-schema.php` (backup group – flat keys), `backend/app/Providers/ConfigServiceProvider.php` (`injectBackupConfig()`).
- **Controllers**: `BackupController.php` (operations), `BackupSettingController.php` (settings API and Test Connection).
- **UI**: `frontend/app/(dashboard)/configuration/backup/page.tsx` – two tabs: **Backups** (list, create, download, restore, delete), **Settings** (retention, schedule, S3/SFTP/Google Drive, encryption, notifications).

**Documentation:** [Backup & Restore (docs hub)](../backup.md), [Add backup destination (recipe)](../ai/recipes/add-backup-destination.md), [Extend backup/restore (recipe)](../ai/recipes/extend-backup-restore.md), [Backup patterns](../ai/patterns/backup-restore.md). Settings that must remain in `.env` only: [ADR-015](015-env-only-settings.md).

## Consequences

### Positive

- ZIP format is universally readable
- Manifest enables version-aware restoration
- Scheduled backups reduce data loss risk
- Multiple destinations provide redundancy
- Encrypted configs protect sensitive data

### Negative

- Large databases take time to backup
- ZIP compression limited for already-compressed files
- Remote storage requires additional configuration
- Restoration requires application downtime

### Neutral

- Backups are admin-only by default
- Manual and scheduled backups use same format
- Cross-database restoration is best-effort

## Related Decisions

- [ADR-010: Database Abstraction Strategy](./010-database-abstraction.md)
- [ADR-014: Database Settings with Environment Fallback](./014-database-settings-env-fallback.md) – Backup settings stored in DB with env fallback
- [ADR-015: Environment-Only Settings](./015-env-only-settings.md) – Which settings must stay in `.env`

## Notes

### Restore Safety

The restore process:
1. Validates backup before any changes
2. Creates automatic pre-restore backup
3. Runs in database transaction where possible
4. Rolls back on any error
5. Clears all caches after restore

### Version Migration

When restoring from older versions:
1. Import data as-is
2. Run all pending migrations
3. Run any necessary data transformations
4. Validate data integrity

This allows restoring backups from previous versions onto newer installations.
