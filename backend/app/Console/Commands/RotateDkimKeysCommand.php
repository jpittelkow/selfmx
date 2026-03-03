<?php

namespace App\Console\Commands;

use App\Models\EmailDomain;
use App\Services\AuditService;
use App\Services\Email\MailgunProvider;
use App\Services\SettingService;
use Illuminate\Console\Command;

class RotateDkimKeysCommand extends Command
{
    protected $signature = 'email:rotate-dkim {--force : Rotate all Mailgun domains regardless of schedule}';

    protected $description = 'Rotate DKIM keys for Mailgun domains past their rotation interval';

    public function __construct(
        private MailgunProvider $mailgun,
        private SettingService $settingService,
        private AuditService $auditService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $intervalDays = (int) $this->settingService->get('mailgun', 'dkim_rotation_interval_days', 0);

        if ($intervalDays === 0 && !$this->option('force')) {
            $this->info('DKIM auto-rotation is disabled (interval = 0). Use --force to rotate anyway.');
            return self::SUCCESS;
        }

        $domains = EmailDomain::where('provider', 'mailgun')
            ->where('is_active', true)
            ->where('is_verified', true)
            ->get();

        if ($domains->isEmpty()) {
            $this->info('No active, verified Mailgun domains found.');
            return self::SUCCESS;
        }

        $rotated = 0;
        $skipped = 0;

        foreach ($domains as $domain) {
            if (!$this->option('force')) {
                $lastRotation = $domain->dkim_rotated_at ?? $domain->created_at;
                $dueAt = $lastRotation->addDays($intervalDays);

                if (now()->lt($dueAt)) {
                    $skipped++;
                    $this->line("  skip {$domain->name} — next rotation due {$dueAt->toDateString()}");
                    continue;
                }
            }

            $this->line("  rotating {$domain->name}...");
            $result = $this->mailgun->rotateDkimKey($domain->name, $domain->provider_config ?? []);

            if (isset($result['message']) && str_contains(strtolower($result['message'] ?? ''), 'error')) {
                $this->error("  failed for {$domain->name}: " . ($result['message'] ?? 'unknown error'));
                continue;
            }

            $domain->update(['dkim_rotated_at' => now()]);

            $this->auditService->log(
                'email_domain.dkim_rotated',
                $domain,
                [],
                ['scheduled' => true, 'rotated_at' => now()->toIso8601String()],
            );

            $rotated++;
        }

        $this->info("Done. Rotated: {$rotated}, Skipped: {$skipped}.");
        return self::SUCCESS;
    }
}
