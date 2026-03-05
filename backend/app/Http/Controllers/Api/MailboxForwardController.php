<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Models\Mailbox;
use App\Models\MailboxForward;
use App\Services\AuditService;
use App\Services\Email\MailboxAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MailboxForwardController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private AuditService $auditService,
        private MailboxAccessService $accessService,
    ) {}

    public function show(Request $request, Mailbox $mailbox): JsonResponse
    {
        if (! $this->accessService->hasAccess($request->user(), $mailbox->id)) {
            return $this->errorResponse('Not found', 404);
        }

        $forward = MailboxForward::where('mailbox_id', $mailbox->id)
            ->where('user_id', $request->user()->id)
            ->first();

        return response()->json(['forward' => $forward]);
    }

    public function upsert(Request $request, Mailbox $mailbox): JsonResponse
    {
        if (! $this->accessService->canManage($request->user(), $mailbox->id)) {
            return $this->errorResponse('Not found', 404);
        }

        $validated = $request->validate([
            'forward_to' => ['required', 'email', 'max:255'],
            'keep_local_copy' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $forward = MailboxForward::updateOrCreate(
            [
                'mailbox_id' => $mailbox->id,
                'user_id' => $request->user()->id,
            ],
            [
                'forward_to' => $validated['forward_to'],
                'keep_local_copy' => $validated['keep_local_copy'] ?? true,
                'is_active' => $validated['is_active'] ?? true,
            ]
        );

        $this->auditService->log('mailbox.forward_updated', $mailbox, [], [
            'forward_to' => $forward->forward_to,
            'keep_local_copy' => $forward->keep_local_copy,
        ]);

        return $this->successResponse('Forwarding updated', ['forward' => $forward]);
    }

    public function destroy(Request $request, Mailbox $mailbox): JsonResponse
    {
        if (! $this->accessService->canManage($request->user(), $mailbox->id)) {
            return $this->errorResponse('Not found', 404);
        }

        $forward = MailboxForward::where('mailbox_id', $mailbox->id)
            ->where('user_id', $request->user()->id)
            ->first();

        if ($forward) {
            $this->auditService->log('mailbox.forward_removed', $mailbox, [
                'forward_to' => $forward->forward_to,
            ], []);

            $forward->delete();
        }

        return $this->successResponse('Forwarding removed');
    }
}
