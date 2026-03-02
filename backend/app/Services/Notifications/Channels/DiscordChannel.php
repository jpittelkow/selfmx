<?php

namespace App\Services\Notifications\Channels;

use App\Models\User;
use App\Services\NotificationTemplateService;
use Illuminate\Support\Facades\Http;

class DiscordChannel implements ChannelInterface
{
    public function send(User $user, string $type, string $title, string $message, array $data = []): array
    {
        $resolved = $this->resolveContent($user, $type, $title, $message, $data);
        $title = $resolved['title'];
        $message = $resolved['body'];

        // User-specific webhook or global webhook
        $webhookUrl = $user->getSetting('notifications', 'discord_webhook_url')
            ?? config('notifications.channels.discord.webhook_url');

        if (!$webhookUrl) {
            throw new \RuntimeException('Discord webhook URL not configured');
        }

        $payload = [
            'username' => config('notifications.channels.discord.username', 'selfmx'),
            'embeds' => [
                [
                    'title' => $title,
                    'description' => $message,
                    'color' => $this->getColorForType($type),
                    'timestamp' => now()->toISOString(),
                ],
            ],
        ];

        if ($avatarUrl = config('notifications.channels.discord.avatar_url')) {
            $payload['avatar_url'] = $avatarUrl;
        }

        $response = Http::post($webhookUrl, $payload);

        if (!$response->successful()) {
            throw new \RuntimeException('Discord webhook error: ' . $response->body());
        }

        return [
            'sent' => true,
        ];
    }

    public function getName(): string
    {
        return 'Discord';
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
        // User-configurable channel: available if user has webhook OR global webhook exists
        $userWebhook = $user->getSetting('notifications', 'discord_webhook_url');
        $globalWebhook = config('notifications.channels.discord.webhook_url');

        return !empty($userWebhook) || !empty($globalWebhook);
    }

    private function getColorForType(string $type): int
    {
        return match ($type) {
            'error' => 0xFF0000,    // Red
            'warning' => 0xFFA500,  // Orange
            'success' => 0x00FF00,  // Green
            'info' => 0x0099FF,     // Blue
            default => 0x7289DA,    // Discord blurple
        };
    }
}
