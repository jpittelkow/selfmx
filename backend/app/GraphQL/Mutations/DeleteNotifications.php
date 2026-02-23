<?php

namespace App\GraphQL\Mutations;

use GraphQL\Error\Error;
use Illuminate\Support\Facades\Auth;

class DeleteNotifications
{
    public function __invoke($root, array $args, $context): array
    {
        $user = Auth::guard('api-key')->user();
        $ids = $args['ids'];

        if (count($ids) > 100) {
            throw new Error('Cannot delete more than 100 notifications at once.',
                extensions: ['code' => 'VALIDATION_ERROR']);
        }

        $deleted = $user->notifications()
            ->whereIn('id', $ids)
            ->delete();

        return ['deletedCount' => $deleted];
    }
}
