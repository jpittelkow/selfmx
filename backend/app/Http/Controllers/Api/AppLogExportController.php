<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AppLogExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AppLogExportController extends Controller
{
    public function __construct(
        private AppLogExportService $exportService
    ) {}

    /**
     * Return recent log entries as JSON for inline display.
     * Query params: limit (default 200, max 500), level, correlation_id.
     */
    public function recent(Request $request): \Illuminate\Http\JsonResponse
    {
        $limit = min((int) $request->input('limit', 200), 500);
        $level = $request->input('level');
        $correlationId = $request->input('correlation_id');

        $files = $this->exportService->getLogFilesInRange(null, null);

        // Read entries from all files, keep last $limit matching entries
        $entries = [];
        foreach ($files as $path) {
            if (! is_readable($path)) {
                continue;
            }
            $handle = fopen($path, 'r');
            if ($handle === false) {
                continue;
            }
            while (($line = fgets($handle)) !== false) {
                $entry = $this->exportService->parseLine($line);
                if ($entry === null) {
                    continue;
                }
                if (! $this->exportService->passesFilters($entry, $level, $correlationId)) {
                    continue;
                }
                $entries[] = $entry;
                // Keep a rolling window to limit memory
                if (count($entries) > $limit * 2) {
                    $entries = array_slice($entries, -$limit);
                }
            }
            fclose($handle);
        }

        // Return the last $limit entries (newest last)
        $entries = array_slice($entries, -$limit);

        return response()->json(['data' => array_values($entries)]);
    }

    /**
     * Export application log files as CSV or JSON.
     * Query params: date_from, date_to, level, correlation_id, format (csv|json, default csv).
     */
    public function export(Request $request): StreamedResponse
    {
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $level = $request->input('level');
        $correlationId = $request->input('correlation_id');
        $format = $request->input('format', 'csv');
        if (! in_array($format, ['csv', 'json'], true)) {
            $format = 'csv';
        }

        $files = $this->exportService->getLogFilesInRange($dateFrom, $dateTo);

        $filename = 'app_logs_' . date('Y-m-d_His') . ($format === 'csv' ? '.csv' : '.jsonl');

        $headers = [
            'Content-Type' => $format === 'csv' ? 'text/csv' : 'application/x-ndjson',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        return response()->stream(
            function () use ($files, $format, $level, $correlationId, $dateFrom, $dateTo) {
                $service = $this->exportService;
                $out = fopen('php://output', 'w');
                if ($out === false) {
                    return;
                }
                if ($format === 'csv') {
                    fputcsv($out, ['Datetime', 'Level', 'Message', 'Correlation ID']);
                }
                foreach ($files as $path) {
                    if (! is_readable($path)) {
                        continue;
                    }
                    $handle = fopen($path, 'r');
                    if ($handle === false) {
                        continue;
                    }
                    while (($line = fgets($handle)) !== false) {
                        $entry = $service->parseLine($line);
                        if ($entry === null) {
                            continue;
                        }
                        if (! $service->passesFilters($entry, $level, $correlationId)) {
                            continue;
                        }
                        if (! $service->inDateRange($entry, $dateFrom, $dateTo)) {
                            continue;
                        }
                        if ($format === 'csv') {
                            fputcsv($out, [
                                $entry['datetime'],
                                $entry['level'],
                                $entry['message'],
                                $entry['correlation_id'] ?? '',
                            ]);
                        } else {
                            echo json_encode($entry) . "\n";
                        }
                    }
                    fclose($handle);
                }
            },
            200,
            $headers
        );
    }
}
