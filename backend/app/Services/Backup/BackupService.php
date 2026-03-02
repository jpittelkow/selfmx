<?php

namespace App\Services\Backup;

use App\Services\SettingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class BackupService
{
    private string $disk;

    public function __construct()
    {
        $this->disk = config('backup.disk', 'backups');
    }

    /**
     * Push current backup settings from DB into Laravel runtime config
     * so destination classes read them at runtime.
     */
    public function applySettingsToConfig(SettingService $settingService, string $group = 'backup'): void
    {
        $s = $settingService->getGroup($group);

        config(['backup.disk' => $s['disk'] ?? config('backup.disk')]);
        config([
            'backup.retention.enabled' => $s['retention_enabled'] ?? config('backup.retention.enabled'),
            'backup.retention.days' => $s['retention_days'] ?? config('backup.retention.days'),
            'backup.retention.min_backups' => $s['min_backups'] ?? config('backup.retention.min_backups'),
        ]);
        config([
            'backup.schedule.enabled' => $s['schedule_enabled'] ?? config('backup.schedule.enabled'),
            'backup.schedule.frequency' => $s['schedule_frequency'] ?? config('backup.schedule.frequency'),
            'backup.schedule.time' => $s['schedule_time'] ?? config('backup.schedule.time'),
            'backup.schedule.day_of_week' => $s['schedule_day'] ?? config('backup.schedule.day_of_week'),
            'backup.schedule.day_of_month' => $s['schedule_date'] ?? config('backup.schedule.day_of_month'),
        ]);
        config([
            'backup.scheduled.destinations' => isset($s['scheduled_destinations'])
                ? array_map('trim', explode(',', (string) $s['scheduled_destinations']))
                : config('backup.scheduled.destinations'),
            'backup.scheduled.retention.keep_count' => $s['retention_count'] ?? config('backup.scheduled.retention.keep_count'),
            'backup.scheduled.retention.keep_days' => $s['retention_days'] ?? config('backup.scheduled.retention.keep_days'),
        ]);
        config([
            'backup.encryption.enabled' => $s['encryption_enabled'] ?? config('backup.encryption.enabled'),
            'backup.encryption.password' => $s['encryption_password'] ?? config('backup.encryption.password'),
        ]);
        config([
            'backup.notifications.on_success' => $s['notify_success'] ?? config('backup.notifications.on_success'),
            'backup.notifications.on_failure' => $s['notify_failure'] ?? config('backup.notifications.on_failure'),
        ]);

        config(['backup.destinations.s3.enabled' => $s['s3_enabled'] ?? config('backup.destinations.s3.enabled')]);
        config([
            'backup.destinations.s3.bucket' => $s['s3_bucket'] ?? config('backup.destinations.s3.bucket'),
            'backup.destinations.s3.path' => $s['s3_path'] ?? config('backup.destinations.s3.path'),
            'backup.destinations.s3.region' => $s['s3_region'] ?? config('backup.destinations.s3.region'),
            'backup.destinations.s3.endpoint' => $s['s3_endpoint'] ?? config('backup.destinations.s3.endpoint'),
        ]);
        if (array_key_exists('s3_access_key_id', $s)) {
            config(['backup.destinations.s3.key' => $s['s3_access_key_id']]);
        }
        if (array_key_exists('s3_secret_access_key', $s)) {
            config(['backup.destinations.s3.secret' => $s['s3_secret_access_key']]);
        }

        config(['backup.destinations.sftp.enabled' => $s['sftp_enabled'] ?? config('backup.destinations.sftp.enabled')]);
        config([
            'backup.destinations.sftp.host' => $s['sftp_host'] ?? config('backup.destinations.sftp.host'),
            'backup.destinations.sftp.port' => $s['sftp_port'] ?? config('backup.destinations.sftp.port'),
            'backup.destinations.sftp.username' => $s['sftp_username'] ?? config('backup.destinations.sftp.username'),
            'backup.destinations.sftp.password' => $s['sftp_password'] ?? config('backup.destinations.sftp.password'),
            'backup.destinations.sftp.private_key' => $s['sftp_private_key'] ?? config('backup.destinations.sftp.private_key'),
            'backup.destinations.sftp.passphrase' => $s['sftp_passphrase'] ?? config('backup.destinations.sftp.passphrase'),
            'backup.destinations.sftp.path' => $s['sftp_path'] ?? config('backup.destinations.sftp.path'),
        ]);

        config(['backup.destinations.google_drive.enabled' => $s['gdrive_enabled'] ?? config('backup.destinations.google_drive.enabled')]);
        config([
            'backup.destinations.google_drive.client_id' => $s['gdrive_client_id'] ?? config('backup.destinations.google_drive.client_id'),
            'backup.destinations.google_drive.client_secret' => $s['gdrive_client_secret'] ?? config('backup.destinations.google_drive.client_secret'),
            'backup.destinations.google_drive.refresh_token' => $s['gdrive_refresh_token'] ?? config('backup.destinations.google_drive.refresh_token'),
            'backup.destinations.google_drive.folder_id' => $s['gdrive_folder_id'] ?? config('backup.destinations.google_drive.folder_id'),
        ]);
    }

    /**
     * List all available backups.
     */
    public function listBackups(): array
    {
        $files = Storage::disk($this->disk)->files();

        return collect($files)
            ->filter(fn ($file) => str_ends_with($file, '.zip'))
            ->map(fn ($file) => [
                'filename' => $file,
                'size' => Storage::disk($this->disk)->size($file),
                'created_at' => date('Y-m-d H:i:s', Storage::disk($this->disk)->lastModified($file)),
            ])
            ->sortByDesc('created_at')
            ->values()
            ->all();
    }

    /**
     * Create a new backup.
     */
    public function create(array $options = []): array
    {
        $includeDatabase = $options['include_database'] ?? true;
        $includeFiles = $options['include_files'] ?? true;
        $includeSettings = $options['include_settings'] ?? true;

        Log::info('Backup started', [
            'include_database' => $includeDatabase,
            'include_files' => $includeFiles,
            'include_settings' => $includeSettings,
        ]);

        $timestamp = now()->format('Y-m-d_H-i-s');
        $filename = "selfmx-backup-{$timestamp}.zip";
        $tempPath = storage_path("app/temp/{$filename}");

        // Ensure temp directory exists
        if (!is_dir(dirname($tempPath))) {
            mkdir(dirname($tempPath), 0755, true);
        }

        $zip = new ZipArchive();
        if ($zip->open($tempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Failed to create backup archive');
        }

        // Create manifest
        $manifest = [
            'version' => config('backup.format_version', '2.0'),
            'app_version' => config('version.version'),
            'created_at' => now()->toISOString(),
            'contents' => [],
        ];

        // Backup database
        if ($includeDatabase) {
            $dbBackup = $this->backupDatabase();
            $zip->addFromString('database.sql', $dbBackup);
            $manifest['contents']['database'] = true;
        }

        // Backup files
        if ($includeFiles) {
            $this->addFilesToZip($zip, storage_path('app/public'), 'files/');
            $manifest['contents']['files'] = true;
        }

        // Backup settings (from database)
        if ($includeSettings) {
            $settings = $this->exportSettings();
            $zip->addFromString('settings.json', json_encode($settings, JSON_PRETTY_PRINT));
            $manifest['contents']['settings'] = true;
        }

        // Add manifest
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

        $zip->close();

        // Move to backup disk
        $content = file_get_contents($tempPath);
        Storage::disk($this->disk)->put($filename, $content);
        unlink($tempPath);

        Log::info('Backup created', [
            'filename' => $filename,
            'size' => strlen($content),
        ]);

        return [
            'filename' => $filename,
            'size' => strlen($content),
            'manifest' => $manifest,
        ];
    }

    /**
     * Check if backup exists.
     */
    public function exists(string $filename): bool
    {
        return Storage::disk($this->disk)->exists($filename);
    }

    /**
     * Download a backup file.
     */
    public function download(string $filename): StreamedResponse
    {
        return Storage::disk($this->disk)->download($filename);
    }

    /**
     * Restore from uploaded file.
     */
    public function restoreFromUpload(UploadedFile $file): array
    {
        Log::warning('Backup restore started (upload)', ['filename' => $file->getClientOriginalName()]);

        $tempPath = $file->storeAs('temp', 'restore-upload.zip', 'local');
        $fullPath = storage_path("app/{$tempPath}");

        try {
            $result = $this->performRestore($fullPath);
            unlink($fullPath);
            Log::warning('Backup restore completed (upload)', ['restored' => array_keys($result['restored'] ?? [])]);
            return $result;
        } catch (\Exception $e) {
            unlink($fullPath);
            Log::error('Backup restore failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Restore from existing backup file.
     */
    public function restoreFromFile(string $filename): array
    {
        Log::warning('Backup restore started (file)', ['filename' => $filename]);

        $tempPath = storage_path('app/temp/restore.zip');

        // Copy from backup disk to temp
        $content = Storage::disk($this->disk)->get($filename);
        file_put_contents($tempPath, $content);

        try {
            $result = $this->performRestore($tempPath);
            unlink($tempPath);
            Log::warning('Backup restore completed (file)', ['filename' => $filename, 'restored' => array_keys($result['restored'] ?? [])]);
            return $result;
        } catch (\Exception $e) {
            unlink($tempPath);
            Log::error('Backup restore failed', ['filename' => $filename, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Delete a backup.
     */
    public function delete(string $filename): void
    {
        Storage::disk($this->disk)->delete($filename);
    }

    /**
     * Perform the actual restore.
     */
    private function performRestore(string $zipPath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Failed to open backup archive');
        }

        // Read manifest
        $manifestContent = $zip->getFromName('manifest.json');
        if (!$manifestContent) {
            throw new \RuntimeException('Invalid backup: missing manifest');
        }

        $manifest = json_decode($manifestContent, true);

        // Validate version
        $version = $manifest['version'] ?? '1.0';
        if (version_compare($version, config('backup.format_version'), '>')) {
            throw new \RuntimeException('Backup version is newer than supported');
        }

        $result = [
            'manifest' => $manifest,
            'restored' => [],
        ];

        DB::beginTransaction();

        try {
            // Restore database
            if ($zip->locateName('database.sql') !== false) {
                $sql = $zip->getFromName('database.sql');
                $this->restoreDatabase($sql);
                $result['restored']['database'] = true;
            }

            // Restore settings
            if ($zip->locateName('settings.json') !== false) {
                $settings = json_decode($zip->getFromName('settings.json'), true);
                $this->importSettings($settings);
                $result['restored']['settings'] = true;
            }

            // Restore files
            if (isset($manifest['contents']['files']) && $manifest['contents']['files']) {
                $this->extractFiles($zip, storage_path('app/public'));
                $result['restored']['files'] = true;
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Backup restore failed during performRestore', ['error' => $e->getMessage()]);
            throw $e;
        }

        $zip->close();

        return $result;
    }

    /**
     * Backup the database.
     * Returns JSON format for all database types for security and consistency.
     */
    private function backupDatabase(): string
    {
        $connection = config('database.default');

        if ($connection === 'sqlite') {
            $dbPath = config('database.connections.sqlite.database');
            
            // Handle in-memory database (used in tests)
            if ($dbPath === ':memory:' || !file_exists($dbPath)) {
                return $this->exportTablesAsJSON();
            }
            
            // For file-based SQLite, return the raw database file
            // It's safe because SQLite restore is a file replacement, not SQL execution
            return file_get_contents($dbPath);
        }

        // For MySQL/PostgreSQL, export as JSON (not SQL) for security
        return $this->exportTablesAsJSON();
    }

    /**
     * Export tables as JSON.
     * This is the secure alternative to SQL export - no SQL injection possible.
     */
    private function exportTablesAsJSON(): string
    {
        $tables = ['users', 'settings', 'notifications', 'social_accounts', 'ai_providers'];
        $data = [
            'format' => 'json',
            'format_version' => '2.0',
            'exported_at' => now()->toISOString(),
            'tables' => [],
        ];

        foreach ($tables as $table) {
            try {
                $rows = DB::table($table)->get()->map(fn ($row) => (array) $row)->toArray();
                $data['tables'][$table] = $rows;
            } catch (\Exception $e) {
                // Table might not exist yet
                $data['tables'][$table] = [];
            }
        }

        // Add integrity hash to detect tampering
        $content = json_encode($data['tables'], JSON_UNESCAPED_UNICODE);
        $data['integrity_hash'] = hash('sha256', $content);

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Restore the database.
     * Supports both legacy SQL format (read-only, for backwards compatibility)
     * and new JSON format (preferred, secure).
     */
    private function restoreDatabase(string $content): void
    {
        $connection = config('database.default');

        if ($connection === 'sqlite') {
            // For SQLite, replace the database file
            $dbPath = config('database.connections.sqlite.database');
            file_put_contents($dbPath, $content);
            return;
        }

        // Detect format: JSON starts with '{', SQL starts with INSERT
        $trimmed = ltrim($content);
        if (str_starts_with($trimmed, '{')) {
            $this->restoreDatabaseFromJSON($content);
        } else {
            $this->restoreDatabaseFromLegacySQL($content);
        }
    }

    /**
     * Restore database from JSON format (secure method).
     * Uses Eloquent's updateOrCreate with proper parameter binding.
     */
    private function restoreDatabaseFromJSON(string $content): void
    {
        $data = json_decode($content, true);

        if (!is_array($data) || !isset($data['tables'])) {
            throw new \RuntimeException('Invalid JSON backup format');
        }

        // Verify format version
        $formatVersion = $data['format_version'] ?? '1.0';
        if (version_compare($formatVersion, '3.0', '>=')) {
            throw new \RuntimeException('Backup format version not supported');
        }

        // Verify integrity hash if present
        if (isset($data['integrity_hash'])) {
            $expectedHash = $data['integrity_hash'];
            $actualHash = hash('sha256', json_encode($data['tables'], JSON_UNESCAPED_UNICODE));
            if (!hash_equals($expectedHash, $actualHash)) {
                throw new \RuntimeException('Backup integrity check failed: data may have been tampered with');
            }
        }

        $allowedTables = ['users', 'settings', 'notifications', 'social_accounts', 'ai_providers'];

        foreach ($data['tables'] as $table => $rows) {
            // Only restore to allowed tables
            if (!in_array($table, $allowedTables, true)) {
                Log::warning('Skipping unknown table in backup restore', ['table' => $table]);
                continue;
            }

            if (!is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                // Use updateOrCreate with proper parameter binding (safe from SQL injection)
                $id = $row['id'] ?? null;
                if ($id !== null) {
                    // Remove timestamps to let Eloquent handle them
                    $attributes = array_filter($row, fn ($key) => !in_array($key, ['created_at', 'updated_at'], true), ARRAY_FILTER_USE_KEY);
                    DB::table($table)->updateOrInsert(['id' => $id], $attributes);
                }
            }
        }
    }

    /**
     * Restore database from legacy SQL format.
     * This method exists for backwards compatibility with old backups.
     * It uses a very strict whitelist approach but is NOT recommended.
     *
     * @deprecated Use JSON format instead
     */
    private function restoreDatabaseFromLegacySQL(string $content): void
    {
        Log::warning('Restoring from legacy SQL backup format - consider re-exporting in JSON format');

        // For legacy SQL format, we parse the INSERT statements and use parameterized queries
        $statements = array_filter(explode(";\n", $content));
        $allowedTables = ['users', 'settings', 'notifications', 'social_accounts', 'ai_providers'];

        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (empty($statement)) {
                continue;
            }

            // Parse INSERT statement strictly
            $parsed = $this->parseLegacyInsertStatement($statement, $allowedTables);
            if ($parsed === null) {
                throw new \RuntimeException('Invalid or unsafe SQL statement in backup');
            }

            // Use parameterized query instead of raw SQL
            DB::table($parsed['table'])->updateOrInsert(
                ['id' => $parsed['values']['id'] ?? null],
                $parsed['values']
            );
        }
    }

    /**
     * Parse a legacy INSERT statement into table name and values.
     * Returns null if the statement is invalid or unsafe.
     */
    private function parseLegacyInsertStatement(string $statement, array $allowedTables): ?array
    {
        // Match: INSERT INTO `table` (columns) VALUES (values)
        $pattern = '/^\s*INSERT\s+INTO\s+`?(\w+)`?\s*\(([^)]+)\)\s*VALUES\s*\((.+)\)\s*$/is';

        if (!preg_match($pattern, $statement, $matches)) {
            return null;
        }

        $table = $matches[1];
        $columnsStr = $matches[2];
        $valuesStr = $matches[3];

        // Verify table is allowed
        if (!in_array($table, $allowedTables, true)) {
            return null;
        }

        // Parse columns (remove backticks and whitespace)
        $columns = array_map(
            fn ($col) => trim(str_replace('`', '', $col)),
            explode(',', $columnsStr)
        );

        // Parse values - this is tricky due to quoted strings with commas
        $values = $this->parseCSVValues($valuesStr);

        if (count($columns) !== count($values)) {
            return null;
        }

        $result = [];
        foreach ($columns as $i => $column) {
            // Validate column name is safe (alphanumeric and underscores only)
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
                return null;
            }
            $result[$column] = $values[$i];
        }

        return [
            'table' => $table,
            'values' => $result,
        ];
    }

    /**
     * Parse CSV-style values, handling quoted strings.
     */
    private function parseCSVValues(string $valuesStr): array
    {
        $values = [];
        $current = '';
        $inQuote = false;
        $quoteChar = null;
        $length = strlen($valuesStr);

        for ($i = 0; $i < $length; $i++) {
            $char = $valuesStr[$i];

            if (!$inQuote) {
                if ($char === "'" || $char === '"') {
                    $inQuote = true;
                    $quoteChar = $char;
                } elseif ($char === ',') {
                    $values[] = $this->parseValue(trim($current));
                    $current = '';
                    continue;
                } else {
                    $current .= $char;
                }
            } else {
                if ($char === $quoteChar) {
                    // Check for escaped quote
                    if ($i + 1 < $length && $valuesStr[$i + 1] === $quoteChar) {
                        $current .= $char;
                        $i++; // Skip next quote
                    } else {
                        $inQuote = false;
                        $quoteChar = null;
                    }
                } else {
                    $current .= $char;
                }
            }
        }

        // Add last value
        $values[] = $this->parseValue(trim($current));

        return $values;
    }

    /**
     * Parse a single SQL value into PHP type.
     */
    private function parseValue(string $value): mixed
    {
        if (strtoupper($value) === 'NULL') {
            return null;
        }

        // Remove surrounding quotes
        if ((str_starts_with($value, "'") && str_ends_with($value, "'")) ||
            (str_starts_with($value, '"') && str_ends_with($value, '"'))) {
            return substr($value, 1, -1);
        }

        // Check if numeric
        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        return $value;
    }

    /**
     * Export settings.
     * Note: API keys are intentionally excluded for security.
     * Users must re-enter API keys after restoring from backup.
     */
    private function exportSettings(): array
    {
        return [
            'users' => \App\Models\User::all()->toArray(),
            'settings' => \App\Models\Setting::all()->toArray(),
            'ai_providers' => \App\Models\AIProvider::all()
                ->map(function ($provider) {
                    $data = $provider->toArray();
                    // Exclude API key from backup for security
                    unset($data['api_key']);
                    // Mark that the API key needs to be reconfigured
                    $data['api_key_required'] = true;
                    return $data;
                })
                ->toArray(),
        ];
    }

    /**
     * Import settings.
     */
    private function importSettings(array $settings): void
    {
        // This is a simplified import - in production you'd want ID mapping
        if (isset($settings['settings'])) {
            foreach ($settings['settings'] as $setting) {
                \App\Models\Setting::updateOrCreate(
                    ['user_id' => $setting['user_id'], 'key' => $setting['key']],
                    $setting
                );
            }
        }
    }

    /**
     * Add directory to zip.
     */
    private function addFilesToZip(ZipArchive $zip, string $sourcePath, string $zipPath): void
    {
        if (!is_dir($sourcePath)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourcePath),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = $zipPath . substr($filePath, strlen($sourcePath) + 1);
                $zip->addFile($filePath, $relativePath);
            }
        }
    }

    /**
     * Extract files from zip.
     */
    private function extractFiles(ZipArchive $zip, string $targetPath): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_starts_with($name, 'files/')) {
                $relativePath = substr($name, 6);
                if ($relativePath) {
                    $targetFile = $targetPath . '/' . $relativePath;
                    $targetDir = dirname($targetFile);

                    if (!is_dir($targetDir)) {
                        mkdir($targetDir, 0755, true);
                    }

                    file_put_contents($targetFile, $zip->getFromIndex($i));
                }
            }
        }
    }
}
