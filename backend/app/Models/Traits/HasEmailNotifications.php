<?php

namespace App\Models\Traits;

use App\Mail\TemplatedMail;
use App\Services\EmailTemplateService;
use Illuminate\Support\Facades\Mail;

trait HasEmailNotifications
{
    /**
     * Send the password reset notification.
     * Uses the email template system for customizable content.
     */
    public function sendPasswordResetNotification($token): void
    {
        $templateService = app(EmailTemplateService::class);
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $resetUrl = $frontendUrl . '/reset-password?token=' . $token . '&email=' . urlencode($this->email);

        $rendered = $templateService->render('password_reset', [
            'user' => ['name' => $this->name, 'email' => $this->email],
            'reset_url' => $resetUrl,
            'expires_in' => (string) config('auth.passwords.users.expire', 60) . ' minutes',
            'app_name' => config('app.name'),
        ]);

        Mail::to($this->email)->send(new TemplatedMail($rendered));
    }

    /**
     * Send the email verification notification.
     * Uses the email template system for customizable content.
     */
    public function sendEmailVerificationNotification(): void
    {
        $templateService = app(EmailTemplateService::class);
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $verificationUrl = $frontendUrl . '/verify-email?id=' . $this->getKey() . '&hash=' . hash_hmac('sha256', $this->getEmailForVerification(), config('app.key'));

        $rendered = $templateService->render('email_verification', [
            'user' => ['name' => $this->name, 'email' => $this->email],
            'verification_url' => $verificationUrl,
            'app_name' => config('app.name'),
        ]);

        Mail::to($this->email)->send(new TemplatedMail($rendered));
    }
}
