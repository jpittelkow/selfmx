<?php

namespace App\Services\Notifications\Channels;

use App\Mail\TemplatedMail;
use App\Models\User;
use App\Services\EmailTemplateService;
use App\Services\RenderedEmail;
use App\Services\UsageTrackingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailChannel implements ChannelInterface
{
    public function send(User $user, string $type, string $title, string $message, array $data = []): array
    {
        $templateService = app(EmailTemplateService::class);

        try {
            $rendered = $templateService->render('notification', [
                'user' => ['name' => $user->name, 'email' => $user->email],
                'title' => $title,
                'message' => $message,
                'action_url' => $data['action_url'] ?? '',
                'action_text' => $data['action_text'] ?? '',
                'app_name' => config('app.name'),
            ]);

            return $this->sendRenderedEmail($user, $type, $rendered);
        } catch (\Exception $e) {
            Log::error('Email send failed', [
                'user_id' => $user->id,
                'to' => $user->email,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Send a pre-rendered email (used by orchestrator for per-type email templates).
     */
    public function sendRendered(User $user, string $type, RenderedEmail $rendered): array
    {
        try {
            return $this->sendRenderedEmail($user, $type, $rendered);
        } catch (\Exception $e) {
            Log::error('Email send failed', [
                'user_id' => $user->id,
                'to' => $user->email,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function sendRenderedEmail(User $user, string $type, RenderedEmail $rendered): array
    {
        Mail::to($user->email)->send(new TemplatedMail($rendered));

        $mailProvider = config('mail.default', 'smtp');
        app(UsageTrackingService::class)->recordEmail($mailProvider, $user->id);

        Log::info('Email sent', [
            'user_id' => $user->id,
            'to' => $user->email,
            'type' => $type,
        ]);

        return [
            'email' => $user->email,
            'sent' => true,
        ];
    }

    public function getName(): string
    {
        return 'Email';
    }

    public function isAvailableFor(User $user): bool
    {
        return !empty($user->email) && config('notifications.channels.email.enabled', false);
    }
}
