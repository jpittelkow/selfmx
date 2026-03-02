<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
*/

// Scheduled backups (configurable frequency)
$backupFrequency = config('backup.schedule.frequency', 'daily');
$backupTime = config('backup.schedule.time', '02:00');

if (config('backup.schedule.enabled')) {
    $backup = Schedule::command('backup:run');

    match ($backupFrequency) {
        'hourly' => $backup->hourly(),
        'daily' => $backup->dailyAt($backupTime),
        'weekly' => $backup->weeklyOn(
            config('backup.schedule.day_of_week', 0),
            $backupTime
        ),
        'monthly' => $backup->monthlyOn(
            config('backup.schedule.day_of_month', 1),
            $backupTime
        ),
        default => $backup->dailyAt($backupTime),
    };
}

// Queue worker monitoring
Schedule::command('queue:monitor')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Suspicious activity check (failed logins, bulk exports)
Schedule::command('log:check-suspicious')
    ->everyFifteenMinutes()
    ->withoutOverlapping(15);

// Storage usage alert (when enabled in settings)
Schedule::command('storage:check-alerts')
    ->daily()
    ->withoutOverlapping(60);

// Integration usage budget alerts
Schedule::command('usage:check-budgets')
    ->daily()
    ->withoutOverlapping(60);

// Prune expired API keys and auto-revoke rotated keys past grace period
Schedule::command('api-keys:prune-expired')
    ->daily()
    ->withoutOverlapping(60);

// Process scheduled email sends
Schedule::command('email:process-scheduled')
    ->everyMinute()
    ->withoutOverlapping(30);

// Resurface snoozed emails
Schedule::command('email:process-snoozed')
    ->everyMinute()
    ->withoutOverlapping(30);
