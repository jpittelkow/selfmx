<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Jobs\ImportEmailBatchJob;
use App\Models\Mailbox;
use App\Services\Email\EmailImportService;
use App\Services\Email\MailboxAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class EmailImportController extends Controller
{
    use ApiResponseTrait;

    private const SYNC_SIZE_LIMIT = 10 * 1024 * 1024; // 10MB

    public function __construct(
        private EmailImportService $importService,
        private MailboxAccessService $accessService,
    ) {}

    /**
     * Import emails from an uploaded mbox or eml file.
     */
    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:102400'], // 100MB max
            'mailbox_id' => ['required', 'integer', 'exists:mailboxes,id'],
            'format' => ['required', 'string', 'in:mbox,eml'],
        ]);

        $user = $request->user();
        $mailboxId = (int) $validated['mailbox_id'];

        if (!$this->accessService->hasAccess($user, $mailboxId, 'member')) {
            return $this->errorResponse('Not found', 404);
        }

        $file = $request->file('file');
        $format = $validated['format'];

        // Small files: process synchronously
        if ($file->getSize() < self::SYNC_SIZE_LIMIT) {
            $mailbox = Mailbox::with('emailDomain')->findOrFail($mailboxId);
            $tempPath = $file->getRealPath();
            $result = match ($format) {
                'mbox' => $this->importService->importMbox($tempPath, $mailbox, $user),
                'eml' => $this->importService->importEml($tempPath, $mailbox, $user),
            };

            return response()->json([
                'status' => 'completed',
                'result' => $result->toArray(),
            ]);
        }

        // Large files: store temporarily and dispatch async job
        $jobId = Str::uuid()->toString();
        $tempDir = storage_path('app/imports');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $tempPath = $tempDir . '/' . $jobId . '.' . $format;
        $file->move($tempDir, $jobId . '.' . $format);

        Cache::put("email_import:{$jobId}", [
            'status' => 'queued',
            'updated_at' => now()->toIso8601String(),
        ], now()->addHours(1));

        ImportEmailBatchJob::dispatch($tempPath, $format, $mailboxId, $user->id, $jobId);

        return response()->json([
            'status' => 'queued',
            'job_id' => $jobId,
        ], 202);
    }

    /**
     * Check the status of an async import job.
     */
    public function status(Request $request, string $jobId): JsonResponse
    {
        $data = Cache::get("email_import:{$jobId}");

        if (!$data) {
            return $this->errorResponse('Import job not found', 404);
        }

        return response()->json($data);
    }
}
