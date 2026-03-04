<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;


class AuditLogController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private AuditService $auditService
    ) {}

    /**
     * Get paginated audit logs with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', config('app.pagination.audit_log'));
        $filters = $request->only(['user_id', 'action', 'severity', 'correlation_id', 'date_from', 'date_to']);

        return $this->dataResponse(
            $this->auditService->buildFilteredQuery($filters)->paginate($perPage)
        );
    }

    /**
     * Export audit logs as CSV.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $request->only(['user_id', 'action', 'severity', 'correlation_id', 'date_from', 'date_to']);
        $logs = $this->auditService->queryForExport($filters);

        $filename = 'audit_logs_' . date('Y-m-d_His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($logs) {
            $file = fopen('php://output', 'w');

            fputcsv($file, [
                'ID',
                'Date',
                'User',
                'Action',
                'Severity',
                'Correlation ID',
                'IP Address',
                'User Agent',
            ]);

            foreach ($logs as $log) {
                fputcsv($file, [
                    $log->id,
                    $log->created_at->format('Y-m-d H:i:s'),
                    $log->user ? $log->user->email : 'System',
                    $log->action,
                    $log->severity ?? 'info',
                    $log->correlation_id ?? '',
                    $log->ip_address ?? '',
                    $log->user_agent ?? '',
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Get audit log statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $dateFrom = $request->input('date_from', now()->subDays(30)->format('Y-m-d'));
        $dateTo = $request->input('date_to', now()->format('Y-m-d'));

        return $this->dataResponse($this->auditService->getStats($dateFrom, $dateTo));
    }
}
