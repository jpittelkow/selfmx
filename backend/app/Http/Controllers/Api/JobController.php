<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Services\AuditService;
use App\Services\ScheduledTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class JobController extends Controller
{
    use ApiResponseTrait;
    public function __construct(
        private ScheduledTaskService $scheduledTaskService,
        private AuditService $auditService
    ) {}

    /**
     * Get scheduled tasks with enhanced metadata (next run, last run, triggerable).
     */
    public function scheduled(): JsonResponse
    {
        try {
            Artisan::call('schedule:list');
            $output = Artisan::output();

            $tasks = $this->parseScheduleListOutput($output);

            foreach ($tasks as &$task) {
                $command = $task['command'];
                $task['triggerable'] = $this->scheduledTaskService->isTriggerable($command);
                $task['last_run'] = $this->scheduledTaskService->getLastRun($command);

                if ($task['triggerable']) {
                    $meta = $this->scheduledTaskService->getCommandMeta($command);
                    $task['dangerous'] = $meta['dangerous'] ?? false;
                    if (! empty($meta['description'])) {
                        $task['description'] = $meta['description'];
                    }
                } else {
                    $task['dangerous'] = false;
                }
            }
            unset($task);

            $commandNames = array_column($tasks, 'command');
            foreach ($this->scheduledTaskService->getTriggerableCommands() as $cmd => $meta) {
                if (in_array($cmd, $commandNames, true)) {
                    continue;
                }
                $tasks[] = [
                    'command' => $cmd,
                    'schedule' => 'Not scheduled',
                    'description' => $meta['description'] ?? '',
                    'next_run' => null,
                    'triggerable' => true,
                    'dangerous' => $meta['dangerous'] ?? false,
                    'last_run' => $this->scheduledTaskService->getLastRun($cmd),
                ];
            }

            return $this->dataResponse(['tasks' => $tasks]);
        } catch (\Exception $e) {
            return $this->dataResponse([
                'tasks' => [],
                'error' => 'Unable to retrieve scheduled tasks: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Parse Laravel schedule:list output into task array.
     *
     * @return array<int, array{command: string, schedule: string, description: string, next_run: string|null}>
     */
    private function parseScheduleListOutput(string $output): array
    {
        $tasks = [];
        $lines = explode("\n", trim($output));

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || stripos($line, 'Command') !== false && str_contains($line, 'Schedule')) {
                continue;
            }
            if (! str_contains($line, 'artisan ')) {
                continue;
            }
            if (preg_match('/artisan\s+([a-z0-9:_-]+)/i', $line, $cmdMatch)) {
                $command = trim($cmdMatch[1]);
                $schedule = '';
                $nextRun = null;
                if (preg_match('/Next Due:\s*(.+?)(?:\s*$|\s{2,})/i', $line, $nextMatch)) {
                    $nextRun = trim($nextMatch[1]);
                }
                if (preg_match('/^(\S+(?:\s+\S+){4})\s+/', $line, $cronMatch)) {
                    $schedule = trim($cronMatch[1]);
                }
                $description = '';
                if (preg_match('/' . preg_quote($command, '/') . '\s+(.+?)(?:\s+Next Due:|\s*$)/s', $line, $descMatch)) {
                    $description = trim(preg_replace('/\.+/', '', $descMatch[1] ?? ''));
                }
                if ($nextRun) {
                    $schedule = $schedule ? $schedule . ' · ' . $nextRun : $nextRun;
                }
                $tasks[] = [
                    'command' => $command,
                    'schedule' => $schedule ?: 'Scheduled',
                    'description' => $description,
                    'next_run' => $nextRun,
                ];
            }
        }

        return $tasks;
    }

    /**
     * Run a scheduled command manually (whitelisted commands only).
     */
    public function run(Request $request, string $command): JsonResponse
    {
        if (! $this->scheduledTaskService->isTriggerable($command)) {
            return $this->errorResponse('Command is not allowed to be triggered manually.', 403);
        }

        $options = $request->input('options', []);
        if (! is_array($options)) {
            $options = [];
        }

        $userId = auth()->id();
        $result = $this->scheduledTaskService->run($command, $userId, $options);

        $this->auditService->log(
            'scheduled_command_run',
            null,
            [],
            [
                'command' => $command,
                'success' => $result['success'],
                'duration_ms' => $result['duration_ms'],
                'exit_code' => $result['exit_code'],
            ],
            $userId,
            $request
        );

        if ($result['success']) {
            return $this->dataResponse([
                'success' => true,
                'output' => $result['output'],
                'duration_ms' => $result['duration_ms'],
            ]);
        }

        return $this->successResponse($result['output'], [
            'success' => false,
            'output' => $result['output'],
            'duration_ms' => $result['duration_ms'],
        ], 422);
    }

    /**
     * Get queue status.
     */
    public function queueStatus(): JsonResponse
    {
        try {
            $stats = [
                'pending' => DB::table('jobs')->count(),
                'failed' => DB::table('failed_jobs')->count(),
            ];

            // Try to get queue size if using Redis/database queue
            try {
                $stats['queues'] = [];
                $queues = ['default', 'high', 'low']; // Common queue names

                foreach ($queues as $queue) {
                    $stats['queues'][$queue] = DB::table('jobs')
                        ->where('queue', $queue)
                        ->count();
                }
            } catch (\Exception $e) {
                // Queue stats might not be available
            }

            return $this->dataResponse($stats);
        } catch (\Exception $e) {
            return $this->dataResponse([
                'error' => 'Unable to retrieve queue status: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get failed jobs.
     */
    public function failedJobs(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', config('app.pagination.default'));

        $jobs = DB::table('failed_jobs')
            ->orderBy('failed_at', 'desc')
            ->paginate($perPage);

        return $this->dataResponse($jobs);
    }

    /**
     * Retry a failed job.
     */
    public function retryJob(int $id): JsonResponse
    {
        try {
            $job = DB::table('failed_jobs')->where('id', $id)->first();

            if (!$job) {
                return $this->errorResponse('Failed job not found', 404);
            }

            Artisan::call('queue:retry', ['id' => $id]);

            return $this->successResponse('Job queued for retry');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retry job: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete a failed job.
     */
    public function deleteJob(int $id): JsonResponse
    {
        try {
            $deleted = DB::table('failed_jobs')->where('id', $id)->delete();

            if ($deleted) {
                return $this->deleteResponse('Failed job deleted');
            }

            return $this->errorResponse('Failed job not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to delete job: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Retry all failed jobs.
     */
    public function retryAllFailed(): JsonResponse
    {
        try {
            Artisan::call('queue:retry', ['id' => 'all']);

            return $this->successResponse('All failed jobs queued for retry');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retry jobs: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Clear all failed jobs.
     */
    public function clearFailed(): JsonResponse
    {
        try {
            Artisan::call('queue:flush');

            return $this->successResponse('All failed jobs cleared');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to clear jobs: ' . $e->getMessage(), 500);
        }
    }
}
