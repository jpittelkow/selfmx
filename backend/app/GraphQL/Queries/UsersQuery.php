<?php

namespace App\GraphQL\Queries;

use App\GraphQL\Concerns\HandlesPagination;
use App\Models\User;

class UsersQuery
{
    use HandlesPagination;

    public function __invoke($root, array $args, $context): array
    {
        $query = User::query()->orderBy('created_at', 'desc');

        if (!empty($args['search'])) {
            $search = str_replace(['%', '_'], ['\\%', '\\_'], $args['search']);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $perPage = $this->clampPerPage($args['first'] ?? 25);
        $paginator = $query->paginate($perPage, ['*'], 'page', $args['page'] ?? 1);

        return $this->paginatorResponse($paginator);
    }
}
