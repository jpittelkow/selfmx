<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Models\SpamFilterList;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SpamFilterController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private AuditService $auditService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $type = $request->input('type');
        $query = SpamFilterList::where('user_id', $request->user()->id);

        if ($type && in_array($type, ['allow', 'block'])) {
            $query->where('type', $type);
        }

        $entries = $query->orderBy('value')->get();

        return response()->json(['entries' => $entries]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['allow', 'block'])],
            'match_type' => ['sometimes', Rule::in(['exact', 'domain'])],
            'value' => ['required', 'string', 'max:320'],
        ]);

        $validated['user_id'] = $request->user()->id;
        $validated['match_type'] = $validated['match_type'] ?? 'exact';
        $validated['value'] = strtolower(trim($validated['value']));

        // Check for duplicate
        $exists = SpamFilterList::where('user_id', $validated['user_id'])
            ->where('type', $validated['type'])
            ->where('value', $validated['value'])
            ->exists();

        if ($exists) {
            return $this->errorResponse('This entry already exists', 422);
        }

        $entry = SpamFilterList::create($validated);

        $this->auditService->log("spam_filter.created", $entry, null, [
            'type' => $entry->type,
            'value' => $entry->value,
        ]);

        return response()->json(['entry' => $entry], 201);
    }

    public function destroy(Request $request, SpamFilterList $entry): JsonResponse
    {
        if ($entry->user_id !== $request->user()->id) {
            return $this->errorResponse('Not found', 404);
        }

        $this->auditService->log("spam_filter.deleted", $entry, [
            'type' => $entry->type,
            'value' => $entry->value,
        ]);

        $entry->delete();

        return response()->json(['message' => 'Entry removed']);
    }

    public function bulkStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entries' => ['required', 'array', 'min:1', 'max:100'],
            'entries.*.type' => ['required', Rule::in(['allow', 'block'])],
            'entries.*.match_type' => ['sometimes', Rule::in(['exact', 'domain'])],
            'entries.*.value' => ['required', 'string', 'max:320'],
        ]);

        $userId = $request->user()->id;
        $created = 0;
        $skipped = 0;

        foreach ($validated['entries'] as $entryData) {
            $entryData['user_id'] = $userId;
            $entryData['match_type'] = $entryData['match_type'] ?? 'exact';
            $entryData['value'] = strtolower(trim($entryData['value']));

            $exists = SpamFilterList::where('user_id', $userId)
                ->where('type', $entryData['type'])
                ->where('value', $entryData['value'])
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            SpamFilterList::create($entryData);
            $created++;
        }

        $this->auditService->log("spam_filter.bulk_created", null, null, [
            'created' => $created,
            'skipped' => $skipped,
        ]);

        return response()->json([
            'created' => $created,
            'skipped' => $skipped,
        ], 201);
    }
}
