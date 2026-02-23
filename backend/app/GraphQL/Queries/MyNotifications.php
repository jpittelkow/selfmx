<?php

namespace App\GraphQL\Queries;

use App\GraphQL\Concerns\HandlesPagination;
use App\Models\NotificationTemplate;
use Illuminate\Support\Facades\Auth;

class MyNotifications
{
    use HandlesPagination;

    public function __invoke($root, array $args, $context): array
    {
        $user = Auth::guard('api-key')->user();
        $query = $user->notifications()->orderBy('created_at', 'desc');

        if (!empty($args['unreadOnly'])) {
            $query->unread();
        }

        if (!empty($args['category'])) {
            $categoryTypes = $this->getCategoryTypeMap();
            $types = $categoryTypes[$args['category']] ?? [];
            if ($types) {
                $query->whereIn('type', $types);
            }
        }

        $perPage = $this->clampPerPage($args['first'] ?? 25);
        $paginator = $query->paginate($perPage, ['*'], 'page', $args['page'] ?? 1);

        return $this->paginatorResponse($paginator);
    }

    private function getCategoryTypeMap(): array
    {
        return cache()->remember('notification_category_type_map', 300, function () {
            $types = NotificationTemplate::query()
                ->select('type')
                ->distinct()
                ->pluck('type')
                ->toArray();

            $map = [];
            foreach ($types as $type) {
                $parts = explode('.', $type);
                $category = match (true) {
                    str_starts_with($type, 'backup.') => 'backup',
                    str_starts_with($type, 'auth.') => 'auth',
                    str_starts_with($type, 'system.') => 'system',
                    str_starts_with($type, 'llm.') => 'llm',
                    str_starts_with($type, 'storage.') => 'storage',
                    str_starts_with($type, 'usage.') => 'usage',
                    str_starts_with($type, 'payment.') => 'payment',
                    $type === 'suspicious_activity' => 'security',
                    default => $parts[0] ?? 'system',
                };
                $map[$category][] = $type;
            }

            return $map;
        });
    }
}
