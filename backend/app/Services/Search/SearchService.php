<?php

namespace App\Services\Search;

use App\Models\AIProvider;
use App\Models\ApiToken;
use App\Models\Contact;
use App\Models\Email;
use App\Models\EmailLabel;
use App\Models\EmailTemplate;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Webhook;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SearchService
{
    /**
     * Searchable model name to class map (must match SearchReindexCommand).
     *
     * @var array<string, class-string>
     */
    protected static array $searchableModels = [
        'users' => User::class,
        'user_groups' => UserGroup::class,
        'notifications' => Notification::class,
        'email_templates' => EmailTemplate::class,
        'notification_templates' => NotificationTemplate::class,
        'api_tokens' => ApiToken::class,
        'ai_providers' => AIProvider::class,
        'webhooks' => Webhook::class,
        'emails' => Email::class,
        'contacts' => Contact::class,
    ];

    /**
     * Meilisearch index name for static pages (not an Eloquent model).
     */
    protected static string $pagesIndexName = 'pages';

    /**
     * Get searchable model types and classes.
     *
     * @return array<string, class-string>
     */
    public static function getSearchableModels(): array
    {
        return static::$searchableModels;
    }

    /**
     * Check if search is enabled (admin can disable via Configuration > Search).
     */
    public function isEnabled(): bool
    {
        return filter_var(config('search.enabled', true), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Test Meilisearch connection with provided credentials.
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(string $host, ?string $apiKey = null): array
    {
        $host = rtrim($host, '/');
        if ($host === '') {
            return ['success' => false, 'message' => 'Host URL is required.'];
        }
        if (! preg_match('#^https?://#', $host)) {
            return ['success' => false, 'message' => 'Host must be a valid HTTP or HTTPS URL.'];
        }
        try {
            $client = new \Meilisearch\Client($host, $apiKey);
            $client->health();
            return ['success' => true, 'message' => 'Connection successful.'];
        } catch (\Exception $e) {
            Log::warning('Meilisearch connection test failed', [
                'host' => $host,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get current Meilisearch connection health status.
     *
     * @return array{status: string, healthy: bool, message?: string}
     */
    public function getHealth(): array
    {
        try {
            $client = app(\Meilisearch\Client::class);
            $health = $client->health();
            $status = $health['status'] ?? 'unknown';
            return [
                'status' => $status,
                'healthy' => ($status === 'available'),
            ];
        } catch (\Exception $e) {
            Log::warning('Meilisearch health check failed', ['error' => $e->getMessage()]);
            return [
                'status' => 'error',
                'healthy' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Search users (admin-only: sees all users).
     * For user-scoped search, filter results by user_id in controller.
     *
     * @param  int|null  $perPage  Uses config('app.pagination.default') when null.
     * @param  int  $page  Page number for pagination.
     */
    public function searchUsers(string $query, ?int $perPage = null, int $page = 1): LengthAwarePaginator
    {
        $perPage = (int) ($perPage ?? config('search.results_per_page', config('app.pagination.default', 20)));
        $page = (int) $page;

        if (trim($query) === '') {
            return User::query()->orderBy('name')->paginate($perPage, ['*'], 'page', $page);
        }

        try {
            $results = User::search($query)->paginate($perPage, 'page', $page);

            Log::info('Search completed', [
                'query' => $query,
                'results_count' => $results->total(),
            ]);

            return $results;
        } catch (\Exception $e) {
            Log::warning('Search failed, falling back to database', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            $escaped = \App\Support\Str::escapeLike($query);
            return User::where('name', 'like', "%{$escaped}%")
                ->orWhere('email', 'like', "%{$escaped}%")
                ->paginate($perPage, ['*'], 'page', $page);
        }
    }

    /**
     * Search notifications (user-scoped when scopeUserId set).
     */
    public function searchNotifications(string $query, ?int $perPage = null, int $page = 1, ?int $scopeUserId = null): LengthAwarePaginator
    {
        $perPage = (int) ($perPage ?? config('app.pagination.default', 20));
        $page = (int) $page;
        $query = trim($query);

        if ($query === '') {
            $q = Notification::query()->orderByDesc('created_at');
            if ($scopeUserId !== null) {
                $q->where('user_id', $scopeUserId);
            }
            return $q->paginate($perPage, ['*'], 'page', $page);
        }

        try {
            $builder = Notification::search($query);
            if ($scopeUserId !== null) {
                $builder->where('user_id', $scopeUserId);
            }
            return $builder->paginate($perPage, 'page', $page);
        } catch (\Exception $e) {
            Log::warning('Search failed, falling back to database', ['query' => $query, 'error' => $e->getMessage()]);
            $escaped = \App\Support\Str::escapeLike($query);
            $q = Notification::query()->where(function ($qb) use ($escaped) {
                $qb->where('title', 'like', "%{$escaped}%")->orWhere('message', 'like', "%{$escaped}%");
            });
            if ($scopeUserId !== null) {
                $q->where('user_id', $scopeUserId);
            }
            return $q->orderByDesc('created_at')->paginate($perPage, ['*'], 'page', $page);
        }
    }

    /**
     * Search email templates (admin only).
     */
    public function searchEmailTemplates(string $query, ?int $perPage = null, int $page = 1): LengthAwarePaginator
    {
        $perPage = (int) ($perPage ?? config('app.pagination.default', 20));
        $page = (int) $page;
        $query = trim($query);

        if ($query === '') {
            return EmailTemplate::query()->orderBy('name')->paginate($perPage, ['*'], 'page', $page);
        }

        try {
            return EmailTemplate::search($query)->paginate($perPage, 'page', $page);
        } catch (\Exception $e) {
            Log::warning('Search failed, falling back to database', ['query' => $query, 'error' => $e->getMessage()]);
            $escaped = \App\Support\Str::escapeLike($query);
            return EmailTemplate::where('name', 'like', "%{$escaped}%")
                ->orWhere('subject', 'like', "%{$escaped}%")
                ->orWhere('description', 'like', "%{$escaped}%")
                ->orderBy('name')
                ->paginate($perPage, ['*'], 'page', $page);
        }
    }

    /**
     * Search notification templates (admin only).
     */
    public function searchNotificationTemplates(string $query, ?int $perPage = null, int $page = 1): LengthAwarePaginator
    {
        $perPage = (int) ($perPage ?? config('app.pagination.default', 20));
        $page = (int) $page;
        $query = trim($query);

        if ($query === '') {
            return NotificationTemplate::query()->orderBy('type')->orderBy('channel_group')->paginate($perPage, ['*'], 'page', $page);
        }

        try {
            return NotificationTemplate::search($query)->paginate($perPage, 'page', $page);
        } catch (\Exception $e) {
            Log::warning('Search failed, falling back to database', ['query' => $query, 'error' => $e->getMessage()]);

            $escaped = \App\Support\Str::escapeLike($query);
            return NotificationTemplate::where('type', 'like', "%{$escaped}%")
                ->orWhere('channel_group', 'like', "%{$escaped}%")
                ->orWhere('title', 'like', "%{$escaped}%")
                ->orWhere('body', 'like', "%{$escaped}%")
                ->orderBy('type')
                ->orderBy('channel_group')
                ->paginate($perPage, ['*'], 'page', $page);
        }
    }

    /**
     * Search API tokens (user-scoped when scopeUserId set).
     */
    public function searchApiTokens(string $query, ?int $perPage = null, int $page = 1, ?int $scopeUserId = null): LengthAwarePaginator
    {
        $perPage = (int) ($perPage ?? config('app.pagination.default', 20));
        $page = (int) $page;
        $query = trim($query);

        if ($query === '') {
            $q = ApiToken::query()->orderBy('name');
            if ($scopeUserId !== null) {
                $q->where('user_id', $scopeUserId);
            }
            return $q->paginate($perPage, ['*'], 'page', $page);
        }

        try {
            $builder = ApiToken::search($query);
            if ($scopeUserId !== null) {
                $builder->where('user_id', $scopeUserId);
            }
            return $builder->paginate($perPage, 'page', $page);
        } catch (\Exception $e) {
            Log::warning('Search failed, falling back to database', ['query' => $query, 'error' => $e->getMessage()]);
            $escaped = \App\Support\Str::escapeLike($query);
            $q = ApiToken::query()->where('name', 'like', "%{$escaped}%");
            if ($scopeUserId !== null) {
                $q->where('user_id', $scopeUserId);
            }
            return $q->orderBy('name')->paginate($perPage, ['*'], 'page', $page);
        }
    }

    /**
     * Search AI providers (user-scoped when scopeUserId set).
     */
    public function searchAIProviders(string $query, ?int $perPage = null, int $page = 1, ?int $scopeUserId = null): LengthAwarePaginator
    {
        $perPage = (int) ($perPage ?? config('app.pagination.default', 20));
        $page = (int) $page;
        $query = trim($query);

        if ($query === '') {
            $q = AIProvider::query()->orderBy('provider');
            if ($scopeUserId !== null) {
                $q->where('user_id', $scopeUserId);
            }
            return $q->paginate($perPage, ['*'], 'page', $page);
        }

        try {
            $builder = AIProvider::search($query);
            if ($scopeUserId !== null) {
                $builder->where('user_id', $scopeUserId);
            }
            return $builder->paginate($perPage, 'page', $page);
        } catch (\Exception $e) {
            Log::warning('Search failed, falling back to database', ['query' => $query, 'error' => $e->getMessage()]);
            $escaped = \App\Support\Str::escapeLike($query);
            $q = AIProvider::query()->where(function ($qb) use ($escaped) {
                $qb->where('provider', 'like', "%{$escaped}%")->orWhere('model', 'like', "%{$escaped}%");
            });
            if ($scopeUserId !== null) {
                $q->where('user_id', $scopeUserId);
            }
            return $q->orderBy('provider')->paginate($perPage, ['*'], 'page', $page);
        }
    }

    /**
     * Search webhooks (admin only).
     */
    public function searchWebhooks(string $query, ?int $perPage = null, int $page = 1): LengthAwarePaginator
    {
        $perPage = (int) ($perPage ?? config('app.pagination.default', 20));
        $page = (int) $page;
        $query = trim($query);

        if ($query === '') {
            return Webhook::query()->orderBy('name')->paginate($perPage, ['*'], 'page', $page);
        }

        try {
            return Webhook::search($query)->paginate($perPage, 'page', $page);
        } catch (\Exception $e) {
            Log::warning('Search failed, falling back to database', ['query' => $query, 'error' => $e->getMessage()]);
            $escaped = \App\Support\Str::escapeLike($query);
            return Webhook::where('name', 'like', "%{$escaped}%")
                ->orWhere('url', 'like', "%{$escaped}%")
                ->orderBy('name')
                ->paginate($perPage, ['*'], 'page', $page);
        }
    }

    /**
     * Search emails with structured filter syntax (user-scoped).
     *
     * Supports filter syntax: from:, to:, has:attachment, after:YYYY-MM-DD,
     * before:YYYY-MM-DD, label:name, is:read, is:unread, is:starred.
     * Remaining text is used as free-text search.
     */
    public function searchEmails(string $query, \App\Models\User|int $user, ?int $perPage = null, int $page = 1): LengthAwarePaginator
    {
        // Support both User object and legacy int $userId
        if (is_int($user)) {
            $user = \App\Models\User::findOrFail($user);
        }

        $accessService = app(\App\Services\Email\MailboxAccessService::class);
        $mailboxIds = $accessService->getAccessibleMailboxIds($user);

        $perPage = (int) ($perPage ?? config('app.pagination.default', 25));
        $page = (int) $page;

        $parsed = $this->parseEmailQuery($query);
        $textQuery = trim($parsed['text']);

        try {
            $builder = Email::search($textQuery);

            // Scope to accessible mailboxes
            $builder->whereIn('mailbox_id', $mailboxIds);

            // Apply parsed filters
            if ($parsed['from'] !== null) {
                $builder->where('from_address', $parsed['from']);
            }
            if ($parsed['has_attachment'] === true) {
                $builder->where('has_attachment', true);
            }
            if ($parsed['is_read'] !== null) {
                $builder->where('is_read', $parsed['is_read']);
            }
            if ($parsed['is_starred'] === true) {
                $builder->where('is_starred', true);
            }
            if ($parsed['after'] !== null) {
                $builder->where('sent_at', '>=', strtotime($parsed['after']));
            }
            if ($parsed['before'] !== null) {
                $builder->where('sent_at', '<=', strtotime($parsed['before'] . ' 23:59:59'));
            }
            if ($parsed['label'] !== null) {
                $label = EmailLabel::where('user_id', $user->id)
                    ->where('name', $parsed['label'])->first();
                if ($label) {
                    $builder->where('label_ids', $label->id);
                }
            }

            // Exclude trashed and spam by default
            $builder->where('is_trashed', false);
            $builder->where('is_spam', false);

            return $builder->paginate($perPage, 'page', $page);
        } catch (\Exception $e) {
            Log::warning('Email search failed, falling back to database', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return $this->searchEmailsViaDatabase($textQuery, $parsed, $mailboxIds, $user->id, $perPage, $page);
        }
    }

    /**
     * Database fallback for email search.
     */
    protected function searchEmailsViaDatabase(string $textQuery, array $parsed, array $mailboxIds, int $userId, int $perPage, int $page): LengthAwarePaginator
    {
        $q = Email::whereIn('mailbox_id', $mailboxIds)
            ->where('is_trashed', false)
            ->where('is_spam', false)
            ->with(['recipients', 'labels', 'attachments']);

        if ($textQuery !== '') {
            $q->where(function ($qb) use ($textQuery) {
                $qb->where('subject', 'like', "%{$textQuery}%")
                    ->orWhere('from_address', 'like', "%{$textQuery}%")
                    ->orWhere('from_name', 'like', "%{$textQuery}%")
                    ->orWhere('body_text', 'like', "%{$textQuery}%");
            });
        }

        if ($parsed['from'] !== null) {
            $q->where('from_address', $parsed['from']);
        }
        if ($parsed['to'] !== null) {
            $q->whereHas('recipients', function ($qb) use ($parsed) {
                $qb->where('type', 'to')->where('address', $parsed['to']);
            });
        }
        if ($parsed['has_attachment'] === true) {
            $q->whereHas('attachments');
        }
        if ($parsed['is_read'] !== null) {
            $q->where('is_read', $parsed['is_read']);
        }
        if ($parsed['is_starred'] === true) {
            $q->where('is_starred', true);
        }
        if ($parsed['after'] !== null) {
            $q->where('sent_at', '>=', $parsed['after']);
        }
        if ($parsed['before'] !== null) {
            $q->where('sent_at', '<=', $parsed['before'] . ' 23:59:59');
        }
        if ($parsed['label'] !== null) {
            $label = EmailLabel::where('user_id', $userId)
                ->where('name', $parsed['label'])->first();
            if ($label) {
                $q->whereHas('labels', fn ($qb) => $qb->where('email_labels.id', $label->id));
            }
        }

        return $q->orderByDesc('sent_at')->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Parse an email search query with structured filter syntax.
     *
     * @return array{text: string, from: ?string, to: ?string, has_attachment: ?bool, after: ?string, before: ?string, label: ?string, is_read: ?bool, is_starred: ?bool}
     */
    public function parseEmailQuery(string $query): array
    {
        $result = [
            'text' => '',
            'from' => null,
            'to' => null,
            'has_attachment' => null,
            'after' => null,
            'before' => null,
            'label' => null,
            'is_read' => null,
            'is_starred' => null,
        ];

        $remaining = [];
        preg_match_all('/"[^"]*"|\'[^\']*\'|\S+/', $query, $tokens);

        foreach ($tokens[0] as $token) {
            if (preg_match('/^from:(.+)$/i', $token, $m)) {
                $result['from'] = strtolower($m[1]);
            } elseif (preg_match('/^to:(.+)$/i', $token, $m)) {
                $result['to'] = strtolower($m[1]);
            } elseif (preg_match('/^has:attachment$/i', $token)) {
                $result['has_attachment'] = true;
            } elseif (preg_match('/^after:(\d{4}-\d{2}-\d{2})$/i', $token, $m)) {
                $result['after'] = $m[1];
            } elseif (preg_match('/^before:(\d{4}-\d{2}-\d{2})$/i', $token, $m)) {
                $result['before'] = $m[1];
            } elseif (preg_match('/^label:(.+)$/i', $token, $m)) {
                $result['label'] = $m[1];
            } elseif (preg_match('/^is:(read|unread|starred)$/i', $token, $m)) {
                $flag = strtolower($m[1]);
                if ($flag === 'read') {
                    $result['is_read'] = true;
                } elseif ($flag === 'unread') {
                    $result['is_read'] = false;
                } elseif ($flag === 'starred') {
                    $result['is_starred'] = true;
                }
            } else {
                $remaining[] = $token;
            }
        }

        $result['text'] = implode(' ', $remaining);

        return $result;
    }

    /**
     * Global search across searchable models with unified result format.
     *
     * @param  array<string, mixed>  $filters  Optional filters (e.g. ['type' => 'users'])
     * @param  int|null  $scopeUserId  When set, scope user results to only this user (for non-admin).
     * @return array{data: array<int, array{id: int, type: string, title: string, subtitle?: string, url: string, highlight?: array{title?: string, subtitle?: string}}>, meta: array{query: string, total: int, page: int, per_page: int}}
     */
    public function globalSearch(string $query, ?string $type = null, array $filters = [], int $page = 1, ?int $perPage = null, ?int $scopeUserId = null): array
    {
        $perPage = (int) ($perPage ?? config('search.results_per_page', config('app.pagination.default', 20)));
        $page = (int) $page;
        $query = trim($query);
        $types = $type !== null ? [$type] : array_keys(static::$searchableModels);

        $allResults = [];
        $total = 0;

        foreach ($types as $modelType) {
            $class = static::$searchableModels[$modelType] ?? null;
            if ($class === null) {
                continue;
            }
            if ($modelType === 'users') {
                if ($scopeUserId !== null) {
                    $user = User::find($scopeUserId);
                    if (! $user || ($query !== '' && stripos($user->name, $query) === false && stripos($user->email, $query) === false)) {
                        $total = 0;
                    } else {
                        $allResults[] = $this->transformUserToResult($user, $query);
                        $total = 1;
                    }
                } else {
                    $paginator = $this->searchUsers($query ?: ' ', $perPage, $page);
                    foreach ($paginator->items() as $user) {
                        $allResults[] = $this->transformUserToResult($user, $query);
                    }
                    $total = $paginator->total();
                }
                break;
            }
            if ($modelType === 'user_groups') {
                if ($scopeUserId !== null) {
                    $total = 0;
                } else {
                    $paginator = UserGroup::search($query ?: ' ')->paginate($perPage, 'page', $page);
                    foreach ($paginator->items() as $group) {
                        $allResults[] = $this->transformUserGroupToResult($group, $query);
                    }
                    $total = $paginator->total();
                }
                break;
            }
            if ($modelType === 'notifications') {
                $paginator = $this->searchNotifications($query ?: ' ', $perPage, $page, $scopeUserId);
                foreach ($paginator->items() as $notification) {
                    $allResults[] = $this->transformNotificationToResult($notification, $query);
                }
                $total = $paginator->total();
                break;
            }
            if ($modelType === 'email_templates') {
                if ($scopeUserId !== null) {
                    $total = 0;
                } else {
                    $paginator = $this->searchEmailTemplates($query ?: ' ', $perPage, $page);
                    foreach ($paginator->items() as $template) {
                        $allResults[] = $this->transformEmailTemplateToResult($template, $query);
                    }
                    $total = $paginator->total();
                }
                break;
            }
            if ($modelType === 'notification_templates') {
                if ($scopeUserId !== null) {
                    $total = 0;
                } else {
                    $paginator = $this->searchNotificationTemplates($query ?: ' ', $perPage, $page);
                    foreach ($paginator->items() as $template) {
                        $allResults[] = $this->transformNotificationTemplateToResult($template, $query);
                    }
                    $total = $paginator->total();
                }
                break;
            }
            if ($modelType === 'api_tokens') {
                $paginator = $this->searchApiTokens($query ?: ' ', $perPage, $page, $scopeUserId);
                foreach ($paginator->items() as $token) {
                    $allResults[] = $this->transformApiTokenToResult($token, $query);
                }
                $total = $paginator->total();
                break;
            }
            if ($modelType === 'ai_providers') {
                $paginator = $this->searchAIProviders($query ?: ' ', $perPage, $page, $scopeUserId);
                foreach ($paginator->items() as $provider) {
                    $allResults[] = $this->transformAIProviderToResult($provider, $query);
                }
                $total = $paginator->total();
                break;
            }
            if ($modelType === 'webhooks') {
                if ($scopeUserId !== null) {
                    $total = 0;
                } else {
                    $paginator = $this->searchWebhooks($query ?: ' ', $perPage, $page);
                    foreach ($paginator->items() as $webhook) {
                        $allResults[] = $this->transformWebhookToResult($webhook, $query);
                    }
                    $total = $paginator->total();
                }
                break;
            }
        }

        $meta = [
            'query' => $query,
            'total' => (int) $total,
            'page' => $page,
            'per_page' => $perPage,
        ];

        return ['data' => $allResults, 'meta' => $meta];
    }

    /**
     * Sync static pages from config to Meilisearch index.
     *
     * @return array{success: bool, count: int}
     */
    public function syncPagesToIndex(): array
    {
        try {
            $client = app(\Meilisearch\Client::class);
        } catch (\Throwable $e) {
            Log::error('Failed to resolve Meilisearch client for pages sync', [
                'error' => $e->getMessage(),
                'host' => config('scout.meilisearch.host'),
                'key_set' => ! empty(config('scout.meilisearch.key')),
            ]);
            throw $e;
        }

        try {
            $index = $client->index(static::$pagesIndexName);

            $index->updateSettings([
                'searchableAttributes' => ['title', 'subtitle', 'content'],
                'filterableAttributes' => ['admin_only'],
                'displayedAttributes' => ['id', 'title', 'subtitle', 'url', 'admin_only', 'content'],
                'rankingRules' => [
                    'words', 'typo', 'proximity', 'attribute', 'sort', 'exactness',
                ],
            ]);

            $pages = config('search-pages');
            $index->addDocuments($pages, 'id');

            Log::info('Pages index synced', ['count' => count($pages)]);

            return ['success' => true, 'count' => count($pages)];
        } catch (\Throwable $e) {
            $isKeyError = stripos($e->getMessage(), 'api key') !== false || str_contains($e->getMessage(), 'invalid_api_key');

            Log::error('Pages sync failed', [
                'error' => $e->getMessage(),
                'host' => config('scout.meilisearch.host'),
                'key_set' => ! empty(config('scout.meilisearch.key')),
                'key_source' => $isKeyError ? 'Check Configuration > Search API key or MEILI_MASTER_KEY env var' : null,
            ]);

            throw $e;
        }
    }

    /**
     * Search static pages index.
     *
     * @return array<int, array{id: string, type: string, title: string, subtitle?: string, url: string, highlight?: array{title?: string, subtitle?: string}}>
     */
    public function searchPages(string $query, bool $isAdmin = false, int $limit = 5): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        try {
            $client = app(\Meilisearch\Client::class);
            $index = $client->index(static::$pagesIndexName);

            $filter = $isAdmin ? null : 'admin_only = false';

            $results = $index->search($query, [
                'limit' => $limit,
                'filter' => $filter,
                'attributesToHighlight' => ['title', 'subtitle'],
                'highlightPreTag' => '<mark>',
                'highlightPostTag' => '</mark>',
            ]);

            return array_map(function ($hit) {
                return [
                    'id' => $hit['id'],
                    'type' => 'page',
                    'title' => $hit['_formatted']['title'] ?? $hit['title'],
                    'subtitle' => $hit['_formatted']['subtitle'] ?? $hit['subtitle'] ?? null,
                    'url' => $hit['url'],
                    'highlight' => [
                        'title' => $hit['_formatted']['title'] ?? null,
                        'subtitle' => $hit['_formatted']['subtitle'] ?? null,
                    ],
                ];
            }, $results->getHits());
        } catch (\Exception $e) {
            Log::warning('Page search failed', ['query' => $query, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Get document count for the pages index.
     *
     * @return array{count: int, name: string}
     */
    public function getPagesIndexStats(): array
    {
        try {
            $client = app(\Meilisearch\Client::class);
            $index = $client->index(static::$pagesIndexName);
            $stats = $index->stats();

            return [
                'count' => $stats['numberOfDocuments'] ?? 0,
                'name' => 'pages',
            ];
        } catch (\Exception $e) {
            return ['count' => 0, 'name' => 'pages'];
        }
    }

    /**
     * Search user groups (admin only).
     *
     * @return array<int, array{id: int, type: string, title: string, subtitle?: string, url: string, highlight?: array{title?: string, subtitle?: string}}>
     */
    public function searchUserGroups(string $query, int $limit = 5): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        try {
            $results = UserGroup::search($query)->take($limit)->get();

            return $results->map(fn ($group) => [
                'id' => $group->id,
                'type' => 'group',
                'title' => $group->name,
                'subtitle' => $group->description,
                'url' => '/configuration/groups?highlight=' . $group->id,
                'highlight' => [
                    'title' => $this->highlightMatch($group->name, $query),
                    'subtitle' => $group->description ? $this->highlightMatch($group->description, $query) : null,
                ],
            ])->toArray();
        } catch (\Exception $e) {
            Log::warning('UserGroup search failed', ['query' => $query, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Get search suggestions (fast autocomplete).
     *
     * @param  int|null  $scopeUserId  When set, scope user results to only this user (for non-admin).
     * @return array<int, array{id: int|string, type: string, title: string, subtitle?: string, url: string, highlight?: array{title?: string, subtitle?: string}}>
     */
    public function getSuggestions(string $query, int $limit = 5, ?int $scopeUserId = null, ?int $authUserId = null): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $suggestionsLimit = (int) config('search.suggestions_limit', 5);
        $limit = min((int) $limit, max(3, min(10, $suggestionsLimit)));
        $isAdmin = $scopeUserId === null;
        $results = [];

        // Search emails first (most relevant for daily use)
        $emailUserId = $authUserId ?? $scopeUserId;
        if ($emailUserId) {
            $emailLimit = min(3, $limit);
            try {
                $emailResults = $this->searchEmails($query, $emailUserId, $emailLimit, 1);
                foreach ($emailResults->items() as $email) {
                    $results[] = $this->transformEmailToSuggestion($email, $query);
                }
            } catch (\Exception $e) {
                Log::warning('Email suggestion search failed', ['error' => $e->getMessage()]);
            }
        }

        // Search pages (fast, static content)
        $pageLimit = min(3, $limit - count($results));
        if ($pageLimit > 0) {
            $pages = $this->searchPages($query, $isAdmin, $pageLimit);
            $results = array_merge($results, $pages);
        }

        // Search contacts
        $contactLimit = min(2, $limit - count($results));
        if ($contactLimit > 0 && $emailUserId) {
            try {
                $contactResults = Contact::search($query)
                    ->where('user_id', $emailUserId)
                    ->paginate($contactLimit);
                foreach ($contactResults->items() as $contact) {
                    $results[] = $this->transformContactToSuggestion($contact, $query);
                }
            } catch (\Exception $e) {
                Log::warning('Contact suggestion search failed', ['error' => $e->getMessage()]);
            }
        }

        // Search user groups (admin only)
        if ($isAdmin) {
            $groupLimit = min(2, $limit - count($results));
            if ($groupLimit > 0) {
                $groups = $this->searchUserGroups($query, $groupLimit);
                $results = array_merge($results, $groups);
            }
        }

        // Then search users
        $userLimit = max(2, $limit - count($results));
        $userResults = $this->globalSearch($query, 'users', [], 1, $userLimit, $scopeUserId);
        $results = array_merge($results, $userResults['data']);

        return array_slice($results, 0, $limit);
    }

    /**
     * Transform an Email model into a suggestion result.
     */
    protected function transformEmailToSuggestion(Email $email, string $query): array
    {
        $title = $email->subject ?: '(No subject)';
        $subtitle = $email->from_name ? "{$email->from_name} <{$email->from_address}>" : $email->from_address;
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeSubtitle = htmlspecialchars($subtitle ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return [
            'id' => $email->id,
            'type' => 'emails',
            'title' => $safeTitle,
            'subtitle' => $safeSubtitle,
            'url' => '/mail?search=' . urlencode($email->subject ?: ''),
            'highlight' => [
                'title' => $this->highlightMatch($title, $query),
                'subtitle' => $subtitle ? $this->highlightMatch($subtitle, $query) : null,
            ],
        ];
    }

    /**
     * Transform a Contact model into a suggestion result.
     */
    protected function transformContactToSuggestion(Contact $contact, string $query): array
    {
        $title = $contact->display_name ?: $contact->email_address;
        $subtitle = $contact->display_name ? $contact->email_address : null;
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeSubtitle = $subtitle ? htmlspecialchars($subtitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : null;

        return [
            'id' => $contact->id,
            'type' => 'contacts',
            'title' => $safeTitle,
            'subtitle' => $safeSubtitle,
            'url' => '/contacts',
            'highlight' => [
                'title' => $this->highlightMatch($title, $query),
                'subtitle' => $subtitle ? $this->highlightMatch($subtitle, $query) : null,
            ],
        ];
    }

    /**
     * Get index statistics for admin (document counts per index).
     *
     * @return array<string, array{count: int, name: string}>
     */
    public function getIndexStats(): array
    {
        $stats = [];

        $stats['pages'] = $this->getPagesIndexStats();

        foreach (static::$searchableModels as $type => $class) {
            try {
                $paginator = $class::search('')->paginate(1);
                $stats[$type] = [
                    'count' => $paginator->total(),
                    'name' => $type,
                ];
            } catch (\Exception $e) {
                Log::warning('Search index stats failed', ['type' => $type, 'error' => $e->getMessage()]);
                $stats[$type] = ['count' => 0, 'name' => $type];
            }
        }

        return $stats;
    }

    /**
     * Transform a UserGroup into a unified search result item.
     */
    protected function transformUserGroupToResult(UserGroup $group, string $query): array
    {
        $title = $group->name;
        $subtitle = $group->description;
        $highlight = null;
        if ($query !== '') {
            $highlight = [
                'title' => $this->highlightMatch($title, $query),
                'subtitle' => $subtitle !== null ? $this->highlightMatch($subtitle, $query) : null,
            ];
        }
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeSubtitle = $subtitle !== null ? htmlspecialchars($subtitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : null;

        return [
            'id' => $group->id,
            'type' => 'group',
            'title' => $safeTitle,
            'subtitle' => $safeSubtitle,
            'url' => '/configuration/groups?highlight=' . $group->id,
            'highlight' => $highlight,
        ];
    }

    /**
     * Transform a User model into a unified search result item.
     *
     * @param  array{title?: string, subtitle?: string}  $highlight
     */
    protected function transformUserToResult(User $user, string $query): array
    {
        $title = $user->name;
        $subtitle = $user->email;
        $highlight = null;
        if ($query !== '') {
            $highlight = [
                'title' => $this->highlightMatch($title, $query),
                'subtitle' => $this->highlightMatch($subtitle, $query),
            ];
        }
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeSubtitle = htmlspecialchars($subtitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return [
            'id' => $user->id,
            'type' => 'user',
            'title' => $safeTitle,
            'subtitle' => $safeSubtitle,
            'url' => '/configuration/users?highlight=' . $user->id,
            'highlight' => $highlight,
        ];
    }

    /**
     * Transform a Notification into a unified search result item.
     */
    protected function transformNotificationToResult(Notification $notification, string $query): array
    {
        $title = $notification->title ?? '';
        $subtitle = $notification->message ? Str::limit($notification->message, 60) : null;
        $highlight = null;
        if ($query !== '') {
            $highlight = [
                'title' => $this->highlightMatch($title, $query),
                'subtitle' => $subtitle !== null ? $this->highlightMatch($subtitle, $query) : null,
            ];
        }
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeSubtitle = $subtitle !== null ? htmlspecialchars($subtitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : null;

        return [
            'id' => $notification->id,
            'type' => 'notification',
            'title' => $safeTitle,
            'subtitle' => $safeSubtitle,
            'url' => '/notifications?highlight=' . urlencode($notification->id),
            'highlight' => $highlight,
        ];
    }

    /**
     * Transform an EmailTemplate into a unified search result item.
     */
    protected function transformEmailTemplateToResult(EmailTemplate $template, string $query): array
    {
        $title = $template->name ?? $template->key ?? '';
        $subtitle = $template->subject ?? $template->description;
        $subtitle = $subtitle ? Str::limit((string) $subtitle, 60) : null;
        $highlight = null;
        if ($query !== '') {
            $highlight = [
                'title' => $this->highlightMatch($title, $query),
                'subtitle' => $subtitle !== null ? $this->highlightMatch($subtitle, $query) : null,
            ];
        }
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeSubtitle = $subtitle !== null ? htmlspecialchars($subtitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : null;

        return [
            'id' => $template->id,
            'type' => 'email_template',
            'title' => $safeTitle,
            'subtitle' => $safeSubtitle,
            'url' => '/configuration/email-templates/' . urlencode($template->key),
            'highlight' => $highlight,
        ];
    }

    /**
     * Transform a NotificationTemplate into a unified search result item.
     */
    protected function transformNotificationTemplateToResult(NotificationTemplate $template, string $query): array
    {
        $title = $template->title ?? $template->type ?? '';
        $subtitle = $template->type . ' · ' . $template->channel_group . ($template->body ? ' · ' . Str::limit($template->body, 40) : '');
        $subtitle = Str::limit($subtitle, 60);
        $highlight = null;
        if ($query !== '') {
            $highlight = [
                'title' => $this->highlightMatch($title, $query),
                'subtitle' => $this->highlightMatch($subtitle, $query),
            ];
        }
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeSubtitle = htmlspecialchars($subtitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return [
            'id' => $template->id,
            'type' => 'notification_template',
            'title' => $safeTitle,
            'subtitle' => $safeSubtitle,
            'url' => '/configuration/notification-templates/' . $template->id,
            'highlight' => $highlight,
        ];
    }

    /**
     * Transform an ApiToken into a unified search result item.
     */
    protected function transformApiTokenToResult(ApiToken $token, string $query): array
    {
        $title = $token->name ?? '';
        $subtitle = null;
        $highlight = null;
        if ($query !== '') {
            $highlight = [
                'title' => $this->highlightMatch($title, $query),
                'subtitle' => null,
            ];
        }
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return [
            'id' => $token->id,
            'type' => 'api_token',
            'title' => $safeTitle,
            'subtitle' => null,
            'url' => '/configuration/api?highlight=' . $token->id,
            'highlight' => $highlight,
        ];
    }

    /**
     * Transform an AIProvider into a unified search result item.
     */
    protected function transformAIProviderToResult(AIProvider $provider, string $query): array
    {
        $title = $provider->provider . ($provider->model ? ' – ' . $provider->model : '');
        $subtitle = $provider->model ?? null;
        $highlight = null;
        if ($query !== '') {
            $highlight = [
                'title' => $this->highlightMatch($title, $query),
                'subtitle' => $subtitle !== null ? $this->highlightMatch($subtitle, $query) : null,
            ];
        }
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeSubtitle = $subtitle !== null ? htmlspecialchars($subtitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : null;

        return [
            'id' => $provider->id,
            'type' => 'ai_provider',
            'title' => $safeTitle,
            'subtitle' => $safeSubtitle,
            'url' => '/configuration/ai?highlight=' . $provider->id,
            'highlight' => $highlight,
        ];
    }

    /**
     * Transform a Webhook into a unified search result item.
     */
    protected function transformWebhookToResult(Webhook $webhook, string $query): array
    {
        $title = $webhook->name ?? '';
        $subtitle = $webhook->url ? Str::limit($webhook->url, 60) : null;
        $highlight = null;
        if ($query !== '') {
            $highlight = [
                'title' => $this->highlightMatch($title, $query),
                'subtitle' => $subtitle !== null ? $this->highlightMatch($subtitle, $query) : null,
            ];
        }
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeSubtitle = $subtitle !== null ? htmlspecialchars($subtitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : null;

        return [
            'id' => $webhook->id,
            'type' => 'webhook',
            'title' => $safeTitle,
            'subtitle' => $safeSubtitle,
            'url' => '/configuration/api?tab=webhooks&highlight=' . $webhook->id,
            'highlight' => $highlight,
        ];
    }

    /**
     * Wrap matching (case-insensitive) query in <mark> tags.
     * Escapes HTML in text to prevent XSS when rendered in the frontend.
     */
    protected function highlightMatch(string $text, string $query): string
    {
        if ($query === '' || $text === '') {
            return $text;
        }
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $pattern = '/(' . preg_quote($query, '/') . ')/iu';

        return preg_replace($pattern, '<mark>$1</mark>', $escaped);
    }

    /**
     * Reindex a single model or all models and return a structured report.
     *
     * @return array{success: bool, message: string, model?: string, models?: array, count?: int, output?: string}
     */
    public function reindexAndReport(?string $model = null): array
    {
        if ($model !== null) {
            if ($model === 'pages') {
                $result = $this->syncPagesToIndex();
                if (!($result['success'] ?? false)) {
                    return ['success' => false, 'message' => 'Pages sync failed'];
                }

                return [
                    'success' => true,
                    'message' => "Pages index synced ({$result['count']} pages).",
                    'model' => 'pages',
                    'count' => $result['count'],
                ];
            }

            $result = $this->reindexModel($model);
            if (!$result['success']) {
                return ['success' => false, 'message' => $result['error'] ?? 'Reindex failed'];
            }

            return [
                'success' => true,
                'message' => 'Index reindexed successfully.',
                'model' => $model,
                'output' => $result['output'] ?? null,
            ];
        }

        $results = $this->reindexAll();
        $failed = array_filter($results, fn (array $r) => !($r['success'] ?? false));
        if ($failed !== []) {
            $messages = array_map(fn (array $r) => $r['error'] ?? 'Unknown error', $failed);

            return ['success' => false, 'message' => 'Reindex failed: ' . implode('; ', $messages)];
        }

        $pagesResult = $this->syncPagesToIndex();
        if ($pagesResult['success']) {
            $results['pages'] = ['success' => true, 'output' => "Synced {$pagesResult['count']} pages"];
        }

        return [
            'success' => true,
            'message' => 'All indexes reindexed successfully.',
            'models' => array_keys($results),
        ];
    }

    /**
     * Reindex all searchable models.
     *
     * @return array<string, array{success: bool, output?: string, error?: string}>
     */
    public function reindexAll(): array
    {
        $results = [];
        foreach (static::$searchableModels as $name => $class) {
            try {
                Artisan::call('scout:import', ['model' => $class]);
                $results[$name] = ['success' => true, 'output' => Artisan::output()];
            } catch (\Exception $e) {
                Log::error('Search reindex failed', ['model' => $name, 'error' => $e->getMessage()]);
                $results[$name] = ['success' => false, 'error' => $e->getMessage()];
            }
        }
        Log::info('Search reindex completed', ['models' => array_keys($results)]);

        return $results;
    }

    /**
     * Reindex a single searchable model.
     *
     * @return array{success: bool, output?: string, error?: string}
     */
    public function reindexModel(string $model): array
    {
        $model = strtolower($model);
        $class = static::$searchableModels[$model] ?? null;
        if ($class === null) {
            return ['success' => false, 'error' => "Unknown model: {$model}"];
        }
        try {
            Artisan::call('scout:import', ['model' => $class]);
            Log::info('Search reindex completed', ['model' => $model]);

            return ['success' => true, 'output' => Artisan::output()];
        } catch (\Exception $e) {
            Log::error('Search reindex failed', ['model' => $model, 'error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
