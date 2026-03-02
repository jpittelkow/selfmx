<?php

namespace App\Services\Notifications\Channels;

use App\Models\User;
use App\Services\NotificationTemplateService;
use App\Services\UsageTrackingService;
use Illuminate\Support\Facades\Http;

class VonageChannel implements ChannelInterface
{
    use ExtractsCountryFromPhone;

    public function send(User $user, string $type, string $title, string $message, array $data = []): array
    {
        $resolved = $this->resolveContent($user, $type, $title, $message, $data);
        $title = $resolved['title'];
        $message = $resolved['body'];

        $phoneNumber = $user->getSetting('notifications', 'vonage_phone_number')
            ?? $user->getSetting('phone_number');

        if (!$phoneNumber) {
            throw new \RuntimeException('Phone number not configured for user');
        }

        $apiKey = config('notifications.channels.vonage.api_key');
        $apiSecret = config('notifications.channels.vonage.api_secret');
        $from = config('notifications.channels.vonage.from', 'selfmx');

        if (!$apiKey || !$apiSecret) {
            throw new \RuntimeException('Vonage configuration incomplete');
        }

        $smsBody = "{$title}\n\n{$message}";

        // Vonage SMS API
        $response = Http::post('https://rest.nexmo.com/sms/json', [
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
            'to' => $this->formatPhoneNumber($phoneNumber),
            'from' => $from,
            'text' => $smsBody,
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Vonage API error: ' . $response->body());
        }

        $responseData = $response->json();

        // Vonage returns status in messages array
        if (isset($responseData['messages'][0]['status']) && $responseData['messages'][0]['status'] !== '0') {
            throw new \RuntimeException('Vonage SMS failed: ' . ($responseData['messages'][0]['error-text'] ?? 'Unknown error'));
        }

        // Record usage for integration usage dashboard
        $country = $this->extractCountryFromPhone($phoneNumber);
        app(UsageTrackingService::class)->recordSMS('vonage', $country, $user->id);

        return [
            'message_id' => $responseData['messages'][0]['message-id'] ?? null,
            'to' => $phoneNumber,
            'sent' => true,
        ];
    }

    /**
     * Format phone number for Vonage (remove + and spaces).
     */
    private function formatPhoneNumber(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone);
    }

    public function getName(): string
    {
        return 'SMS (Vonage)';
    }

    public function isAvailableFor(User $user): bool
    {
        $phone = $user->getSetting('notifications', 'vonage_phone_number')
            ?? $user->getSetting('phone_number');

        return config('notifications.channels.vonage.enabled', false)
            && !empty($phone);
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
}
