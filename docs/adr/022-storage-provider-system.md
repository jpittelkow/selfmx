# ADR-022: Storage Provider System

## Status

Accepted

## Date

2026-01-31

## Context

Sourdough needs flexible file storage for:
- User uploads (avatars, attachments)
- Backup storage (local and remote)
- Application assets

Self-hosted users may want local storage, while others need cloud providers (S3, GCS, Azure). The system must support multiple providers with a unified interface and admin-configurable settings.

## Decision

We implement a **multi-provider storage system** using Laravel's Filesystem abstraction with database-stored configuration.

### Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                   Storage Architecture                           │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  Application Code                                                │
│  ┌──────────────────────────────┐                               │
│  │  Storage::disk('default')    │ ─── Laravel Filesystem API    │
│  │  StorageService              │                               │
│  └─────────────┬────────────────┘                               │
│                │                                                 │
│                ▼                                                 │
│  ┌──────────────────────────────┐                               │
│  │  ConfigServiceProvider       │                               │
│  │  (injects storage config)    │                               │
│  └─────────────┬────────────────┘                               │
│                │                                                 │
│       ┌────────┴────────┬────────────┬─────────────┐            │
│       ▼                 ▼            ▼             ▼            │
│  ┌─────────┐      ┌─────────┐  ┌─────────┐  ┌─────────┐        │
│  │  Local  │      │   S3    │  │   GCS   │  │  Azure  │        │
│  │Filesystem│     │(& S3-   │  │(Google) │  │  Blob   │        │
│  │         │      │compat)  │  │         │  │         │        │
│  └─────────┘      └─────────┘  └─────────┘  └─────────┘        │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### Supported Providers

| Provider | Driver | Settings Prefix | Use Case |
|----------|--------|-----------------|----------|
| Local Filesystem | `local` | - | Self-hosted default |
| Amazon S3 | `s3` | `s3_` | AWS cloud storage |
| Google Cloud Storage | `gcs` | `gcs_` | GCP cloud storage |
| Azure Blob Storage | `azure` | `azure_` | Azure cloud storage |
| DigitalOcean Spaces | `s3` | `do_` | DO S3-compatible |
| MinIO | `s3` | `minio_` | Self-hosted S3-compatible |
| Backblaze B2 | `s3` | `b2_` | Budget cloud storage |

### Database Settings

Storage configuration stored in `system_settings` (group: `storage`):

```php
// Key settings
'storage.driver' => 'local',           // Active provider
'storage.max_upload_size' => 10,       // MB
'storage.allowed_file_types' => '*',   // Comma-separated or *

// S3 settings
'storage.s3_bucket' => '',
'storage.s3_region' => 'us-east-1',
'storage.s3_key' => '',                // Encrypted
'storage.s3_secret' => '',             // Encrypted
'storage.s3_endpoint' => '',           // For S3-compatible

// GCS settings
'storage.gcs_bucket' => '',
'storage.gcs_project_id' => '',
'storage.gcs_key_file' => '',          // Encrypted JSON
```

### StorageService

Core service methods:

```php
class StorageService
{
    // Provider management
    public function getProviderConfig(string $provider): array;
    public function getAllProviders(): array;
    public function testConnection(string $provider): bool;
    
    // File operations
    public function store(UploadedFile $file, string $path): string;
    public function get(string $path): ?string;
    public function delete(string $path): bool;
    public function exists(string $path): bool;
    
    // Analytics
    public function getStorageStats(): array;  // Usage, file count, etc.
}
```

### File Browser

Admin UI at Configuration > Storage > Files provides:
- Browse files and directories
- Upload new files
- Download files
- Delete files (with confirmation)
- View file metadata (size, type, modified date)

### Configuration Injection

`ConfigServiceProvider` injects storage settings at boot:

```php
protected function injectStorageConfig(): void
{
    $driver = $this->settings->get('storage.driver', 'local');
    config(['filesystems.default' => $driver]);
    
    // Inject provider-specific config
    if ($driver === 's3') {
        config(['filesystems.disks.s3.bucket' => $this->settings->get('storage.s3_bucket')]);
        // ... more settings
    }
}
```

### API Endpoints

**Storage Settings** (admin; requires `can:settings.view` / `can:settings.edit`):

```
GET    /api/storage-settings              - Get storage settings (alert thresholds + provider config)
PUT    /api/storage-settings              - Update storage settings
POST   /api/storage-settings/test         - Test provider connection
GET    /api/storage-settings/stats        - Get storage usage statistics
GET    /api/storage-settings/analytics    - Storage analytics (usage over time)
GET    /api/storage-settings/cleanup-suggestions - Suggest files for cleanup
POST   /api/storage-settings/cleanup      - Execute cleanup
GET    /api/storage-settings/paths        - List storage directory paths
GET    /api/storage-settings/health       - Storage health status
```

**File Manager** (admin only; requires `can:admin`):

```
GET    /api/storage/files                     - List files/directories (paginated)
POST   /api/storage/files                     - Upload file(s)
GET    /api/storage/files/{path}              - File/directory details + preview URL
GET    /api/storage/files/{path}/download     - Download file
PUT    /api/storage/files/{path}/rename       - Rename file/directory
PUT    /api/storage/files/{path}/move         - Move file/directory
DELETE /api/storage/files/{path}              - Delete file/directory
```

## Consequences

### Positive

- Single interface for all storage providers
- Easy migration between providers (change config, move files)
- Database settings enable UI configuration without env edits
- S3-compatible API covers many providers (DO Spaces, MinIO, B2)
- Local storage works out-of-the-box for self-hosted

### Negative

- Each provider requires specific configuration
- Credentials stored in database (encrypted)
- Provider-specific features may not be available through unified API
- Large file migrations between providers can be slow

### Neutral

- Storage settings are admin-only
- Files can be organized in directories
- No automatic provider failover (explicit selection)

## Key Files

- `backend/app/Services/StorageService.php` - Storage operations and provider management
- `backend/app/Http/Controllers/Api/StorageSettingController.php` - Settings API
- `backend/app/Http/Controllers/Api/FileManagerController.php` - File browser API
- `backend/config/filesystems.php` - Laravel filesystem configuration
- `backend/config/settings-schema.php` - Storage settings schema (storage group)
- `backend/app/Providers/ConfigServiceProvider.php` - Config injection
- `frontend/app/(dashboard)/configuration/storage/page.tsx` - Storage settings UI
- `frontend/app/(dashboard)/configuration/storage/files/page.tsx` - File browser UI
- `frontend/components/storage/file-browser.tsx` - File browser component
- `frontend/components/storage/upload-dialog.tsx` - Upload dialog
- `frontend/components/storage/file-preview.tsx` - File preview component

## Notes

### Why Storage Doesn't Use a Custom Provider Interface

Other Sourdough subsystems (notifications, LLM, backup) use a custom interface pattern (`ChannelInterface`, `LLMProviderInterface`, `DestinationInterface`) with provider classes that implement the interface. Storage does **not** follow this pattern because Laravel's Filesystem abstraction (`Storage::disk()`) already provides the same benefits:

- Unified API across all providers (put, get, delete, exists, url)
- Provider-specific configuration via `config/filesystems.php`
- Well-tested, maintained by the Laravel team
- S3-compatible API covers multiple providers (AWS S3, DigitalOcean Spaces, MinIO, Backblaze B2)

Instead of custom provider classes, `StorageService` uses `ConfigServiceProvider` to inject database-stored credentials into Laravel's filesystem config at boot time. Adding a new storage provider means adding a new disk configuration in `filesystems.php` and a form section in the frontend — no new PHP class needed.

## Related Decisions

- [ADR-007: Backup System Design](./007-backup-system-design.md) - Backup destinations use similar providers (custom interface)
- [ADR-014: Database Settings with Environment Fallback](./014-database-settings-env-fallback.md) - Storage settings stored in DB
