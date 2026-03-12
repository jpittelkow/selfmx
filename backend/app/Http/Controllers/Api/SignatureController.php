<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Models\Signature;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SignatureController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private AuditService $auditService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $signatures = Signature::where('user_id', $request->user()->id)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return response()->json(['signatures' => $signatures]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $validated['user_id'] = $request->user()->id;

        $signature = DB::transaction(function () use ($validated, $request) {
            if (!empty($validated['is_default'])) {
                $this->unsetOtherDefaults($request->user()->id);
            }

            return Signature::create($validated);
        });

        $this->auditService->log('signature.created', $signature, [], [
            'name' => $signature->name,
        ]);

        return $this->createdResponse('Signature created', ['signature' => $signature]);
    }

    public function show(Request $request, Signature $signature): JsonResponse
    {
        if ($signature->user_id !== $request->user()->id) {
            return $this->errorResponse('Not found', 404);
        }

        return response()->json(['signature' => $signature]);
    }

    public function update(Request $request, Signature $signature): JsonResponse
    {
        if ($signature->user_id !== $request->user()->id) {
            return $this->errorResponse('Not found', 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'body' => ['sometimes', 'string'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        // Prevent unsetting default without a replacement
        if (array_key_exists('is_default', $validated) && !$validated['is_default'] && $signature->is_default) {
            $otherCount = Signature::where('user_id', $request->user()->id)
                ->where('id', '!=', $signature->id)
                ->count();
            if ($otherCount === 0) {
                // Only signature — keep it as default
                unset($validated['is_default']);
            }
        }

        $old = $signature->only(array_keys($validated));

        DB::transaction(function () use ($signature, $validated, $request) {
            if (!empty($validated['is_default'])) {
                $this->unsetOtherDefaults($request->user()->id, $signature->id);
            }

            $signature->update($validated);
        });

        $this->auditService->log('signature.updated', $signature, $old, $validated);

        return $this->successResponse('Signature updated', ['signature' => $signature->fresh()]);
    }

    public function destroy(Request $request, Signature $signature): JsonResponse
    {
        if ($signature->user_id !== $request->user()->id) {
            return $this->errorResponse('Not found', 404);
        }

        $this->auditService->log('signature.deleted', $signature, [
            'name' => $signature->name,
        ], []);

        $signature->delete();

        return $this->successResponse('Signature deleted');
    }

    public function setDefault(Request $request, Signature $signature): JsonResponse
    {
        if ($signature->user_id !== $request->user()->id) {
            return $this->errorResponse('Not found', 404);
        }

        DB::transaction(function () use ($signature) {
            Signature::where('user_id', $signature->user_id)
                ->where('id', '!=', $signature->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);

            $signature->update(['is_default' => true]);
        });

        $this->auditService->log('signature.set_default', $signature, [], [
            'name' => $signature->name,
        ]);

        return $this->successResponse('Default signature updated', ['signature' => $signature->fresh()]);
    }

    private function unsetOtherDefaults(int $userId, ?int $excludeId = null): void
    {
        $query = Signature::where('user_id', $userId)
            ->where('is_default', true);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $query->update(['is_default' => false]);
    }
}
