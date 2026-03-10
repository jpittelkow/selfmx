<?php

namespace App\Services;

class EmailConfigService
{
    private const PLACEHOLDER_FROM = 'hello@example.com';

    private const DEV_MAILERS = ['log', 'array'];

    public function __construct(
        private SettingService $settingService
    ) {}

    /**
     * Whether email (SMTP/provider) is properly configured for sending.
     */
    public function isConfigured(): bool
    {
        $status = $this->getStatus();

        return $status['configured'];
    }

    /**
     * Get email configuration status for admin/API.
     *
     * @return array{configured: bool, provider: string, missing_fields: array<string>}
     */
    public function getStatus(): array
    {
        $settings = $this->settingService->getGroup('mail');
        $mailer = (string) ($settings['mailer'] ?? 'log');

        if (in_array($mailer, self::DEV_MAILERS, true)) {
            return [
                'configured' => false,
                'provider' => $mailer,
                'missing_fields' => ['mailer must be smtp, mailgun, ses, or postmark'],
            ];
        }

        $fromAddress = trim((string) ($settings['from_address'] ?? ''));
        if ($fromAddress === '' || $fromAddress === self::PLACEHOLDER_FROM) {
            return [
                'configured' => false,
                'provider' => $mailer,
                'missing_fields' => ['from_address must be set to a real email address'],
            ];
        }

        $missing = match ($mailer) {
            'smtp' => $this->validateSmtp($settings),
            'mailgun' => $this->validateMailgun($settings),
            'ses' => $this->validateSes($settings),
            'postmark' => $this->validatePostmark($settings),
            default => ['mailer must be smtp, mailgun, ses, or postmark'],
        };

        return [
            'configured' => empty($missing),
            'provider' => $mailer,
            'missing_fields' => $missing,
        ];
    }

    /**
     * Apply mail settings to Laravel runtime config for the current request.
     *
     * @param array<string, mixed> $settings  Settings keyed by schema names (mailer, smtp_host, etc.)
     */
    public function applySettingsToConfig(array $settings): void
    {
        if (isset($settings['mailer'])) {
            config(['mail.default' => $settings['mailer']]);
        }

        // SMTP
        config([
            'mail.mailers.smtp.host' => $settings['smtp_host'] ?? config('mail.mailers.smtp.host'),
            'mail.mailers.smtp.port' => $settings['smtp_port'] ?? config('mail.mailers.smtp.port'),
            'mail.mailers.smtp.encryption' => $settings['smtp_encryption'] ?? config('mail.mailers.smtp.encryption'),
            'mail.mailers.smtp.username' => $settings['smtp_username'] ?? config('mail.mailers.smtp.username'),
            'mail.mailers.smtp.password' => $settings['smtp_password'] ?? config('mail.mailers.smtp.password'),
        ]);

        // Mailgun
        if (!empty($settings['mailgun_domain'])) {
            config(['services.mailgun.domain' => $settings['mailgun_domain']]);
        }
        if (!empty($settings['mailgun_secret'])) {
            config(['services.mailgun.secret' => $settings['mailgun_secret']]);
        }

        // SES
        if (!empty($settings['ses_key'])) {
            config(['services.ses.key' => $settings['ses_key']]);
        }
        if (!empty($settings['ses_secret'])) {
            config(['services.ses.secret' => $settings['ses_secret']]);
        }
        if (!empty($settings['ses_region'])) {
            config(['services.ses.region' => $settings['ses_region']]);
        }

        // Postmark
        if (!empty($settings['postmark_token'])) {
            config(['services.postmark.token' => $settings['postmark_token']]);
        }

        // From address/name
        if (isset($settings['from_address'])) {
            config(['mail.from.address' => $settings['from_address']]);
        }
        if (isset($settings['from_name'])) {
            config(['mail.from.name' => $settings['from_name']]);
        }
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string>
     */
    private function validateSmtp(array $settings): array
    {
        $missing = [];
        $host = trim((string) ($settings['smtp_host'] ?? ''));
        $port = $settings['smtp_port'] ?? null;
        if ($host === '') {
            $missing[] = 'smtp_host';
        }
        if ($port === null || $port === '') {
            $missing[] = 'smtp_port';
        }

        return $missing;
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string>
     */
    private function validateMailgun(array $settings): array
    {
        $missing = [];
        if (empty(trim((string) ($settings['mailgun_domain'] ?? '')))) {
            $missing[] = 'mailgun_domain';
        }
        if (empty(trim((string) ($settings['mailgun_secret'] ?? '')))) {
            $missing[] = 'mailgun_secret';
        }

        return $missing;
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string>
     */
    private function validateSes(array $settings): array
    {
        $missing = [];
        if (empty(trim((string) ($settings['ses_key'] ?? '')))) {
            $missing[] = 'ses_key';
        }
        if (empty(trim((string) ($settings['ses_secret'] ?? '')))) {
            $missing[] = 'ses_secret';
        }
        if (empty(trim((string) ($settings['ses_region'] ?? '')))) {
            $missing[] = 'ses_region';
        }

        return $missing;
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string>
     */
    private function validatePostmark(array $settings): array
    {
        $missing = [];
        if (empty(trim((string) ($settings['postmark_token'] ?? '')))) {
            $missing[] = 'postmark_token';
        }

        return $missing;
    }
}
