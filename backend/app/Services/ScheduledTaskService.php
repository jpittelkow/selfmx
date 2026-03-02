<?php

namespace App\Services;

use App\Models\TaskRun;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ScheduledTaskService
{
    /**
     * Commands that may be triggered manually from the Jobs UI.
     * Only these commands are allowed; prevents arbitrary command execution.
     */
    private const TRIGGERABLE_COMMANDS = [
        'backup:run' => [
            'description' => 'Create backup and upload to configured destinations',
            'dangerous' => false,
        ],
        'log:cleanup' => [
            'description' => 'Remove log entries older than retention periods',
            'dangerous' => true,
        ],
        'log:check-suspicious' => [
            'description' => 'Check audit logs for suspicious activity',
            'dangerous' => false,
        ],
        'storage:check-alerts' => [
            'description' => 'Check storage usage against thresholds and notify admins',
            'dangerous' => false,
        ],
        'api-keys:prune-expired' => [
            'description' => 'Soft-delete expired API keys and auto-revoke rotated keys past grace period',
            'dangerous' => false,
        ],
    ];

    /**
     * Minimum seconds between manual runs for rate-limited commands.
     */
    private const RATE_LIMIT_SECONDS = [
        'backup:run' => 300, // 5 minutes
    ];

    /**
     * Get all triggerable command names and metadata.
     *
     * @return array<string, array{description: string, dangerous: bool}>
     */
    public function getTriggerableCommands(): array
    {
        return self::TRIGGERABLE_COMMANDS;
    }

    /**
     * Check if a command is in the whitelist and may be triggered manually.
     */
    public function isTriggerable(string $command): bool
    {
        return isset(self::TRIGGERABLE_COMMANDS[$command]);
    }

    /**
     * Get metadata for a triggerable command, or null if not triggerable.
     *
     * @return array{description: string, dangerous: bool}|null
     */
    public function getCommandMeta(string $command): ?array
    {
        return self::TRIGGERABLE_COMMANDS[$command] ?? null;
    }

    /**
     * Run an artisan command synchronously and return result.
     * Only whitelisted commands may be run.
     *
     * @param  array<string, mixed>  $options  Command options (e.g. ['--dry-run' => true])
     * @return array{success: bool, output: string, duration_ms: int, exit_code: int}
     */
    public function run(string $command, ?int $userId = null, array $options = []): array
    {
        if (! $this->isTriggerable($command)) {
            return [
                'success' => false,
                'output' => 'Command is not allowed to be triggered manually.',
                'duration_ms' => 0,
                'exit_code' => 1,
            ];
        }

        if (isset(self::RATE_LIMIT_SECONDS[$command])) {
            $lastRun = $this->getLastRunAt($command);
            if ($lastRun && $lastRun->diffInSeconds(now()) < self::RATE_LIMIT_SECONDS[$command]) {
                return [
                    'success' => false,
                    'output' => sprintf(
                        'This command may only be run once every %d seconds. Please wait and try again.',
                        self::RATE_LIMIT_SECONDS[$command]
                    ),
                    'duration_ms' => 0,
                    'exit_code' => 1,
                ];
            }
        }

        $start = microtime(true);
        $exitCode = 0;
        $output = '';
        $taskRun = null;
        $hasTaskRunsTable = Schema::hasTable('task_runs');

        try {
            if ($hasTaskRunsTable) {
                $taskRun = TaskRun::create([
                    'command' => $command,
                    'user_id' => $userId,
                    'status' => TaskRun::STATUS_RUNNING,
                    'started_at' => now(),
                ]);
            }

            $input = $this->buildInput($command, $options);
            $exitCode = Artisan::call($command, $input);
            $output = trim(Artisan::output());
        } catch (\Throwable $e) {
            Log::error('Scheduled task run failed', [
                'command' => $command,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            $output = 'Error: ' . $e->getMessage();
            $exitCode = 1;
        }

        $durationMs = (int) round((microtime(true) - $start) * 1000);

        if ($taskRun) {
            $taskRun->update([
                'status' => $exitCode === 0 ? TaskRun::STATUS_SUCCESS : TaskRun::STATUS_FAILED,
                'completed_at' => now(),
                'output' => strlen($output) > 65535 ? substr($output, 0, 65532) . '...' : $output,
                'error' => $exitCode !== 0 ? $output : null,
                'duration_ms' => $durationMs,
            ]);
        }

        return [
            'success' => $exitCode === 0,
            'output' => $output,
            'duration_ms' => $durationMs,
            'exit_code' => $exitCode,
        ];
    }

    /**
     * Get the timestamp of the last run for a command (from task_runs table).
     * Returns null if no run history or table does not exist.
     */
    public function getLastRunAt(string $command): ?\Illuminate\Support\Carbon
    {
        if (! Schema::hasTable('task_runs')) {
            return null;
        }

        $last = TaskRun::where('command', $command)
            ->orderByDesc('started_at')
            ->first();

        return $last ? $last->started_at : null;
    }

    /**
     * Get the last run record for a command (for status and timestamp).
     * Returns null if no run history or table does not exist.
     *
     * @return array{at: string, status: string}|null
     */
    public function getLastRun(string $command): ?array
    {
        if (! Schema::hasTable('task_runs')) {
            return null;
        }

        $last = TaskRun::where('command', $command)
            ->orderByDesc('started_at')
            ->first();

        if (! $last) {
            return null;
        }

        return [
            'at' => $last->started_at->toIso8601String(),
            'status' => $last->status,
        ];
    }

    /**
     * Get recent run history for a command.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, TaskRun>
     */
    public function getRunHistory(string $command, int $limit = 10)
    {
        if (! Schema::hasTable('task_runs')) {
            return collect();
        }

        return TaskRun::where('command', $command)
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Build Artisan command input array from options.
     * Laravel expects option keys with the -- prefix (e.g. --dry-run).
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function buildInput(string $command, array $options): array
    {
        $input = [];

        foreach ($options as $key => $value) {
            $name = is_string($key) && str_starts_with($key, '--')
                ? $key
                : '--' . $key;

            if (is_bool($value)) {
                if ($value) {
                    $input[$name] = true;
                }
            } else {
                $input[$name] = $value;
            }
        }

        return $input;
    }
}
