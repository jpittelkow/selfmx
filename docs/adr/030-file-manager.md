# ADR-030: File Manager

## Status

Accepted

## Date

2026-03-04

## Context

Users need a web-based interface to browse, upload, download, rename, move, and delete files managed by the application's storage system. The file manager must work with any configured storage provider (local, S3, etc.) and enforce security constraints.

## Decision

Implement a file manager API using `StorageService` as the backend, with path validation and audit logging.

### Security

- **Path traversal protection**: All paths are validated — `..` segments and null bytes are blocked. Sensitive directories (`.env`, `config`, `.git`, `bootstrap`, `vendor`) are blocked by segment name.
- **Upload policy**: `StorageService::getUploadPolicy()` provides configurable file type and size restrictions. Each upload is validated via `StorageService::validateUpload()`.
- **Audit logging**: All file operations (upload, download, delete, rename, move) are logged via `AuditService`.
- **Path normalization**: Backslashes are converted to forward slashes, leading/trailing slashes are trimmed.

### API

All endpoints require `can:admin` middleware.

| Method | Endpoint | Purpose |
|--------|----------|---------|
| `GET` | `/api/storage/files` | List files/directories (paginated) |
| `POST` | `/api/storage/files` | Upload file(s) |
| `GET` | `/api/storage/files/{path}` | File/directory details + preview URL |
| `GET` | `/api/storage/files/{path}/download` | Download a file |
| `PUT` | `/api/storage/files/{path}/rename` | Rename file/directory |
| `PUT` | `/api/storage/files/{path}/move` | Move file/directory |
| `DELETE` | `/api/storage/files/{path}` | Delete file/directory |

### Storage Integration

The file manager delegates all operations to `StorageService`, which abstracts the underlying storage provider. This means the file manager works with local disk, S3, or any other configured provider without code changes.

## Consequences

### Positive

- Works with any storage provider via `StorageService` abstraction
- Path traversal and sensitive directory protection
- Full audit trail for compliance
- Paginated listing for large directories
- Multi-file upload support with per-file validation

### Negative

- No user scoping — all authenticated users share the same file namespace (access controlled by auth middleware)
- No folder creation endpoint — folders are implicitly created on upload

### Neutral

- Uses `FilePathRequest` form request for path validation on show/download/delete/rename/move
- Preview URLs generated via `StorageService::getPreviewUrl()`

## Related Decisions

- [ADR-022](./022-storage-provider-system.md) — storage provider abstraction
- [ADR-023](./023-audit-logging-system.md) — file operations are audited

## Notes

- Key files: `backend/app/Http/Controllers/Api/FileManagerController.php`, `backend/app/Services/StorageService.php`
- Blocked path segments: `.env`, `config`, `.git`, `bootstrap`, `vendor`
