<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Models\NotificationDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationDeliveryController extends Controller
{
    use ApiResponseTrait;

    /**
     * Paginated delivery log with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 50), 100);

        $query = NotificationDelivery::with('user:id,name,email')
            ->orderBy('attempted_at', 'desc');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }
        if ($request->filled('channel')) {
            $query->where('channel', $request->input('channel'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('notification_type')) {
            $escaped = \App\Support\Str::escapeLike($request->input('notification_type'));
            $query->where('notification_type', 'like', '%' . $escaped . '%');
        }
        if ($request->filled('date_from')) {
            $query->whereDate('attempted_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('attempted_at', '<=', $request->input('date_to'));
        }

        return $this->dataResponse($query->paginate($perPage));
    }

    /**
     * Summary stats for the delivery log.
     */
    public function stats(Request $request): JsonResponse
    {
        $days = min(max((int) $request->input('days', 7), 1), 90);
        $since = now()->subDays($days);

        $byChannel = NotificationDelivery::where('attempted_at', '>=', $since)
            ->select(
                'channel',
                DB::raw('count(*) as total'),
                DB::raw('sum(case when status = \'success\' then 1 else 0 end) as successes'),
                DB::raw('sum(case when status = \'failed\' then 1 else 0 end) as failures')
            )
            ->groupBy('channel')
            ->get();

        $byStatus = NotificationDelivery::where('attempted_at', '>=', $since)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get()
            ->pluck('count', 'status');

        return $this->dataResponse([
            'by_channel' => $byChannel,
            'by_status' => $byStatus,
        ]);
    }
}
