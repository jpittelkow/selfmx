<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Services\Notifications\NotificationOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private NotificationOrchestrator $notificationOrchestrator
    ) {}

    /**
     * Get user notifications.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = $user->notifications()->orderBy('created_at', 'desc');

        if ($request->boolean('unread')) {
            $query->unread();
        }

        if ($request->has('category')) {
            $categoryTypes = $this->getCategoryTypeMap();
            $types = $categoryTypes[$request->input('category')] ?? [];
            if ($types) {
                $query->whereIn('type', $types);
            }
        }

        $perPage = min((int) $request->input('per_page', config('app.pagination.default')), 100);
        $notifications = $query->paginate($perPage);

        return $this->dataResponse($notifications);
    }

    /**
     * Get unread notification count.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = $request->user()->notifications()->unread()->count();

        return $this->dataResponse([
            'count' => $count,
        ]);
    }

    /**
     * Mark notifications as read.
     */
    public function markAsRead(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['required', 'uuid'],
        ]);

        $request->user()
            ->notifications()
            ->whereIn('id', $validated['ids'])
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->successResponse('Notifications marked as read');
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $request->user()
            ->notifications()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->successResponse('All notifications marked as read');
    }

    /**
     * Delete a notification.
     */
    public function destroy(Request $request, Notification $notification): JsonResponse
    {
        // Ensure user owns this notification
        if ($notification->user_id !== $request->user()->id) {
            return $this->errorResponse('Notification not found', 404);
        }

        $notification->delete();

        return $this->deleteResponse('Notification deleted');
    }

    /**
     * Delete multiple notifications at once.
     */
    public function destroyBatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['required', 'uuid'],
        ]);

        $deleted = $request->user()
            ->notifications()
            ->whereIn('id', $validated['ids'])
            ->delete();

        return $this->successResponse("{$deleted} notification(s) deleted", [
            'deleted' => $deleted,
        ]);
    }

    /**
     * Test a notification channel.
     */
    public function test(Request $request, string $channel): JsonResponse
    {
        if (!NotificationOrchestrator::isKnownChannel($channel)) {
            return $this->dataResponse([
                'message' => 'Unknown notification channel',
                'error' => "Channel '{$channel}' is not a recognized notification channel.",
            ], 422);
        }

        $user = $request->user();

        try {
            $this->notificationOrchestrator->sendTestNotification($user, $channel);

            return $this->successResponse('Test notification sent', [
                'channel' => $channel,
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Test notification failed', [
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);
            return $this->dataResponse([
                'message' => 'Failed to send test notification',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Diagnose push notification delivery for the current user.
     * Returns the state of every gate that must pass for webpush to work.
     */
    public function diagnosePush(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $channel = 'webpush';

            $channelInstance = $this->notificationOrchestrator->resolveChannel($channel);
            $channelEnabled = config('notifications.channels.webpush.enabled', false);
            $vapidPublicKeySet = !empty(config('notifications.channels.webpush.public_key'));
            $vapidPrivateKeySet = !empty(config('notifications.channels.webpush.private_key'));

            $availableToUsers = filter_var(
                \App\Models\SystemSetting::get('channel_webpush_available', false, 'notifications'),
                FILTER_VALIDATE_BOOLEAN
            );

            $userEnabled = (bool) $user->getSetting('notifications', 'webpush_enabled', false);
            $subscriptionCount = $user->pushSubscriptions()->count();
            $isAvailableFor = $channelInstance?->isAvailableFor($user) ?? false;

            $queueEnabled = (bool) \App\Models\SystemSetting::get(
                'queue_enabled',
                config('notifications.queue.enabled', true),
                'notifications'
            );

            $recentDeliveries = \App\Models\NotificationDelivery::where('user_id', $user->id)
                ->where('channel', $channel)
                ->orderByDesc('attempted_at')
                ->limit(5)
                ->get(['status', 'error', 'notification_type', 'attempted_at']);

            return $this->dataResponse([
                'gates' => [
                    'channel_enabled' => $channelEnabled,
                    'vapid_public_key_set' => $vapidPublicKeySet,
                    'vapid_private_key_set' => $vapidPrivateKeySet,
                    'available_to_users' => $availableToUsers,
                    'user_enabled' => $userEnabled,
                    'subscription_count' => $subscriptionCount,
                    'is_available_for_user' => $isAvailableFor,
                ],
                'queue_enabled' => $queueEnabled,
                'recent_deliveries' => $recentDeliveries,
            ]);
        } catch (\Throwable $e) {
            Log::error('Push notification diagnosis failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Build category->types map from notification templates.
     */
    private function getCategoryTypeMap(): array
    {
        $types = cache()->remember('notification_category_type_map', 300, function () {
            return NotificationTemplate::query()
                ->select('type')
                ->distinct()
                ->pluck('type')
                ->toArray();
        });

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
    }
}
