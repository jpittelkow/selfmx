<?php

namespace App\Services\Notifications\Channels;

use App\Models\User;
use App\Services\NotificationTemplateService;
use Illuminate\Support\Facades\Http;

class NtfyChannel implements ChannelInterface
{
    public function send(User $user, string $type, string $title, string $message, array $data = []): array
    {
        $resolved = $this->resolveContent($user, $type, $title, $message, $data);
        $title = $resolved['title'];
        $message = $resolved['body'];

        $topic = $user->getSetting('notifications', 'ntfy_topic');

        if (!$topic) {
            throw new \RuntimeException('ntfy topic not configured for user');
        }

        $server = config('notifications.channels.ntfy.server', 'https://ntfy.sh');
        $url = rtrim($server, '/') . '/' . $topic;

        // Map notification type to priority (1=min, 5=max)
        $priority = $this->mapPriority($type);

        $payload = [
            'topic' => $topic,
            'title' => $title,
            'message' => $message,
            'tags' => [$type],
            'priority' => $priority,
        ];

        // Add click URL if provided in data
        if (isset($data['url'])) {
            $payload['click'] = $data['url'];
        }

        // Add actions if provided
        if (isset($data['actions'])) {
            $payload['actions'] = $data['actions'];
        }

        $response = Http::timeout(30)->post($url, $payload);

        if (!$response->successful()) {
            throw new \RuntimeException('ntfy API error: ' . $response->body());
        }

        return [
            'topic' => $topic,
            'sent' => true,
        ];
    }

    public function getName(): string
    {
        return 'ntfy Push';
    }

    public function isAvailableFor(User $user): bool
    {
        return config('notifications.channels.ntfy.enabled', false)
            && !empty($user->getSetting('notifications', 'ntfy_topic'));
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
            'app_name' => config('app.name', 'selfmx'),
        ], $data);
        return $service->renderTemplate($template, $variables);
    }

    /**
     * Map notification type to ntfy priority (1-5).
     * 1 = min, 2 = low, 3 = default, 4 = high, 5 = max
     */
    private function mapPriority(string $type): int
    {
        // Extract base type (e.g., 'error' from 'backup.error')
        $baseType = explode('.', $type)[0];

        return match ($baseType) {
            'error', 'critical' => 5, // max priority
            'warning' => 4,           // high priority
            'info', 'success' => 3,   // default priority
            default => 3,             // default
        };
    }
}
