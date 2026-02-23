<?php

namespace App\GraphQL\Queries;

use App\Models\UserGroup;

class UserGroupsQuery
{
    public function __invoke($root, array $args, $context): array
    {
        return UserGroup::with('permissions')
            ->withCount('members')
            ->orderBy('name')
            ->get()
            ->map(function ($group) {
                return array_merge($group->toArray(), [
                    'memberCount' => $group->members_count,
                    'permissions' => $group->permissions->pluck('permission')->toArray(),
                ]);
            })
            ->toArray();
    }
}
