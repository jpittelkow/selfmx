<?php

namespace App\Services\Notifications\Channels;

use App\Models\User;
use App\Services\NotificationTemplateService;
use App\Services\UsageTrackingService;
use Illuminate\Support\Facades\Http;

class TwilioChannel implements ChannelInterface
{
    use ExtractsCountryFromPhone;

    public function send(User $user, string $type, string $title, string $message, array $data = []): array
    {
        $resolved = $this->resolveContent($user, $type, $title, $message, $data);
        $title = $resolved['title'];
        $message = $resolved['body'];

        $phoneNumber = $user->getSetting('notifications', 'twilio_phone_number')
            ?? $user->getSetting('phone_number');

        if (!$phoneNumber) {
            throw new \RuntimeException('Phone number not configured for user');
        }

        $sid = config('notifications.channels.twilio.sid');
        $token = config('notifications.channels.twilio.token');
        $from = config('notifications.channels.twilio.from');

        if (!$sid || !$token || !$from) {
            throw new \RuntimeException('Twilio configuration incomplete');
        }

        $smsBody = "{$title}\n\n{$message}";

        $response = Http::withBasicAuth($sid, $token)
            ->asForm()
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                'To' => $phoneNumber,
                'From' => $from,
                'Body' => $smsBody,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Twilio API error: ' . $response->body());
        }

        // Record usage for integration usage dashboard
        $country = $this->extractCountryFromPhone($phoneNumber);
        app(UsageTrackingService::class)->recordSMS('twilio', $country, $user->id);

        return [
            'sid' => $response->json('sid'),
            'to' => $phoneNumber,
            'sent' => true,
        ];
    }

    public function getName(): string
    {
        return 'SMS (Twilio)';
    }

    private function resolveContent(User $user, string $type, string $title, string $message, array $data): array
    {
        $service = app(NotificationTemplateService::class);
        $template = $service->getByTypeAndChannel($type, 'chat');
        if (!$template) {
            return ['title' => $title, 'body' => $message];
        }
        $variables = array_merge([
            'user' => ['name' => $user->name, 'email' => $user->email],
            'app_name' => config('app.name', 'selfmx'),
        ], $data);
        return $service->renderTemplate($template, $variables);
    }

    public function isAvailableFor(User $user): bool
    {
        $phone = $user->getSetting('notifications', 'twilio_phone_number')
            ?? $user->getSetting('phone_number');

        return config('notifications.channels.twilio.enabled', false)
            && !empty($phone);
    }
}
