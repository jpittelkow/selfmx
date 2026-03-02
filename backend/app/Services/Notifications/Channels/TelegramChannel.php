<?php

namespace App\Services\Notifications\Channels;

use App\Models\User;
use App\Services\NotificationTemplateService;
use Illuminate\Support\Facades\Http;

class TelegramChannel implements ChannelInterface
{
    public function send(User $user, string $type, string $title, string $message, array $data = []): array
    {
        $resolved = $this->resolveContent($user, $type, $title, $message, $data);
        $title = $resolved['title'];
        $message = $resolved['body'];

        $chatId = $user->getSetting('telegram_chat_id');

        if (!$chatId) {
            throw new \RuntimeException('Telegram chat ID not configured for user');
        }

        $botToken = config('notifications.channels.telegram.bot_token');
        $parseMode = config('notifications.channels.telegram.parse_mode', 'HTML');

        $text = "<b>{$title}</b>\n\n{$message}";

        $response = Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode,
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Telegram API error: ' . $response->body());
        }

        return [
            'chat_id' => $chatId,
            'message_id' => $response->json('result.message_id'),
            'sent' => true,
        ];
    }

    public function getName(): string
    {
        return 'Telegram';
    }

    public function isAvailableFor(User $user): bool
    {
        return config('notifications.channels.telegram.enabled', false)
            && !empty($user->getSetting('telegram_chat_id'));
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
