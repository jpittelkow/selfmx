<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Services\UsageTrackingService;

class StorageService
{
    public const PROVIDERS = [
        'local' => ['label' => 'Local Filesystem', 'driver' => 'local'],
        's3' => ['label' => 'Amazon S3', 'driver' => 's3'],
        'gcs' => ['label' => 'Google Cloud Storage', 'driver' => 'gcs'],
        'azure' => ['label' => 'Azure Blob Storage', 'driver' => 'azure'],
        'do_spaces' => ['label' => 'DigitalOcean Spaces', 'driver' => 's3'],
        'minio' => ['label' => 'MinIO', 'driver' => 's3'],
        'b2' => ['label' => 'Backblaze B2', 'driver' => 's3'],
    ];

    /**
     * Default file-type whitelist when no types are configured (blocks executables).
     */
    private const DEFAULT_ALLOWED_TYPES = [
        // Documents
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'txt', 'csv',
        // Images
        'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico', 'tiff', 'tif',
        // Audio
        'mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a',
        // Video
        'mp4', 'webm', 'avi', 'mov', 'mkv', 'wmv',
        // Archives
        'zip', 'rar', '7z', 'tar', 'gz',
        // Other common safe types
        'json', 'xml', 'yaml', 'yml', 'md',
    ];

    /**
     * Return the effective upload policy from storage settings.
     *
     * @return array{max_bytes: int, allowed_extensions: array<string>}
     */
    public function getUploadPolicy(): array
    {
        $settings = SystemSetting::getGroup('storage');
        $maxBytes = (int) ($settings['max_upload_size'] ?? 10485760);
        $allowedTypes = $settings['allowed_file_types'] ?? [];

        return [
            'max_bytes' => $maxBytes,
            'allowed_extensions' => !empty($allowedTypes) ? $allowedTypes : self::DEFAULT_ALLOWED_TYPES,
        ];
    }

    /**
     * Validate a single uploaded file against an upload policy.
     * Returns a string error message, or null if valid.
     */
    public function validateUpload(UploadedFile $file, array $policy): ?string
    {
        if ($file->getSize() > $policy['max_bytes']) {
            return $file->getClientOriginalName() . ': exceeds max size.';
        }

        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, array_map('strtolower', $policy['allowed_extensions']), true)) {
            return $file->getClientOriginalName() . ': file type not allowed.';
        }

        if (!$this->validateMimeType($file, $ext)) {
            return $file->getClientOriginalName() . ': file type does not match content.';
        }

        return null;
    }

    /**
     * Validate that file MIME type matches the claimed extension.
     * Prevents extension spoofing attacks.
     */
    private function validateMimeType(UploadedFile $file, string $extension): bool
    {
        $mimeType = $file->getMimeType();
        $extensionMimeMap = config('mime-types');

        if (!isset($extensionMimeMap[$extension])) {
            return false;
        }

        return in_array($mimeType, $extensionMimeMap[$extension], true);
    }

    /**
     * Get provider-specific settings from the storage group.
     */
    public function getProviderConfig(string $provider): array
    {
        $all = SystemSetting::getGroup('storage');
        $prefix = $this->getSettingPrefix($provider);

        if ($prefix === null) {
            return $all;
        }

        $config = [];
        foreach ($all as $key => $value) {
            if ($key === 'driver' || $key === 'max_upload_size' || $key === 'allowed_file_types') {
                $config[$key] = $value;
            } elseif (Str::startsWith($key, $prefix)) {
                $config[Str::after($key, $prefix)] = $value;
            }
        }

        return $config;
    }

    /**
     * List all supported providers with metadata.
     */
    public function getAvailableProviders(): array
    {
        return collect(self::PROVIDERS)->map(function ($meta, $id) {
            return array_merge(['id' => $id], $meta);
        })->values()->all();
    }

    /**
     * Test connectivity for a provider with the given config.
     * Config keys should match request/DB (e.g. s3_bucket, gcs_bucket, etc.).
     */
    public function testConnection(string $provider, array $config): array
    {
        try {
            if ($provider === 'local') {
                return ['success' => true];
            }

            $diskConfig = $this->buildDiskConfig($provider, $config);
            if ($diskConfig === null) {
                return ['success' => false, 'error' => 'Unsupported provider or missing configuration.'];
            }

            $diskName = 'storage-test-'.Str::random(8);
            Config::set("filesystems.disks.{$diskName}", $diskConfig);

            $disk = Storage::disk($diskName);

            $testPath = '.selfmx-storage-test-'.Str::random(8);
            $disk->put($testPath, 'test');
            $disk->delete($testPath);

            Config::set("filesystems.disks.{$diskName}", null);

            return ['success' => true];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Build Laravel disk config array for a provider from flat config (DB/request).
     */
    public function buildDiskConfig(string $provider, array $config): ?array
    {
        $meta = self::PROVIDERS[$provider] ?? null;
        if ($meta === null) {
            return null;
        }

        $driver = $meta['driver'];

        if ($driver === 'local') {
            return [
                'driver' => 'local',
                'root' => storage_path('app'),
                'throw' => false,
            ];
        }

        if ($driver === 's3') {
            $key = $this->getConfigKey($config, $provider, 'key', 's3_key', 'key');
            $secret = $this->getConfigKey($config, $provider, 'secret', 's3_secret', 'secret');
            $bucket = $this->getConfigKey($config, $provider, 'bucket', 's3_bucket', 'bucket');
            $region = $this->getConfigKey($config, $provider, 'region', 's3_region', 'region');

            $diskConfig = [
                'driver' => 's3',
                'key' => $key,
                'secret' => $secret,
                'region' => $region ?? 'us-east-1',
                'bucket' => $bucket,
                'throw' => false,
            ];

            if ($provider === 'do_spaces') {
                $diskConfig['endpoint'] = $config['do_spaces_endpoint'] ?? $config['endpoint'] ?? 'https://'.$region.'.digitaloceanspaces.com';
                $diskConfig['use_path_style_endpoint'] = false;
            } elseif ($provider === 'minio') {
                $diskConfig['endpoint'] = $config['minio_endpoint'] ?? $config['endpoint'] ?? '';
                $diskConfig['use_path_style_endpoint'] = true;
            } elseif ($provider === 'b2') {
                $keyId = $config['b2_key_id'] ?? $config['key_id'] ?? $key;
                $diskConfig['key'] = $keyId;
                $diskConfig['secret'] = $config['b2_application_key'] ?? $config['application_key'] ?? $secret;
                $diskConfig['endpoint'] = $config['b2_endpoint'] ?? 'https://s3.'.($region ?? 'us-west-002').'.backblazeb2.com';
                $diskConfig['use_path_style_endpoint'] = false;
            }

            return $diskConfig;
        }

        if ($driver === 'gcs') {
            $bucket = $config['gcs_bucket'] ?? $config['bucket'] ?? '';
            $projectId = $config['gcs_project_id'] ?? $config['project_id'] ?? '';
            $credentials = $config['gcs_credentials_json'] ?? $config['credentials_json'] ?? $config['credentials'] ?? [];

            if (is_string($credentials)) {
                $decoded = json_decode($credentials, true);
                $credentials = is_array($decoded) ? $decoded : [];
            }

            return [
                'driver' => 'gcs',
                'project_id' => $projectId,
                'bucket' => $bucket,
                'key_file_path' => null,
                'credentials' => $credentials,
                'throw' => false,
            ];
        }

        if ($driver === 'azure') {
            $connectionString = $config['azure_connection_string'] ?? $config['connection_string'] ?? '';
            $container = $config['azure_container'] ?? $config['container'] ?? '';

            return [
                'driver' => 'azure',
                'connection_string' => $connectionString,
                'container' => $container,
                'throw' => false,
            ];
        }

        return null;
    }

    /**
     * Get the default disk for file manager operations.
     */
    public function getDisk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk(config('filesystems.default'));
    }

    /**
     * List files and directories in a path with pagination.
     *
     * @return array{items: array<int, array{name: string, path: string, size: int|null, mimeType: string|null, lastModified: int|null, isDirectory: bool}>, total: int}
     */
    public function listFiles(string $path = '', int $page = 1, int $perPage = 50): array
    {
        $disk = $this->getDisk();
        $path = $this->normalizePath($path);

        $directories = $disk->directories($path);
        $files = $disk->files($path);

        $items = [];
        foreach ($directories as $dir) {
            $name = basename($dir);
            $items[] = [
                'name' => $name,
                'path' => $dir,
                'size' => null,
                'mimeType' => null,
                'lastModified' => $this->getLastModified($disk, $dir),
                'isDirectory' => true,
            ];
        }
        foreach ($files as $filePath) {
            $name = basename($filePath);
            $items[] = [
                'name' => $name,
                'path' => $filePath,
                'size' => $disk->size($filePath),
                'mimeType' => $this->getMimeType($disk, $filePath),
                'lastModified' => $disk->lastModified($filePath),
                'isDirectory' => false,
            ];
        }

        usort($items, function ($a, $b) {
            if ($a['isDirectory'] !== $b['isDirectory']) {
                return $a['isDirectory'] ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        $total = count($items);
        $offset = ($page - 1) * $perPage;
        $items = array_slice($items, $offset, $perPage);

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Get file or directory metadata.
     */
    public function getFileInfo(string $path): ?array
    {
        $disk = $this->getDisk();
        $path = $this->normalizePath($path);

        if ($disk->exists($path)) {
            $isDir = $disk->directoryExists($path);
            return [
                'name' => basename($path),
                'path' => $path,
                'size' => $isDir ? null : $disk->size($path),
                'mimeType' => $isDir ? null : $this->getMimeType($disk, $path),
                'lastModified' => $this->getLastModified($disk, $path),
                'isDirectory' => $isDir,
            ];
        }

        return null;
    }

    /**
     * Upload a file to the given path.
     *
     * @return array{path: string, name: string, size: int}
     */
    public function uploadFile(UploadedFile $file, string $path): array
    {
        $disk = $this->getDisk();
        $path = $this->normalizePath($path);
        $filename = $file->getClientOriginalName();
        $targetPath = $path === '' ? $filename : $path . '/' . $filename;

        $disk->put($targetPath, File::get($file->getRealPath()));

        // Record usage for cloud storage providers
        $this->trackStorageUsage('upload', $file->getSize());

        return [
            'path' => $targetPath,
            'name' => $filename,
            'size' => $file->getSize(),
        ];
    }

    /**
     * Delete a file or directory (recursive).
     */
    public function deleteFile(string $path): bool
    {
        $disk = $this->getDisk();
        $path = $this->normalizePath($path);

        if ($disk->directoryExists($path)) {
            return $disk->deleteDirectory($path);
        }

        return $disk->delete($path);
    }

    /**
     * Rename a file or directory.
     */
    public function renameFile(string $oldPath, string $newName): bool
    {
        $disk = $this->getDisk();
        $oldPath = $this->normalizePath($oldPath);
        $parent = dirname($oldPath);
        $newPath = ($parent === '.' || $parent === '') ? $newName : $parent . '/' . $newName;

        if ($oldPath === $newPath) {
            return true;
        }

        if ($disk->directoryExists($oldPath)) {
            $disk->makeDirectory($newPath);
            $this->moveDirectoryContents($disk, $oldPath, $newPath);
            return $disk->deleteDirectory($oldPath);
        }

        return $disk->move($oldPath, $newPath);
    }

    /**
     * Move a file or directory to a new path.
     */
    public function moveFile(string $from, string $to): bool
    {
        $disk = $this->getDisk();
        $from = $this->normalizePath($from);
        $to = $this->normalizePath($to);
        $name = basename($from);
        $destinationPath = ($to === '' || $to === '.') ? $name : rtrim($to, '/') . '/' . $name;

        if ($from === $destinationPath) {
            return true;
        }

        if ($disk->directoryExists($from)) {
            $disk->makeDirectory($destinationPath);
            $this->moveDirectoryContents($disk, $from, $destinationPath);
            return $disk->deleteDirectory($from);
        }

        return $disk->move($from, $destinationPath);
    }

    /**
     * Stream a file for download.
     */
    public function downloadFile(string $path): StreamedResponse
    {
        $disk = $this->getDisk();
        $path = $this->normalizePath($path);

        if ($disk->directoryExists($path)) {
            throw new \InvalidArgumentException('Cannot download a directory.');
        }

        if (! $disk->exists($path)) {
            throw new \Illuminate\Contracts\Filesystem\FileNotFoundException($path);
        }

        // Record usage for cloud storage providers
        try {
            $size = $disk->size($path);
            $this->trackStorageUsage('download', $size);
        } catch (\Throwable $e) {
            // Don't fail the download if usage tracking fails
        }

        return $disk->download($path, basename($path));
    }

    /**
     * Get a temporary URL for preview (cloud) or null for local.
     */
    public function getPreviewUrl(string $path): ?string
    {
        $disk = $this->getDisk();
        $path = $this->normalizePath($path);

        if ($disk->directoryExists($path) || ! $disk->exists($path)) {
            return null;
        }

        try {
            if (method_exists($disk, 'temporaryUrl')) {
                return $disk->temporaryUrl($path, now()->addMinutes(5));
            }
        } catch (\Throwable $e) {
            // Fall through
        }

        return null;
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path, '/');
        return $path === '' ? '' : $path;
    }

    private function getLastModified(\Illuminate\Contracts\Filesystem\Filesystem $disk, string $path): ?int
    {
        try {
            return $disk->lastModified($path);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function getMimeType(\Illuminate\Contracts\Filesystem\Filesystem $disk, string $path): ?string
    {
        try {
            if (method_exists($disk, 'mimeType')) {
                return $disk->mimeType($path);
            }
            $fullPath = method_exists($disk, 'path') ? $disk->path($path) : null;
            if ($fullPath && is_file($fullPath)) {
                return File::mimeType($fullPath);
            }
        } catch (\Throwable $e) {
            // Fall through
        }
        return null;
    }

    private function moveDirectoryContents(\Illuminate\Contracts\Filesystem\Filesystem $disk, string $from, string $to): void
    {
        $files = $disk->files($from);
        foreach ($files as $file) {
            $disk->move($file, $to . '/' . basename($file));
        }
        $dirs = $disk->directories($from);
        foreach ($dirs as $dir) {
            $dirName = basename($dir);
            $disk->makeDirectory($to . '/' . $dirName);
            $this->moveDirectoryContents($disk, $dir, $to . '/' . $dirName);
            $disk->deleteDirectory($dir);
        }
    }

    /**
     * Track storage usage for cloud providers only (skip local).
     */
    private function trackStorageUsage(string $operation, int $bytes): void
    {
        $diskName = config('filesystems.default', 'local');
        $diskDriver = config("filesystems.disks.{$diskName}.driver", 'local');

        if ($diskDriver === 'local') {
            return;
        }

        // Use the disk name as provider (e.g. s3, gcs, azure, do_spaces, minio, b2)
        // since disk names in this project match the StorageService::PROVIDERS keys
        $provider = $diskName;

        try {
            $userId = auth()->id();
            app(UsageTrackingService::class)->recordStorage($provider, $operation, $bytes, $userId);
        } catch (\Throwable $e) {
            // Silently fail - don't disrupt storage operations
        }
    }

    private function getSettingPrefix(string $provider): ?string
    {
        $prefixes = [
            's3' => 's3_',
            'gcs' => 'gcs_',
            'azure' => 'azure_',
            'do_spaces' => 'do_spaces_',
            'minio' => 'minio_',
            'b2' => 'b2_',
        ];

        return $prefixes[$provider] ?? null;
    }

    private function getConfigKey(array $config, string $provider, string ...$keys): mixed
    {
        $prefixes = [$provider.'_', ''];
        foreach ($keys as $key) {
            foreach ($prefixes as $prefix) {
                $k = $prefix.$key;
                if (array_key_exists($k, $config)) {
                    return $config[$k];
                }
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Storage health, cleanup, analytics & stats
    // -------------------------------------------------------------------------

    /**
     * Get storage health status (permissions, disk space).
     */
    public function getHealth(): array
    {
        $storagePath = storage_path();
        $diskFree = disk_free_space($storagePath);
        $diskTotal = disk_total_space($storagePath);
        $diskFree = $diskFree !== false ? (int) $diskFree : 0;
        $diskTotal = $diskTotal !== false ? (int) $diskTotal : 0;
        $diskUsedPercent = $diskTotal > 0
            ? round((1 - $diskFree / $diskTotal) * 100, 1)
            : 0;

        $checks = [
            'writable' => is_writable($storagePath),
            'disk_free_bytes' => $diskFree,
            'disk_total_bytes' => $diskTotal,
            'disk_used_percent' => $diskUsedPercent,
        ];

        $checks['status'] = $checks['writable'] && $checks['disk_used_percent'] < 90 ? 'healthy' : 'warning';
        $checks['disk_free_formatted'] = $this->formatBytes($checks['disk_free_bytes']);
        $checks['disk_total_formatted'] = $this->formatBytes($checks['disk_total_bytes']);

        return $checks;
    }

    /**
     * Get cleanup suggestions (local driver only).
     */
    public function getCleanupSuggestions(): array
    {
        $driver = SystemSetting::get('driver', 'local', 'storage');
        $suggestions = [
            'cache' => ['count' => 0, 'size' => 0, 'description' => 'Framework cache files'],
            'temp' => ['count' => 0, 'size' => 0, 'description' => 'Temporary files older than 7 days'],
            'old_backups' => ['count' => 0, 'size' => 0, 'description' => 'Backups beyond retention policy'],
        ];
        $totalReclaimable = 0;

        if ($driver !== 'local') {
            return [
                'suggestions' => $suggestions,
                'total_reclaimable' => 0,
                'note' => 'Cleanup available for local storage only.',
            ];
        }

        $cachePath = storage_path('framework/cache/data');
        if (is_dir($cachePath)) {
            $cacheSize = $this->getDirectorySize($cachePath);
            $cacheCount = $this->countFilesInDir($cachePath);
            $suggestions['cache'] = [
                'count' => $cacheCount,
                'size' => $cacheSize,
                'size_formatted' => $this->formatBytes($cacheSize),
                'description' => 'Framework cache files',
            ];
            $totalReclaimable += $cacheSize;
        }

        $tempPath = storage_path('app/temp');
        if (is_dir($tempPath)) {
            $cutoff = time() - (7 * 24 * 60 * 60);
            [$tempCount, $tempSize] = $this->getOldFilesInDir($tempPath, $cutoff);
            $suggestions['temp'] = [
                'count' => $tempCount,
                'size' => $tempSize,
                'size_formatted' => $this->formatBytes($tempSize),
                'description' => 'Temporary files older than 7 days',
            ];
            $totalReclaimable += $tempSize;
        }

        $backupsPath = storage_path('app/backups');
        if (is_dir($backupsPath)) {
            $keepCount = (int) config('backup.scheduled.retention.keep_count', 10);
            $keepDays = (int) config('backup.scheduled.retention.keep_days', 30);
            $cutoff = time() - ($keepDays * 24 * 60 * 60);
            $backupFiles = [];
            foreach (glob($backupsPath . '/*.zip') ?: [] as $f) {
                $mtime = filemtime($f);
                $backupFiles[] = ['path' => $f, 'mtime' => $mtime, 'size' => filesize($f)];
            }
            usort($backupFiles, fn ($a, $b) => $b['mtime'] <=> $a['mtime']);
            $toRemove = [];
            foreach (array_slice($backupFiles, $keepCount) as $f) {
                if ($f['mtime'] < $cutoff) {
                    $toRemove[] = $f;
                }
            }
            $oldBackupSize = array_sum(array_column($toRemove, 'size'));
            $suggestions['old_backups'] = [
                'count' => count($toRemove),
                'size' => $oldBackupSize,
                'size_formatted' => $this->formatBytes($oldBackupSize),
                'description' => 'Backups beyond retention policy',
            ];
            $totalReclaimable += $oldBackupSize;
        }

        return [
            'suggestions' => $suggestions,
            'total_reclaimable' => $totalReclaimable,
            'total_reclaimable_formatted' => $this->formatBytes($totalReclaimable),
        ];
    }

    /**
     * Execute cleanup for a given type.
     *
     * @return array{files_removed: int, bytes_freed: int}
     *
     * @throws \InvalidArgumentException if driver is not local
     */
    public function executeCleanup(string $type): array
    {
        $driver = SystemSetting::get('driver', 'local', 'storage');
        if ($driver !== 'local') {
            throw new \InvalidArgumentException('Cleanup available for local storage only.');
        }

        $freed = 0;
        $count = 0;

        if ($type === 'cache') {
            $cachePath = storage_path('framework/cache/data');
            if (is_dir($cachePath)) {
                $freed = $this->getDirectorySize($cachePath);
                $count = $this->countFilesInDir($cachePath);
                $this->deleteDirectoryContents($cachePath);
            }
        } elseif ($type === 'temp') {
            $tempPath = storage_path('app/temp');
            if (is_dir($tempPath)) {
                $cutoff = time() - (7 * 24 * 60 * 60);
                [$count, $freed] = $this->deleteOldFilesInDir($tempPath, $cutoff);
            }
        } elseif ($type === 'old_backups') {
            $backupsPath = storage_path('app/backups');
            $keepCount = (int) config('backup.scheduled.retention.keep_count', 10);
            $keepDays = (int) config('backup.scheduled.retention.keep_days', 30);
            $cutoff = time() - ($keepDays * 24 * 60 * 60);
            $backupFiles = [];
            foreach (glob($backupsPath . '/*.zip') ?: [] as $f) {
                $backupFiles[] = ['path' => $f, 'mtime' => filemtime($f), 'size' => filesize($f)];
            }
            usort($backupFiles, fn ($a, $b) => $b['mtime'] <=> $a['mtime']);
            foreach (array_slice($backupFiles, $keepCount) as $f) {
                if ($f['mtime'] < $cutoff) {
                    unlink($f['path']);
                    $count++;
                    $freed += $f['size'];
                }
            }
        }

        return ['files_removed' => $count, 'bytes_freed' => $freed];
    }

    /**
     * Get storage analytics (by type, top files, recent files).
     * Local driver only.
     */
    public function getAnalytics(): array
    {
        $driver = SystemSetting::get('driver', 'local', 'storage');
        $analytics = [
            'driver' => $driver,
            'by_type' => [],
            'top_files' => [],
            'recent_files' => [],
        ];

        if ($driver === 'local') {
            $disk = Storage::disk('local');
            $allFiles = $disk->allFiles();

            $byType = [];
            $filesWithMeta = [];

            foreach ($allFiles as $file) {
                try {
                    $size = $disk->size($file);
                    $lastModified = $disk->lastModified($file);
                    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION)) ?: 'none';
                    $byType[$ext] = ($byType[$ext] ?? 0) + $size;
                    $filesWithMeta[] = [
                        'path' => $file,
                        'size' => $size,
                        'lastModified' => $lastModified,
                        'name' => basename($file),
                    ];
                } catch (\Throwable $e) {
                    continue;
                }
            }

            arsort($byType);
            $analytics['by_type'] = $byType;

            usort($filesWithMeta, fn ($a, $b) => $b['size'] <=> $a['size']);
            $topFiles = array_slice($filesWithMeta, 0, 10);
            foreach ($topFiles as &$f) {
                $f['size_formatted'] = $this->formatBytes($f['size']);
                $f['lastModifiedFormatted'] = date('Y-m-d H:i:s', $f['lastModified']);
            }
            $analytics['top_files'] = $topFiles;

            usort($filesWithMeta, fn ($a, $b) => $b['lastModified'] <=> $a['lastModified']);
            $recentFiles = array_slice($filesWithMeta, 0, 10);
            foreach ($recentFiles as &$f) {
                $f['size_formatted'] = $this->formatBytes($f['size']);
                $f['lastModifiedFormatted'] = date('Y-m-d H:i:s', $f['lastModified']);
            }
            $analytics['recent_files'] = $recentFiles;
        } else {
            $analytics['note'] = 'Analytics available for local storage only';
        }

        return $analytics;
    }

    /**
     * Get storage usage statistics.
     */
    public function getStats(): array
    {
        $driver = SystemSetting::get('driver', 'local', 'storage');
        $stats = [
            'driver' => $driver,
            'total_size' => 0,
            'file_count' => 0,
        ];

        if ($driver === 'local') {
            $files = Storage::disk('local')->allFiles();
            $stats['file_count'] = count($files);

            foreach ($files as $file) {
                $stats['total_size'] += Storage::disk('local')->size($file);
            }

            $breakdown = [];
            $directories = ['app', 'app/public', 'app/backups', 'framework/cache', 'framework/sessions', 'logs'];

            foreach ($directories as $dir) {
                $fullPath = storage_path($dir);
                if (is_dir($fullPath)) {
                    $size = $this->getDirectorySize($fullPath);
                    $breakdown[$dir] = [
                        'size' => $size,
                        'size_formatted' => $this->formatBytes($size),
                    ];
                }
            }
            $stats['breakdown'] = $breakdown;
        } elseif (in_array($driver, ['s3', 'gcs', 'azure', 'do_spaces', 'minio', 'b2'], true)) {
            $stats['note'] = 'Cloud storage statistics require provider SDK integration';
        }

        // Format size
        $stats['total_size_formatted'] = $this->formatBytes($stats['total_size']);

        return $stats;
    }

    // -------------------------------------------------------------------------
    // Filesystem helpers
    // -------------------------------------------------------------------------

    /**
     * Get total size of a directory recursively (bytes).
     */
    private function getDirectorySize(string $path): int
    {
        $size = 0;

        if (! is_dir($path)) {
            return 0;
        }

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    private function countFilesInDir(string $path): int
    {
        $count = 0;
        if (! is_dir($path)) {
            return 0;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * @return array{int, int} [count, total_size]
     */
    private function getOldFilesInDir(string $path, int $cutoffTimestamp): array
    {
        $count = 0;
        $size = 0;
        if (! is_dir($path)) {
            return [0, 0];
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getMTime() < $cutoffTimestamp) {
                $count++;
                $size += $file->getSize();
            }
        }
        return [$count, $size];
    }

    private function deleteDirectoryContents(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::CATCH_GET_CHILD),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
    }

    /**
     * @return array{int, int} [count, bytes_freed]
     */
    private function deleteOldFilesInDir(string $path, int $cutoffTimestamp): array
    {
        $count = 0;
        $freed = 0;
        if (! is_dir($path)) {
            return [0, 0];
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getMTime() < $cutoffTimestamp) {
                $freed += $file->getSize();
                unlink($file->getRealPath());
                $count++;
            }
        }
        return [$count, $freed];
    }

    /**
     * Format bytes to human-readable format.
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
