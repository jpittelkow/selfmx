<?php

namespace App\Services\Notifications\Channels;

use App\Models\User;
use App\Services\NotificationTemplateService;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

class WebPushChannel implements ChannelInterface
{
    private string $publicKey;
    private string $privateKey;
    private string $subject;

    public function __construct()
    {
        $this->publicKey = config('notifications.channels.webpush.public_key', '');
        $this->privateKey = config('notifications.channels.webpush.private_key', '');
        $this->subject = config('notifications.channels.webpush.subject', '');
    }

    public function send(User $user, string $type, string $title, string $message, array $data = []): array
    {
        $resolved = $this->resolveContent($user, $type, $title, $message, $data);
        $title = $resolved['title'];
        $message = $resolved['body'];

        $subscriptionData = $user->getSetting('notifications', 'webpush_subscription');

        if (!$subscriptionData) {
            throw new \RuntimeException('Web Push subscription not configured for user');
        }

        if (is_string($subscriptionData)) {
            $subscriptionData = json_decode($subscriptionData, true);
        }

        if (!isset($subscriptionData['endpoint']) || !isset($subscriptionData['keys'])) {
            throw new \RuntimeException('Invalid Web Push subscription format');
        }

        if (!$this->publicKey || !$this->privateKey) {
            throw new \RuntimeException('VAPID keys not configured');
        }

        $payloadArray = [
            'title' => $title,
            'body' => $message,
            'icon' => $data['icon'] ?? '/icon-192.png',
            'badge' => $data['badge'] ?? '/badge.png',
            'tag' => $data['tag'] ?? $type,
            'data' => $data,
            'timestamp' => time() * 1000,
        ];
        $payload = json_encode($payloadArray);

        // Web Push payloads are limited to ~4KB. Leave headroom for encryption overhead.
        $maxPayloadBytes = 3800;
        if (strlen($payload) > $maxPayloadBytes) {
            Log::warning('WebPush payload too large, stripping data', [
                'original_size' => strlen($payload),
                'user_id' => $user->id,
                'type' => $type,
            ]);
            unset($payloadArray['data']);
            $payload = json_encode($payloadArray);

            if (strlen($payload) > $maxPayloadBytes) {
                // Binary search for the right character length that fits the byte limit.
                // mb_substr counts characters but json_encode may expand them (\uXXXX).
                $lo = 50;
                $hi = mb_strlen($payloadArray['body']);
                while ($lo < $hi) {
                    $mid = intdiv($lo + $hi + 1, 2);
                    $payloadArray['body'] = mb_substr($message, 0, $mid) . '...';
                    if (strlen(json_encode($payloadArray)) <= $maxPayloadBytes) {
                        $lo = $mid;
                    } else {
                        $hi = $mid - 1;
                    }
                }
                $payloadArray['body'] = mb_substr($message, 0, $lo) . '...';
                $payload = json_encode($payloadArray);
            }
        }

        $subscription = Subscription::create([
            'endpoint' => $subscriptionData['endpoint'],
            'publicKey' => $subscriptionData['keys']['p256dh'],
            'authToken' => $subscriptionData['keys']['auth'],
        ]);

        $auth = [
            'VAPID' => [
                'subject' => $this->subject ?: config('app.url'),
                'publicKey' => $this->publicKey,
                'privateKey' => $this->privateKey,
            ],
        ];

        $webPush = new WebPush($auth, ['TTL' => 86400], 30);

        $report = $webPush->sendOneNotification($subscription, $payload);

        if (!$report->isSuccess()) {
            $reason = $report->getReason();

            if ($report->isSubscriptionExpired()) {
                $user->settings()
                    ->where('group', 'notifications')
                    ->whereIn('key', ['webpush_subscription', 'webpush_enabled'])
                    ->delete();

                throw new \RuntimeException('Web Push subscription expired and has been removed');
            }

            throw new \RuntimeException('Web Push failed: ' . ($reason ?: 'Unknown error'));
        }

        return [
            'endpoint' => $subscriptionData['endpoint'],
            'sent' => true,
        ];
    }

    public function getName(): string
    {
        return 'Web Push';
    }

    public function isAvailableFor(User $user): bool
    {
        return config('notifications.channels.webpush.enabled', false)
            && !empty($user->getSetting('notifications', 'webpush_subscription'));
    }

    private function resolveContent(User $user, string $type, string $title, string $message, array $data): array
    {
        $service = app(NotificationTemplateService::class);
        $template = $service->getByTypeAndChannel($type, 'push');
        if (!$template) {
            return ['title' => $title, 'body' => $message];
        }
        $variables = array_merge([
            'user' => ['name' => $user->name, 'email' => $user->email],
            'app_name' => config('app.name', 'Sourdough'),
        ], $data);
        return $service->renderTemplate($template, $variables);
    }
}
