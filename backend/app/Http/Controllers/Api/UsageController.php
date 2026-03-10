<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Services\UsageStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UsageController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private UsageStatsService $usageStatsService
    ) {}

    /**
     * Get aggregated usage statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'integration' => 'nullable|string|in:llm,email,sms,storage,broadcasting,payments',
            'provider' => 'nullable|string',
            'group_by' => 'nullable|string|in:day,week,month',
        ]);

        $dateFrom = $request->input('date_from', now()->subDays(30)->format('Y-m-d'));
        $dateTo = $request->input('date_to', now()->format('Y-m-d'));
        $integration = $request->input('integration');
        $provider = $request->input('provider');
        $groupBy = $request->input('group_by', 'day');

        $stats = $this->usageStatsService->getStats(
            $dateFrom,
            $dateTo,
            $integration,
            $provider,
            $groupBy
        );

        return $this->dataResponse($stats);
    }

    /**
     * Get detailed breakdown for a single integration.
     */
    public function breakdown(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'integration' => 'required|string|in:llm,email,sms,storage,broadcasting,payments',
            'user_id' => 'nullable|integer',
        ]);

        $dateFrom = $request->input('date_from', now()->subDays(30)->format('Y-m-d'));
        $dateTo = $request->input('date_to', now()->format('Y-m-d'));
        $integration = $request->input('integration');
        $userId = $request->input('user_id') ? (int) $request->input('user_id') : null;

        $breakdown = $this->usageStatsService->getBreakdown(
            $dateFrom,
            $dateTo,
            $integration,
            $userId
        );

        return $this->dataResponse($breakdown);
    }

    /**
     * Export usage data as CSV.
     */
    public function export(Request $request): StreamedResponse
    {
        $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'integration' => 'nullable|string|in:llm,email,sms,storage,broadcasting,payments',
            'provider' => 'nullable|string',
        ]);

        $dateFrom = $request->input('date_from', now()->subDays(30)->format('Y-m-d'));
        $dateTo = $request->input('date_to', now()->format('Y-m-d'));
        $integration = $request->input('integration');
        $provider = $request->input('provider');

        $filename = 'usage-export-' . $dateFrom . '-to-' . $dateTo . '.csv';

        return response()->streamDownload(function () use ($dateFrom, $dateTo, $integration, $provider) {
            $handle = fopen('php://output', 'w');

            // Header row
            fputcsv($handle, ['Date', 'Integration', 'Provider', 'Metric', 'Quantity', 'Estimated Cost (USD)', 'User', 'User Email', 'Metadata']);

            $this->usageStatsService->exportToStream($handle, $dateFrom, $dateTo, $integration, $provider);

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
