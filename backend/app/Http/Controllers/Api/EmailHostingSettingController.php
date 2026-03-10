<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Services\AuditService;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailHostingSettingController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private SettingService $settingService,
        private AuditService $auditService,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'email_hosting' => $this->settingService->getGroupMasked('email_hosting'),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'spam_threshold' => ['sometimes', 'numeric', 'min:1', 'max:10'],
            'max_attachment_size' => ['sometimes', 'numeric', 'min:1', 'max:100'],
        ]);

        foreach (['spam_threshold', 'max_attachment_size'] as $field) {
            if (isset($validated[$field])) {
                $validated[$field] = (string) $validated[$field];
            }
        }

        $userId = $request->user()->id;
        $old = $this->settingService->getGroup('email_hosting');
        $this->settingService->setGroup('email_hosting', $validated, $userId);
        $new = array_merge($old, $validated);
        $this->auditService->logSettings('email_hosting', $old, $new, $userId);

        return $this->successResponse('Email hosting settings updated successfully');
    }
}
