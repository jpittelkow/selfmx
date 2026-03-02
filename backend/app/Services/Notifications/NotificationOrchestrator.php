<?php

namespace App\Services\Notifications;

use App\Jobs\SendNotificationChannelJob;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Models\Notification;
use App\Models\SystemSetting;
use App\Services\NotificationTemplateService;
use App\Services\NovuService;
use App\Services\RenderedEmail;
use App\Services\Notifications\Channels\ChannelInterface;
use App\Services\Notifications\Channels\DatabaseChannel;
use App\Services\Notifications\Channels\EmailChannel;
use App\Services\Notifications\Channels\TelegramChannel;
use App\Services\Notifications\Channels\DiscordChannel;
use App\Services\Notifications\Channels\SlackChannel;
use App\Services\Notifications\Channels\TwilioChannel;
use App\Services\Notifications\Channels\SignalChannel;
use App\Services\Notifications\Channels\MatrixChannel;
use App\Services\Notifications\Channels\VonageChannel;
use App\Services\Notifications\Channels\SNSChannel;
use App\Services\Notifications\Channels\WebPushChannel;
use App\Services\Notifications\Channels\FCMChannel;
use App\Services\Notifications\Channels\NtfyChannel;
use Illuminate\Support\Facades\Log;

class NotificationOrchestrator
{
    use NotificationChannelMetadata;
    private array $channelInstances = [];
    private array $typePreferencesCache = [];

    /** Channels that always run synchronously (in-app inbox must be immediate). */
    private const SYNC_ONLY_CHANNELS = ['database'];

    public function __construct(
        private NotificationTemplateService $notificationTemplateService,
        private NovuService $novuService,
        private NotificationRateLimiter $rateLimiter
    ) {}

    /**
     * Map channel identifier to channel group for template lookup.
     */
    private function channelToGroup(string $channel): string
    {
        return match ($channel) {
            'database' => 'inapp',
            'email' => 'email',
            'webpush', 'fcm', 'ntfy' => 'push',
            'telegram', 'discord', 'slack', 'twilio', 'signal', 'matrix', 'vonage', 'sns' => 'chat',
            default => 'inapp',
        };
    }

    /**
     * Send a notification by type using per-channel templates (push, inapp, chat, email).
     * Variables are merged with user and app_name; templates are rendered per channel group.
     * For email: uses per-type email template when available, falls back to generic notification template.
     * When Novu is enabled, delegates to Novu first. Falls back to local channels on failure.
     */
    public function sendByType(
        User $user,
        string $type,
        array $variables = [],
        ?array $channels = null
    ): array {
        $baseVariables = [
            'user' => ['name' => $user->name, 'email' => $user->email],
            'app_name' => config('app.name', 'selfmx'),
        ];
        $results = [];
        if ($this->novuService->isEnabled() && $this->novuService->getWorkflowIdForType($type) !== null) {
            $novuResult = $this->sendViaNovu($user, $type, array_merge($baseVariables, $variables));
            if ($novuResult['novu']['success'] ?? false) {
                return $novuResult;
            }
            $this->writeDelivery($user->id, $type, 'novu', NotificationDelivery::STATUS_FAILED, $novuResult['novu']['error'] ?? 'unknown');
            Log::warning('Novu delivery failed, falling back to local channels', [
                'type' => $type,
                'user' => $user->id,
                'novu_error' => $novuResult['novu']['error'] ?? 'unknown',
            ]);
            $results = $novuResult;
        }

        $channels = $channels ?? $this->getDefaultChannels();
        $callerVariables = $variables;
        $mergedVariables = array_merge($baseVariables, $variables);

        foreach ($channels as $channel) {
            try {
                $channelInstance = $this->resolveChannel($channel);

                if (!$channelInstance || !$this->isChannelEnabled($channel)) {
                    Log::debug("Notification channel {$channel} skipped: not resolved or not enabled", [
                        'user_id' => $user->id, 'type' => $type,
                        'resolved' => $channelInstance !== null,
                        'enabled' => $this->isChannelEnabled($channel),
                    ]);
                    continue;
                }

                if (!$this->isChannelAvailableToUsers($channel)) {
                    Log::debug("Notification channel {$channel} skipped: not available to users", [
                        'user_id' => $user->id, 'type' => $type,
                    ]);
                    continue;
                }

                if (!$this->isUserChannelEnabled($user, $channel, $type)) {
                    Log::debug("Notification channel {$channel} skipped: not enabled by user", [
                        'user_id' => $user->id, 'type' => $type,
                    ]);
                    continue;
                }

                if (!$channelInstance->isAvailableFor($user)) {
                    Log::debug("Notification channel {$channel} skipped: not available for user", [
                        'user_id' => $user->id, 'type' => $type,
                    ]);
                    continue;
                }

                // Rate limit check
                if ($this->rateLimiter->isLimited($user, $channel)) {
                    $this->writeDelivery($user->id, $type, $channel, NotificationDelivery::STATUS_RATE_LIMITED);
                    $results[$channel] = ['success' => false, 'rate_limited' => true];
                    continue;
                }

                $channelGroup = $this->channelToGroup($channel);
                $title = null;
                $message = null;

                if ($channelGroup === 'email') {
                    // Use per-type email template; skip if none exists
                    try {
                        $rendered = $this->notificationTemplateService->render($type, 'email', $mergedVariables);
                        $bodyHtml = $rendered['body'];
                        $bodyText = strip_tags(str_replace(
                            ['</p>', '<br>', '<br/>', '<br />'],
                            "\n",
                            $bodyHtml
                        ));
                        $renderedEmail = new RenderedEmail(
                            $rendered['title'],
                            $bodyHtml,
                            $bodyText
                        );
                        $title = $rendered['title'];
                        $message = $bodyText;
                    } catch (\InvalidArgumentException $e) {
                        Log::warning("No per-type email template, skipping email channel", [
                            'type' => $type,
                            'error' => $e->getMessage(),
                        ]);
                        continue;
                    }

                    // Email always runs sync (rendered content can't be serialized to a job easily)
                    try {
                        /** @var EmailChannel $channelInstance */
                        $channelInstance->sendRendered($user, $type, $renderedEmail);
                        $this->writeDelivery($user->id, $type, $channel, NotificationDelivery::STATUS_SUCCESS);
                        $results[$channel] = ['success' => true];
                    } catch (\Exception $e) {
                        $this->writeDelivery($user->id, $type, $channel, NotificationDelivery::STATUS_FAILED, $e->getMessage());
                        Log::error("Notification channel {$channel} failed", [
                            'user' => $user->id,
                            'type' => $type,
                            'error' => $e->getMessage(),
                        ]);
                        $results[$channel] = ['success' => false, 'error' => $e->getMessage()];
                    }
                    continue;
                }

                try {
                    $rendered = $this->notificationTemplateService->render($type, $channelGroup, $mergedVariables);
                    $title = $rendered['title'];
                    $message = $rendered['body'];
                } catch (\InvalidArgumentException $e) {
                    Log::warning("Notification template not found, skipping channel", [
                        'type' => $type,
                        'channel' => $channel,
                        'channel_group' => $channelGroup,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }

                // Dispatch: sync for database, async for others when queue is enabled
                $results[$channel] = $this->dispatchChannel(
                    $user, $channel, $type, $title, $message, $callerVariables
                );
            } catch (\Exception $e) {
                $this->writeDelivery($user->id, $type, $channel, NotificationDelivery::STATUS_FAILED, $e->getMessage());
                Log::error("Notification channel {$channel} failed", [
                    'user' => $user->id,
                    'type' => $type,
                    'error' => $e->getMessage(),
                ]);
                $results[$channel] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->typePreferencesCache = [];

        return $results;
    }

    /**
     * Send a notification to a user via specified channels.
     * When Novu is enabled and a workflow exists for this type, delegates to Novu first. Falls back to local channels on failure.
     */
    public function send(
        User $user,
        string $type,
        string $title,
        string $message,
        array $data = [],
        ?array $channels = null
    ): array {
        $results = [];
        if ($this->novuService->isEnabled()) {
            $workflowId = $this->novuService->getWorkflowIdForType($type);
            if ($workflowId !== null) {
                $payload = array_merge($data, [
                    'title' => $title,
                    'message' => $message,
                    'user' => ['name' => $user->name, 'email' => $user->email],
                    'app_name' => config('app.name', 'selfmx'),
                ]);
                $novuResult = $this->sendViaNovu($user, $type, $payload);
                if ($novuResult['novu']['success'] ?? false) {
                    return $novuResult;
                }
                $this->writeDelivery($user->id, $type, 'novu', NotificationDelivery::STATUS_FAILED, $novuResult['novu']['error'] ?? 'unknown');
                Log::warning('Novu delivery failed, falling back to local channels', [
                    'type' => $type,
                    'user' => $user->id,
                    'novu_error' => $novuResult['novu']['error'] ?? 'unknown',
                ]);
                $results = $novuResult;
            }
        }

        $channels = $channels ?? $this->getDefaultChannels();

        foreach ($channels as $channel) {
            try {
                $channelInstance = $this->resolveChannel($channel);

                if (!$channelInstance || !$this->isChannelEnabled($channel)) {
                    Log::debug("Notification channel {$channel} skipped: not resolved or not enabled", [
                        'user_id' => $user->id, 'type' => $type,
                        'resolved' => $channelInstance !== null,
                        'enabled' => $this->isChannelEnabled($channel),
                    ]);
                    continue;
                }

                if (!$this->isChannelAvailableToUsers($channel)) {
                    Log::debug("Notification channel {$channel} skipped: not available to users", [
                        'user_id' => $user->id, 'type' => $type,
                    ]);
                    continue;
                }

                if (!$this->isUserChannelEnabled($user, $channel, $type)) {
                    Log::debug("Notification channel {$channel} skipped: not enabled by user", [
                        'user_id' => $user->id, 'type' => $type,
                    ]);
                    continue;
                }

                if (!$channelInstance->isAvailableFor($user)) {
                    Log::debug("Notification channel {$channel} skipped: not available for user", [
                        'user_id' => $user->id, 'type' => $type,
                    ]);
                    continue;
                }

                // Rate limit check
                if ($this->rateLimiter->isLimited($user, $channel)) {
                    $this->writeDelivery($user->id, $type, $channel, NotificationDelivery::STATUS_RATE_LIMITED);
                    $results[$channel] = ['success' => false, 'rate_limited' => true];
                    continue;
                }

                $results[$channel] = $this->dispatchChannel(
                    $user, $channel, $type, $title, $message, $data
                );
            } catch (\Exception $e) {
                $this->writeDelivery($user->id, $type, $channel, NotificationDelivery::STATUS_FAILED, $e->getMessage());
                Log::error("Notification channel {$channel} failed", [
                    'user' => $user->id,
                    'error' => $e->getMessage(),
                ]);
                $results[$channel] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->typePreferencesCache = [];

        return $results;
    }

    /**
     * Send a test notification (bypasses rate limiting, always sync).
     */
    public function sendTestNotification(User $user, string $channel): array
    {
        $channelInstance = $this->resolveChannel($channel);

        if (!$channelInstance) {
            throw new \RuntimeException("Unknown channel: {$channel}");
        }

        if (!$this->isChannelEnabled($channel)) {
            throw new \RuntimeException("Channel is not enabled: {$channel}");
        }

        if (!$this->isChannelAvailableToUsers($channel)) {
            throw new \RuntimeException("Channel is not available to users: {$channel}");
        }

        return $channelInstance->send(
            $user,
            'test',
            'Test Notification',
            'This is a test notification from ' . config('app.name', 'selfmx') . '.',
            ['test' => true, 'timestamp' => now()->toISOString()]
        );
    }

    /**
     * Create an in-app notification.
     */
    public function createInAppNotification(
        User $user,
        string $type,
        string $title,
        string $message,
        array $data = []
    ): Notification {
        return Notification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data,
        ]);
    }

    /**
     * Dispatch a channel send — async via job or sync inline.
     */
    private function dispatchChannel(
        User $user,
        string $channel,
        string $type,
        string $title,
        string $message,
        array $data
    ): array {
        $queueEnabled = $this->isQueueEnabled();

        if ($queueEnabled && !in_array($channel, self::SYNC_ONLY_CHANNELS, true)) {
            SendNotificationChannelJob::dispatch(
                $user->id, $channel, $type, $title, $message, $data
            );
            $this->writeDelivery($user->id, $type, $channel, NotificationDelivery::STATUS_QUEUED);
            return ['success' => true, 'queued' => true];
        }

        // Sync path
        $channelInstance = $this->resolveChannel($channel);
        if (!$channelInstance) {
            return ['success' => false, 'error' => 'Channel not available'];
        }

        try {
            $result = $channelInstance->send($user, $type, $title, $message, $data);
            $this->writeDelivery($user->id, $type, $channel, NotificationDelivery::STATUS_SUCCESS);
            return ['success' => true, 'result' => $result];
        } catch (\Exception $e) {
            $this->writeDelivery($user->id, $type, $channel, NotificationDelivery::STATUS_FAILED, $e->getMessage());
            Log::error("Notification channel {$channel} failed", [
                'user' => $user->id,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Write a delivery log record.
     */
    private function writeDelivery(
        int $userId,
        string $type,
        string $channel,
        string $status,
        ?string $error = null,
        int $attempt = 1
    ): void {
        try {
            NotificationDelivery::create([
                'user_id' => $userId,
                'notification_type' => $type,
                'channel' => $channel,
                'status' => $status,
                'error' => $error,
                'attempt' => $attempt,
                'attempted_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to write notification delivery log', [
                'error' => $e->getMessage(),
                'channel' => $channel,
                'type' => $type,
            ]);
        }
    }

    /**
     * Check if async queue dispatch is enabled.
     */
    private function isQueueEnabled(): bool
    {
        return (bool) SystemSetting::get('queue_enabled', config('notifications.queue.enabled', true), 'notifications');
    }

    /**
     * Get default notification channels.
     */
    private function getDefaultChannels(): array
    {
        return config('notifications.default_channels', ['database']);
    }

    /**
     * Check if a channel is enabled.
     */
    private function isChannelEnabled(string $channel): bool
    {
        return config("notifications.channels.{$channel}.enabled", false);
    }

    /**
     * Check if the channel is available to users (admin has enabled it).
     * Database and email are always available; others use SystemSetting.
     */
    protected function isChannelAvailableToUsers(string $channel): bool
    {
        if ($this->isAlwaysAvailableChannel($channel)) {
            return true;
        }

        $value = SystemSetting::get("channel_{$channel}_available", false, 'notifications');

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Check if the user has enabled this channel in their preferences.
     * When a type is provided, also checks per-type preferences.
     */
    protected function isUserChannelEnabled(User $user, string $channel, string $type = ''): bool
    {
        if ($channel === 'database') {
            return true;
        }

        if (!(bool) $user->getSetting('notifications', "{$channel}_enabled", false)) {
            return false;
        }

        if ($type === '') {
            return true;
        }

        $prefs = $this->getUserTypePreferences($user);
        if (isset($prefs[$type][$channel]) && $prefs[$type][$channel] === false) {
            return false;
        }

        return true;
    }

    /**
     * Get per-type notification preferences for a user (cached per send cycle).
     */
    private function getUserTypePreferences(User $user): array
    {
        $userId = $user->id;
        if (!isset($this->typePreferencesCache[$userId])) {
            $raw = $user->getSetting('notifications', 'type_preferences', []);
            $this->typePreferencesCache[$userId] = is_array($raw) ? $raw : [];
        }

        return $this->typePreferencesCache[$userId];
    }

    /**
     * Send via Novu API when Novu is enabled.
     *
     * @param  array<string, mixed>  $payload  Variables/payload for the workflow
     * @return array{novu: array{success: bool, transaction_id?: string, error?: string}}
     */
    private function sendViaNovu(User $user, string $type, array $payload): array
    {
        $workflowId = $this->novuService->getWorkflowIdForType($type);
        if ($workflowId === null) {
            return ['novu' => ['success' => false, 'error' => "No workflow mapped for type: {$type}"]];
        }

        $subscriberId = $this->novuService->subscriberId($user);
        $result = $this->novuService->triggerWorkflow($workflowId, $subscriberId, $payload);

        return ['novu' => $result];
    }

    /**
     * Get all known channel identifiers.
     *
     * @return string[]
     */
    public static function knownChannels(): array
    {
        return ['database', 'email', 'telegram', 'discord', 'slack', 'twilio', 'signal', 'matrix', 'vonage', 'sns', 'webpush', 'fcm', 'ntfy'];
    }

    /**
     * Check if a channel identifier is valid.
     */
    public static function isKnownChannel(string $channel): bool
    {
        return in_array($channel, self::knownChannels(), true);
    }

    /**
     * Update a per-type, per-channel notification preference for a user.
     *
     * @return array{preferences: array}
     *
     * @throws \InvalidArgumentException
     */
    public function setTypePreference(User $user, string $type, string $channel, bool $enabled): array
    {
        if (!self::isKnownChannel($channel)) {
            throw new \InvalidArgumentException("Unknown channel: {$channel}");
        }

        $knownTypes = cache()->remember('notification_known_types', 300, function () {
            return \App\Models\NotificationTemplate::query()
                ->distinct()
                ->pluck('type')
                ->toArray();
        });

        if (!in_array($type, $knownTypes, true)) {
            throw new \InvalidArgumentException("Unknown notification type: {$type}");
        }

        $prefs = $user->getSetting('notifications', 'type_preferences', []);
        if (!is_array($prefs)) {
            $prefs = [];
        }

        if ($enabled) {
            unset($prefs[$type][$channel]);
            if (isset($prefs[$type]) && empty($prefs[$type])) {
                unset($prefs[$type]);
            }
        } else {
            if (!isset($prefs[$type])) {
                $prefs[$type] = [];
            }
            $prefs[$type][$channel] = false;
        }

        $user->setSetting('notifications', 'type_preferences', $prefs);

        return ['preferences' => $prefs];
    }

    /**
     * Resolve a channel instance (public for use by SendNotificationChannelJob).
     */
    public function resolveChannel(string $channel): ?ChannelInterface
    {
        if (isset($this->channelInstances[$channel])) {
            return $this->channelInstances[$channel];
        }

        $instance = match ($channel) {
            'database' => new DatabaseChannel(),
            'email' => new EmailChannel(),
            'telegram' => new TelegramChannel(),
            'discord' => new DiscordChannel(),
            'slack' => new SlackChannel(),
            'twilio' => new TwilioChannel(),
            'signal' => new SignalChannel(),
            'matrix' => new MatrixChannel(),
            'vonage' => new VonageChannel(),
            'sns' => new SNSChannel(),
            'webpush' => new WebPushChannel(),
            'fcm' => new FCMChannel(),
            'ntfy' => new NtfyChannel(),
            default => null,
        };

        if ($instance) {
            $this->channelInstances[$channel] = $instance;
        }

        return $instance;
    }
}
