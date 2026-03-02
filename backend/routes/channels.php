<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('user.{id}', function (User $user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('audit-logs', function (User $user) {
    return $user->inGroup('admin');
});

Broadcast::channel('app-logs', function (User $user) {
    return $user->inGroup('admin');
});

Broadcast::channel('mail.{id}', function (User $user, $id) {
    return (int) $user->id === (int) $id;
});
