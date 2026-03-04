<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use App\Services\Backup\Destinations\DestinationInterface;
use App\Services\Backup\Destinations\LocalDestination;
use App\Services\Backup\Destinations\S3Destination;
use App\Services\Backup\Destinations\SFTPDestination;
use App\Services\Backup\Destinations\GoogleDriveDestination;
use App\Support\Str;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ScheduledBackup extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'backup:run
                            {--destination= : Specific destination (local, s3, sftp, google_drive)}
                            {--no-cleanup : Skip retention policy cleanup}';

    /**
     * The console command description.
     */
    protected $description = 'Create a scheduled backup and upload to configured destinations';

    public function __construct(
        private BackupService $backupService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $startTime = microtime(true);
        Log::info('Scheduled backup started');

        $this->info('Starting scheduled backup...');

        try {
            // Create the backup
            $result = $this->backupService->create([
                'include_database' => config('backup.scheduled.include_database', true),
                'include_files' => config('backup.scheduled.include_files', true),
                'include_settings' => config('backup.scheduled.include_settings', true),
            ]);

            $this->info("Backup created: {$result['filename']} (" . Str::formatBytes($result['size']) . ')');

            // Get the local backup path
            $localPath = Storage::disk(config('backup.disk', 'backups'))->path($result['filename']);

            // Upload to destinations
            $destinations = $this->getDestinations();
            
            foreach ($destinations as $name => $destination) {
                if (!$destination->isAvailable()) {
                    $this->warn("Skipping unavailable destination: {$name}");
                    continue;
                }

                try {
                    $this->info("Uploading to {$name}...");
                    $uploadResult = $destination->upload($localPath, $result['filename']);
                    $this->info("  -> Uploaded successfully");
                    
                    Log::info("Backup uploaded to {$name}", $uploadResult);
                } catch (\Exception $e) {
                    $this->error("  -> Failed: {$e->getMessage()}");
                    Log::error("Failed to upload backup to {$name}", ['error' => $e->getMessage()]);
                }
            }

            // Apply retention policy
            if (!$this->option('no-cleanup')) {
                $this->applyRetentionPolicy($destinations);
            }

            $durationMs = round((microtime(true) - $startTime) * 1000);
            Log::info('Scheduled backup completed', ['duration_ms' => $durationMs]);
            $this->info('Scheduled backup completed successfully!');

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Backup failed: {$e->getMessage()}");
            Log::error('Scheduled backup failed', ['error' => $e->getMessage()]);

            return Command::FAILURE;
        }
    }

    /**
     * Get configured destinations.
     */
    private function getDestinations(): array
    {
        $specificDestination = $this->option('destination');
        $destinations = [];

        $availableDestinations = [
            'local' => fn() => new LocalDestination(),
            's3' => fn() => new S3Destination(),
            'sftp' => fn() => new SFTPDestination(),
            'google_drive' => fn() => new GoogleDriveDestination(),
        ];

        if ($specificDestination) {
            if (isset($availableDestinations[$specificDestination])) {
                $destinations[$specificDestination] = $availableDestinations[$specificDestination]();
            }
        } else {
            // Use all enabled destinations from config
            $enabledDestinations = config('backup.scheduled.destinations', ['local']);
            
            foreach ($enabledDestinations as $name) {
                if (isset($availableDestinations[$name])) {
                    $destinations[$name] = $availableDestinations[$name]();
                }
            }
        }

        return $destinations;
    }

    /**
     * Apply retention policy to all destinations.
     */
    private function applyRetentionPolicy(array $destinations): void
    {
        $keepCount = config('backup.scheduled.retention.keep_count', 10);
        $keepDays = config('backup.scheduled.retention.keep_days', 30);

        $this->info("Applying retention policy (keep {$keepCount} backups, max {$keepDays} days)...");

        foreach ($destinations as $name => $destination) {
            try {
                $backups = $destination->list();
                $deletedCount = 0;

                foreach ($backups as $index => $backup) {
                    $shouldDelete = false;

                    // Check count limit
                    if ($index >= $keepCount) {
                        $shouldDelete = true;
                    }

                    // Check age limit
                    if (isset($backup['last_modified'])) {
                        $ageInDays = (time() - $backup['last_modified']) / 86400;
                        if ($ageInDays > $keepDays) {
                            $shouldDelete = true;
                        }
                    }

                    if ($shouldDelete) {
                        $destination->delete($backup['filename']);
                        $deletedCount++;
                    }
                }

                if ($deletedCount > 0) {
                    $this->info("  {$name}: Deleted {$deletedCount} old backups");
                }

            } catch (\Exception $e) {
                $this->warn("  {$name}: Failed to apply retention - {$e->getMessage()}");
            }
        }
    }

}
