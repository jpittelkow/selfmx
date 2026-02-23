<?php

namespace App\GraphQL\Queries;

use App\GraphQL\Concerns\HandlesPagination;
use App\Models\IntegrationUsage;

class UsageStats
{
    use HandlesPagination;

    public function __invoke($root, array $args, $context): array
    {
        $query = IntegrationUsage::query()->orderBy('created_at', 'desc');

        if (!empty($args['integration'])) {
            $query->where('integration', $args['integration']);
        }

        if (!empty($args['dateFrom'])) {
            $query->where('created_at', '>=', $args['dateFrom']);
        }

        if (!empty($args['dateTo'])) {
            $query->where('created_at', '<=', $args['dateTo'] . ' 23:59:59');
        }

        $perPage = $this->clampPerPage($args['first'] ?? 25);
        $paginator = $query->paginate($perPage, ['*'], 'page', $args['page'] ?? 1);

        return $this->paginatorResponse($paginator);
    }
}
