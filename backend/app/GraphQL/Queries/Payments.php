<?php

namespace App\GraphQL\Queries;

use App\GraphQL\Concerns\HandlesPagination;
use App\Models\Payment;

class Payments
{
    use HandlesPagination;

    private const COLUMN_MAP = [
        'CREATED_AT' => 'created_at',
        'AMOUNT' => 'amount',
        'STATUS' => 'status',
    ];

    public function __invoke($root, array $args, $context): array
    {
        $query = Payment::with('user');

        if (!empty($args['filters'])) {
            $f = $args['filters'];
            if (!empty($f['status'])) $query->where('status', $f['status']);
            if (!empty($f['userId'])) $query->where('user_id', $f['userId']);
            if (!empty($f['dateFrom'])) $query->where('created_at', '>=', $f['dateFrom']);
            if (!empty($f['dateTo'])) $query->where('created_at', '<=', $f['dateTo'] . ' 23:59:59');
        }

        $this->applyOrderBy($query, $args['orderBy'] ?? [['column' => 'CREATED_AT', 'direction' => 'DESC']], self::COLUMN_MAP);

        $perPage = $this->clampPerPage($args['first'] ?? 25);
        $paginator = $query->paginate($perPage, ['*'], 'page', $args['page'] ?? 1);

        return $this->paginatorResponse($paginator);
    }
}
