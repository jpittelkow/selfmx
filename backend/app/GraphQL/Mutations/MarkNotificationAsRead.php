<?php

namespace App\GraphQL\Mutations;

use GraphQL\Error\Error;
use Illuminate\Support\Facades\Auth;

class MarkNotificationAsRead
{
    public function __invoke($root, array $args, $context)
    {
        $user = Auth::guard('api-key')->user();

        $notification = $user->notifications()
            ->where('id', $args['id'])
            ->first();

        if (!$notification) {
            throw new Error('Notification not found.', extensions: ['code' => 'NOT_FOUND']);
        }

        $notification->markAsRead();

        return $notification;
    }
}
