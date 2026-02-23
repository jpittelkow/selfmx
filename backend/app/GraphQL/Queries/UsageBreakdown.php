<?php

namespace App\GraphQL\Queries;

use App\Models\IntegrationUsage;
use Illuminate\Support\Facades\DB;

class UsageBreakdown
{
    public function __invoke($root, array $args, $context): array
    {
        $query = IntegrationUsage::query()
            ->select(DB::raw('integration, provider, SUM(quantity) as total_quantity, SUM(estimated_cost) as total_cost, COUNT(*) as count'))
            ->groupBy('integration', 'provider')
            ->orderByRaw('SUM(quantity) DESC');

        if (!empty($args['integration'])) {
            $query->where('integration', $args['integration']);
        }

        if (!empty($args['dateFrom'])) {
            $query->where('created_at', '>=', $args['dateFrom']);
        }

        if (!empty($args['dateTo'])) {
            $query->where('created_at', '<=', $args['dateTo'] . ' 23:59:59');
        }

        return $query->get()->map(fn ($row) => [
            'integration' => $row->integration,
            'provider' => $row->provider,
            'totalQuantity' => (float) $row->total_quantity,
            'totalCost' => (float) ($row->total_cost ?? 0),
            'count' => (int) $row->count,
        ])->toArray();
    }
}
