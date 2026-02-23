<?php

namespace App\GraphQL\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

trait HandlesPagination
{
    protected function paginatorResponse(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => $paginator->items(),
            'paginatorInfo' => [
                'count' => $paginator->count(),
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'hasMorePages' => $paginator->hasMorePages(),
            ],
        ];
    }

    protected function clampPerPage(int $requested): int
    {
        $max = (int) config('graphql.max_result_size', 100);
        return min(max($requested, 1), $max);
    }

    protected function applyOrderBy($query, array $orderBy, array $columnMap): void
    {
        foreach ($orderBy as $order) {
            $col = $columnMap[$order['column']] ?? 'created_at';
            $dir = strtolower($order['direction'] ?? 'desc');
            $query->orderBy($col, $dir);
        }
    }
}
