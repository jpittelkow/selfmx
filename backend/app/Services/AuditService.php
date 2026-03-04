<?php

namespace App\Services;

use App\Events\AuditLogCreated;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AuditService
{
    /**
     * Allowed severity values (must match AuditLog::SEVERITY_*).
     */
    private const ALLOWED_SEVERITIES = [
        AuditLog::SEVERITY_INFO,
        AuditLog::SEVERITY_WARNING,
        AuditLog::SEVERITY_ERROR,
        AuditLog::SEVERITY_CRITICAL,
    ];

    /**
     * Keys to mask in old_values/new_values (values replaced with '***').
     */
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'token',
        'secret',
        'api_key',
        'api_secret',
        'access_token',
        'refresh_token',
    ];

    /**
     * Log an action to the audit log.
     * On failure (e.g. DB error), logs to Laravel Log and returns null so the request is not broken.
     */
    public function log(
        string $action,
        ?Model $auditable = null,
        array $oldValues = [],
        array $newValues = [],
        ?int $userId = null,
        ?Request $request = null,
        string $severity = AuditLog::SEVERITY_INFO
    ): ?AuditLog {
        $request = $request ?? (app()->bound(Request::class) ? request() : null);
        $ipAddress = $request?->ip();
        $userAgent = $request?->userAgent();

        if ($userId === null && auth()->check()) {
            $userId = auth()->id();
        }

        $severity = $this->normalizeSeverity($severity);
        $oldValues = $this->filterSensitive($oldValues);
        $newValues = $this->filterSensitive($newValues);

        $correlationId = app()->bound('correlation_id') ? app('correlation_id') : null;

        try {
            $log = AuditLog::create([
                'user_id' => $userId,
                'action' => $action,
                'severity' => $severity,
                'auditable_type' => $auditable ? get_class($auditable) : null,
                'auditable_id' => $auditable?->getKey(),
                'old_values' => !empty($oldValues) ? $oldValues : null,
                'new_values' => !empty($newValues) ? $newValues : null,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'correlation_id' => $correlationId,
            ]);
            $log->load('user');
            event(new AuditLogCreated($log));
            return $log;
        } catch (\Throwable $e) {
            Log::error('AuditService: Failed to write audit log', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Log an auth-related action (action becomes "auth.{action}").
     */
    public function logAuth(
        string $action,
        ?Model $user = null,
        array $data = [],
        string $severity = AuditLog::SEVERITY_INFO
    ): ?AuditLog {
        $fullAction = 'auth.' . $action;
        $newValues = $data;
        if ($user) {
            $newValues['user_id'] = $user->getKey();
            if (method_exists($user, 'getAttribute')) {
                $newValues['email'] = $user->getAttribute('email');
            }
        }
        return $this->log($fullAction, $user, [], $newValues, null, null, $severity);
    }

    /**
     * Log a settings change (action is "settings.updated", group stored in new_values).
     */
    public function logSettings(
        string $group,
        array $oldValues = [],
        array $newValues = [],
        ?int $userId = null
    ): ?AuditLog {
        $newValues = array_merge(['group' => $group], $newValues);
        return $this->log('settings.updated', null, $oldValues, $newValues, $userId, null, AuditLog::SEVERITY_INFO);
    }

    /**
     * Log a user action (no auditable model).
     */
    public function logUserAction(
        string $action,
        ?int $userId = null,
        ?Request $request = null,
        string $severity = AuditLog::SEVERITY_INFO
    ): ?AuditLog {
        $request = $request ?? (app()->bound(Request::class) ? request() : null);
        return $this->log($action, null, [], [], $userId, $request, $severity);
    }

    /**
     * Log a model change.
     */
    public function logModelChange(
        Model $model,
        string $action,
        array $oldValues = [],
        array $newValues = [],
        ?Request $request = null,
        string $severity = AuditLog::SEVERITY_INFO
    ): ?AuditLog {
        $request = $request ?? (app()->bound(Request::class) ? request() : null);
        return $this->log($action, $model, $oldValues, $newValues, null, $request, $severity);
    }

    /**
     * Build a filtered audit log query.
     *
     * @param array<string, mixed> $filters  Keys: user_id, action, severity, correlation_id, date_from, date_to
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function buildFilteredQuery(array $filters): \Illuminate\Database\Eloquent\Builder
    {
        $query = AuditLog::with('user');

        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        if (!empty($filters['action'])) {
            $escaped = \App\Support\Str::escapeLike($filters['action']);
            $query->where('action', 'like', "%{$escaped}%");
        }
        if (!empty($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }
        if (isset($filters['correlation_id']) && $filters['correlation_id'] !== '') {
            $query->where('correlation_id', $filters['correlation_id']);
        }
        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Get a lazy cursor of filtered audit logs for export (memory-efficient).
     *
     * @param array<string, mixed> $filters  Keys: user_id, action, severity, correlation_id, date_from, date_to
     * @return \Illuminate\Support\LazyCollection
     */
    public function queryForExport(array $filters): \Illuminate\Support\LazyCollection
    {
        return $this->buildFilteredQuery($filters)->cursor();
    }

    /**
     * Get audit log statistics for a date range.
     */
    public function getStats(string $dateFrom, string $dateTo): array
    {
        $baseQuery = AuditLog::whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59']);

        return [
            'total_actions' => (clone $baseQuery)->count(),
            'actions_by_type' => (clone $baseQuery)
                ->select('action', DB::raw('count(*) as count'))
                ->groupBy('action')
                ->orderByDesc('count')
                ->limit(10)
                ->get()
                ->pluck('count', 'action'),
            'actions_by_user' => (clone $baseQuery)
                ->whereNotNull('user_id')
                ->select('user_id', DB::raw('count(*) as count'))
                ->groupBy('user_id')
                ->orderByDesc('count')
                ->limit(10)
                ->with('user:id,name,email')
                ->get()
                ->map(fn ($item) => [
                    'user' => $item->user,
                    'count' => $item->count,
                ]),
            'by_severity' => (clone $baseQuery)
                ->select('severity', DB::raw('count(*) as count'))
                ->groupBy('severity')
                ->get()
                ->pluck('count', 'severity'),
            'daily_trends' => (clone $baseQuery)
                ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
                ->groupByRaw('DATE(created_at)')
                ->orderBy('date')
                ->get()
                ->pluck('count', 'date'),
            'recent_warnings' => (clone $baseQuery)
                ->whereIn('severity', ['warning', 'error', 'critical'])
                ->with('user:id,name,email')
                ->orderByDesc('created_at')
                ->limit(5)
                ->get()
                ->map(fn ($log) => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'severity' => $log->severity,
                    'created_at' => $log->created_at->toIso8601String(),
                    'user' => $log->user,
                ]),
        ];
    }

    /**
     * Mask or remove sensitive keys from an array (recursive for nested arrays).
     */
    private function filterSensitive(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $lower = is_string($key) ? strtolower($key) : $key;
            foreach (self::SENSITIVE_KEYS as $sensitive) {
                if (is_string($lower) && (str_contains($lower, $sensitive) || $lower === $sensitive)) {
                    $value = '***';
                    break;
                }
            }
            $out[$key] = is_array($value) ? $this->filterSensitive($value) : $value;
        }
        return $out;
    }

    private function normalizeSeverity(string $severity): string
    {
        return in_array($severity, self::ALLOWED_SEVERITIES, true)
            ? $severity
            : AuditLog::SEVERITY_INFO;
    }
}
