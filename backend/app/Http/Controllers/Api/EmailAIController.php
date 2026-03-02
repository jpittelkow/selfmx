<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Models\Email;
use App\Models\EmailLabel;
use App\Models\EmailThread;
use App\Services\Email\EmailAIService;
use App\Services\Email\MailboxAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailAIController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private EmailAIService $aiService,
        private MailboxAccessService $accessService,
    ) {}

    /**
     * GET /email/ai/status — AI availability and enabled features for the current user.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'available' => $this->aiService->isAvailable($user),
            'features' => $this->aiService->getEnabledFeatures($user),
        ]);
    }

    /**
     * GET /email/ai/settings — per-user AI feature toggle values.
     */
    public function settings(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'summarization_enabled' => (bool) $user->getSetting('email_ai', 'summarization_enabled', true),
            'auto_labeling_enabled' => (bool) $user->getSetting('email_ai', 'auto_labeling_enabled', false),
            'auto_labeling_auto_apply' => (bool) $user->getSetting('email_ai', 'auto_labeling_auto_apply', false),
            'priority_inbox_enabled' => (bool) $user->getSetting('email_ai', 'priority_inbox_enabled', false),
            'smart_replies_enabled' => (bool) $user->getSetting('email_ai', 'smart_replies_enabled', false),
            'process_inbound_automatically' => (bool) $user->getSetting('email_ai', 'process_inbound_automatically', true),
        ]);
    }

    /**
     * PUT /email/ai/settings — update per-user AI feature toggles.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'summarization_enabled' => ['sometimes', 'boolean'],
            'auto_labeling_enabled' => ['sometimes', 'boolean'],
            'auto_labeling_auto_apply' => ['sometimes', 'boolean'],
            'priority_inbox_enabled' => ['sometimes', 'boolean'],
            'smart_replies_enabled' => ['sometimes', 'boolean'],
            'process_inbound_automatically' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();

        foreach ($validated as $key => $value) {
            $user->setSetting('email_ai', $key, $value);
        }

        return response()->json(['message' => 'AI settings updated']);
    }

    /**
     * GET /email/ai/thread/{thread}/summary — get cached thread summary.
     */
    public function threadSummary(Request $request, EmailThread $thread): JsonResponse
    {
        $user = $request->user();
        $mailboxIds = $this->accessService->getAccessibleMailboxIds($user);
        if (! $thread->emails()->whereIn('mailbox_id', $mailboxIds)->exists()) {
            return $this->errorResponse('Thread not found', 404);
        }

        $result = $this->aiService->getCachedResult($thread->id, 'summary', $user->id, 'thread');

        if (!$result) {
            return response()->json(['data' => null, 'stale' => false]);
        }

        $stale = $this->aiService->isStaleSummary($result, $thread);

        return response()->json([
            'data' => $result->result,
            'stale' => $stale,
            'provider' => $result->provider,
            'created_at' => $result->created_at,
        ]);
    }

    /**
     * POST /email/ai/thread/{thread}/summarize — generate/regenerate thread summary.
     */
    public function requestSummary(Request $request, EmailThread $thread): JsonResponse
    {
        $user = $request->user();
        $mailboxIds = $this->accessService->getAccessibleMailboxIds($user);
        if (! $thread->emails()->whereIn('mailbox_id', $mailboxIds)->exists()) {
            return $this->errorResponse('Thread not found', 404);
        }

        if (!$this->aiService->isAvailable($user)) {
            return $this->errorResponse('No AI provider configured', 422);
        }

        $result = $this->aiService->summarizeThread($user, $thread, $mailboxIds);

        if (!$result) {
            return $this->errorResponse('Failed to generate summary', 500);
        }

        return response()->json([
            'data' => $result->result,
            'stale' => false,
            'provider' => $result->provider,
            'created_at' => $result->created_at,
        ]);
    }

    /**
     * GET /email/ai/email/{email}/labels — get label suggestions for an email.
     */
    public function suggestedLabels(Request $request, Email $email): JsonResponse
    {
        $user = $request->user();
        $mailboxIds = $this->accessService->getAccessibleMailboxIds($user);

        if (!in_array($email->mailbox_id, $mailboxIds)) {
            return $this->errorResponse('Email not found', 404);
        }

        // Return cached or generate
        if (!$this->aiService->isAvailable($user)) {
            return response()->json(['data' => null]);
        }

        $result = $this->aiService->suggestLabels($user, $email);

        return response()->json([
            'data' => $result?->result,
            'provider' => $result?->provider,
        ]);
    }

    /**
     * POST /email/ai/email/{email}/labels/apply — apply AI-suggested labels.
     */
    public function applyLabels(Request $request, Email $email): JsonResponse
    {
        $user = $request->user();
        $mailboxIds = $this->accessService->getAccessibleMailboxIds($user);

        if (!in_array($email->mailbox_id, $mailboxIds)) {
            return $this->errorResponse('Email not found', 404);
        }

        $validated = $request->validate([
            'label_ids' => ['required', 'array'],
            'label_ids.*' => ['integer'],
            'new_labels' => ['sometimes', 'array'],
            'new_labels.*.name' => ['required', 'string', 'max:50'],
            'new_labels.*.color' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        $appliedIds = [];

        // Apply existing labels
        foreach ($validated['label_ids'] ?? [] as $labelId) {
            $label = EmailLabel::where('id', $labelId)->where('user_id', $user->id)->first();
            if ($label && !$email->labels()->where('email_labels.id', $labelId)->exists()) {
                $email->labels()->attach($labelId);
                $appliedIds[] = $labelId;
            }
        }

        // Create and apply new labels
        foreach ($validated['new_labels'] ?? [] as $newLabel) {
            $existing = EmailLabel::where('user_id', $user->id)->where('name', $newLabel['name'])->first();
            if (!$existing) {
                $existing = EmailLabel::create([
                    'user_id' => $user->id,
                    'name' => $newLabel['name'],
                    'color' => $newLabel['color'] ?? null,
                ]);
            }
            if (!$email->labels()->where('email_labels.id', $existing->id)->exists()) {
                $email->labels()->attach($existing->id);
                $appliedIds[] = $existing->id;
            }
        }

        return response()->json([
            'message' => 'Labels applied',
            'applied_label_ids' => $appliedIds,
        ]);
    }

    /**
     * GET /email/ai/email/{email}/priority — get priority score for an email.
     */
    public function emailPriority(Request $request, Email $email): JsonResponse
    {
        $user = $request->user();
        $mailboxIds = $this->accessService->getAccessibleMailboxIds($user);

        if (!in_array($email->mailbox_id, $mailboxIds)) {
            return $this->errorResponse('Email not found', 404);
        }

        if (!$this->aiService->isAvailable($user)) {
            return response()->json(['data' => null]);
        }

        $result = $this->aiService->scorePriority($user, $email);

        return response()->json([
            'data' => $result?->result,
            'provider' => $result?->provider,
        ]);
    }

    /**
     * GET /email/ai/email/{email}/replies — get cached smart reply suggestions.
     */
    public function smartReplies(Request $request, Email $email): JsonResponse
    {
        $user = $request->user();
        $mailboxIds = $this->accessService->getAccessibleMailboxIds($user);

        if (!in_array($email->mailbox_id, $mailboxIds)) {
            return $this->errorResponse('Email not found', 404);
        }

        $cached = $this->aiService->getCachedResult($email->id, 'replies', $user->id, 'email');

        return response()->json([
            'data' => $cached?->result,
            'provider' => $cached?->provider,
        ]);
    }

    /**
     * POST /email/ai/email/{email}/replies/generate — generate smart reply suggestions.
     */
    public function generateReplies(Request $request, Email $email): JsonResponse
    {
        $user = $request->user();
        $mailboxIds = $this->accessService->getAccessibleMailboxIds($user);

        if (!in_array($email->mailbox_id, $mailboxIds)) {
            return $this->errorResponse('Email not found', 404);
        }

        if (!$this->aiService->isAvailable($user)) {
            return $this->errorResponse('No AI provider configured', 422);
        }

        $result = $this->aiService->generateReplies($user, $email, $mailboxIds);

        if (!$result) {
            return $this->errorResponse('Failed to generate replies', 500);
        }

        return response()->json([
            'data' => $result->result,
            'provider' => $result->provider,
        ]);
    }
}
