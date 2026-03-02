<?php

namespace App\Services\Notifications\Channels;

use App\Events\NotificationSent;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationTemplateService;

class DatabaseChannel implements ChannelInterface
{
    public function send(User $user, string $type, string $title, string $message, array $data = []): array
    {
        $resolved = $this->resolveContent($user, $type, $title, $message, $data);
        $title = $resolved['title'];
        $message = $resolved['body'];

        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data,
        ]);

        if (config('broadcasting.default') !== 'null') {
            NotificationSent::dispatch($notification, $user);
        }

        return [
            'notification_id' => $notification->id,
            'stored' => true,
        ];
    }

    public function getName(): string
    {
        return 'Database';
    }

    public function isAvailableFor(User $user): bool
    {
        return true;
    }

    private function resolveContent(User $user, string $type, string $title, string $message, array $data): array
    {
        $service = app(NotificationTemplateService::class);
        $template = $service->getByTypeAndChannel($type, 'inapp');
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
