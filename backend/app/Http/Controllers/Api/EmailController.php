<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveDraftRequest;
use App\Http\Requests\SendEmailRequest;
use App\Http\Traits\ApiResponseTrait;
use App\Models\Email;
use App\Models\EmailUserState;
use App\Services\Email\EmailService;
use App\Services\Email\MailboxAccessService;
use App\Services\Search\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmailController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private EmailService $emailService,
        private SearchService $searchService,
        private MailboxAccessService $accessService,
    ) {}

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'max:500'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $results = $this->searchService->searchEmails(
            $validated['q'],
            $request->user(),
            $validated['per_page'] ?? null,
            (int) ($validated['page'] ?? 1),
        );

        return $this->dataResponse($results);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $mailboxIds = $this->accessService->getAccessibleMailboxIds($user);
        $perPage = $request->input('per_page', config('app.pagination.default', 25));

        $query = Email::whereIn('mailbox_id', $mailboxIds)
            ->with(['recipients', 'labels', 'attachments', 'mailbox.emailDomain']);

        // Per-user read/star state via left join
        $query->leftJoin('email_user_states', function ($join) use ($user) {
            $join->on('emails.id', '=', 'email_user_states.email_id')
                ->where('email_user_states.user_id', '=', $user->id);
        })
            ->select('emails.*')
            ->selectRaw('COALESCE(email_user_states.is_read, emails.is_read) as effective_is_read')
            ->selectRaw('COALESCE(email_user_states.is_starred, emails.is_starred) as effective_is_starred');

        // Filters
        if ($request->has('mailbox_id')) {
            $mailboxId = (int) $request->input('mailbox_id');
            if (! in_array($mailboxId, $mailboxIds)) {
                return $this->errorResponse('Not found', 404);
            }
            $query->where('emails.mailbox_id', $mailboxId);
        }

        if ($request->input('starred')) {
            $query->whereRaw('COALESCE(email_user_states.is_starred, emails.is_starred) = ?', [true]);
        }

        if ($request->input('spam')) {
            $query->where('emails.is_spam', true);
        } else {
            // By default exclude spam
            $query->where('emails.is_spam', false);
        }

        if ($request->input('trashed')) {
            $query->where('emails.is_trashed', true);
        } else {
            // By default exclude trashed
            $query->where('emails.is_trashed', false);
        }

        if ($request->input('drafts')) {
            $query->where('emails.is_draft', true);
        } else {
            $query->where('emails.is_draft', false);
        }

        if ($request->input('snoozed')) {
            $query->whereNotNull('email_user_states.snoozed_until')
                ->where('email_user_states.snoozed_until', '>', now());
        }

        if ($request->has('is_read')) {
            $isRead = $request->boolean('is_read');
            $query->whereRaw('COALESCE(email_user_states.is_read, emails.is_read) = ?', [$isRead]);
        }

        if ($request->input('direction')) {
            $query->where('emails.direction', $request->input('direction'));
        }

        if ($request->has('label_id')) {
            $query->whereHas('labels', function ($q) use ($request) {
                $q->where('email_labels.id', $request->input('label_id'));
            });
        }

        $emails = $query->orderByDesc('emails.sent_at')->paginate($perPage);

        return $this->dataResponse($emails);
    }

    public function show(Request $request, Email $email): JsonResponse
    {
        if (! $this->accessService->hasAccess($request->user(), $email->mailbox_id)) {
            return $this->errorResponse('Not found', 404);
        }

        $email->load(['recipients', 'labels', 'attachments', 'thread', 'mailbox.emailDomain']);

        // Auto-mark as read (per-user state)
        $userState = EmailUserState::where('email_id', $email->id)
            ->where('user_id', $request->user()->id)
            ->first();

        $effectiveRead = $userState ? $userState->is_read : $email->is_read;
        if (! $effectiveRead) {
            EmailUserState::updateOrCreate(
                ['email_id' => $email->id, 'user_id' => $request->user()->id],
                ['is_read' => true],
            );
        }

        // Append effective state for the response
        $email->setAttribute('effective_is_read', true);
        $email->setAttribute('effective_is_starred', $userState ? $userState->is_starred : $email->is_starred);

        return response()->json(['email' => $email]);
    }

    public function destroy(Request $request, Email $email): JsonResponse
    {
        if (! $this->accessService->hasAccess($request->user(), $email->mailbox_id, 'member')) {
            return $this->errorResponse('Not found', 404);
        }

        if ($email->is_trashed) {
            $this->emailService->deleteForever($email);

            return $this->successResponse('Email permanently deleted');
        }

        $this->emailService->moveToTrash($email);

        return $this->successResponse('Email moved to trash');
    }

    public function markRead(Request $request, Email $email): JsonResponse
    {
        if (! $this->accessService->hasAccess($request->user(), $email->mailbox_id)) {
            return $this->errorResponse('Not found', 404);
        }

        $read = $request->input('is_read', true);

        EmailUserState::updateOrCreate(
            ['email_id' => $email->id, 'user_id' => $request->user()->id],
            ['is_read' => (bool) $read],
        );

        return $this->successResponse('Updated');
    }

    public function toggleStar(Request $request, Email $email): JsonResponse
    {
        if (! $this->accessService->hasAccess($request->user(), $email->mailbox_id)) {
            return $this->errorResponse('Not found', 404);
        }

        $userState = EmailUserState::firstOrCreate(
            ['email_id' => $email->id, 'user_id' => $request->user()->id],
            ['is_read' => $email->is_read, 'is_starred' => $email->is_starred],
        );

        $userState->update(['is_starred' => ! $userState->is_starred]);

        return $this->successResponse('Updated', ['is_starred' => $userState->is_starred]);
    }

    public function toggleSpam(Request $request, Email $email): JsonResponse
    {
        if (! $this->accessService->hasAccess($request->user(), $email->mailbox_id, 'member')) {
            return $this->errorResponse('Not found', 404);
        }

        $this->emailService->toggleSpam($email);

        return $this->successResponse('Updated', ['is_spam' => $email->fresh()->is_spam]);
    }

    public function bulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:read,unread,trash,restore,star,unstar,spam,not_spam,label,unlabel,delete'],
            'email_ids' => ['required', 'array'],
            'email_ids.*' => ['integer', 'exists:emails,id'],
            'label_id' => ['required_if:action,label,unlabel', 'integer', 'exists:email_labels,id'],
        ]);

        $user = $request->user();
        $mailboxIds = $this->accessService->getAccessibleMailboxIds($user);

        $emails = Email::whereIn('mailbox_id', $mailboxIds)
            ->whereIn('id', $validated['email_ids'])
            ->get();

        foreach ($emails as $email) {
            match ($validated['action']) {
                'read' => EmailUserState::updateOrCreate(
                    ['email_id' => $email->id, 'user_id' => $user->id],
                    ['is_read' => true],
                ),
                'unread' => EmailUserState::updateOrCreate(
                    ['email_id' => $email->id, 'user_id' => $user->id],
                    ['is_read' => false],
                ),
                'star' => EmailUserState::updateOrCreate(
                    ['email_id' => $email->id, 'user_id' => $user->id],
                    ['is_starred' => true],
                ),
                'unstar' => EmailUserState::updateOrCreate(
                    ['email_id' => $email->id, 'user_id' => $user->id],
                    ['is_starred' => false],
                ),
                'trash' => $this->emailService->moveToTrash($email),
                'restore' => $this->emailService->restoreFromTrash($email),
                'spam' => $email->update(['is_spam' => true]),
                'not_spam' => $email->update(['is_spam' => false]),
                'label' => $email->labels()->syncWithoutDetaching([$validated['label_id']]),
                'unlabel' => $email->labels()->detach($validated['label_id']),
                'delete' => $this->emailService->deleteForever($email),
            };
        }

        return $this->successResponse('Bulk action completed', ['count' => $emails->count()]);
    }

    /**
     * Send a new email.
     */
    public function send(SendEmailRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Scheduled send: save as draft with send_at
        if (!empty($data['send_at']) && now()->lt($data['send_at'])) {
            $draft = $this->emailService->saveDraft($request->user(), $data);
            $draft->update(['send_at' => $data['send_at']]);

            return response()->json(['email' => $draft->fresh(), 'scheduled' => true], 202);
        }

        $email = $this->emailService->sendEmail($request->user(), $data);

        return response()->json(['email' => $email], 201);
    }

    /**
     * Save a new draft.
     */
    public function saveDraft(SaveDraftRequest $request): JsonResponse
    {
        $draft = $this->emailService->saveDraft($request->user(), $request->validated());

        return response()->json(['email' => $draft], 201);
    }

    /**
     * Update an existing draft.
     */
    public function updateDraft(Request $request, Email $email): JsonResponse
    {
        if (! $this->accessService->hasAccess($request->user(), $email->mailbox_id, 'member')) {
            return $this->errorResponse('Not found', 404);
        }

        if (! $email->is_draft) {
            return $this->errorResponse('Email is not a draft', 422);
        }

        $validated = $request->validate([
            'mailbox_id' => ['sometimes', 'nullable', 'integer', 'exists:mailboxes,id'],
            'to' => ['sometimes', 'array'],
            'to.*' => ['email'],
            'cc' => ['sometimes', 'array'],
            'cc.*' => ['email'],
            'bcc' => ['sometimes', 'array'],
            'bcc.*' => ['email'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:998'],
            'body_html' => ['sometimes', 'nullable', 'string'],
            'body_text' => ['sometimes', 'nullable', 'string'],
            'in_reply_to' => ['sometimes', 'nullable', 'string'],
            'references' => ['sometimes', 'nullable', 'string'],
            'thread_id' => ['sometimes', 'nullable', 'integer'],
            'attachment_ids_to_keep' => ['sometimes', 'array'],
            'attachment_ids_to_keep.*' => ['integer'],
            'attachments' => ['sometimes', 'array'],
            'attachments.*' => ['file', 'max:25600'],
        ]);

        $draft = $this->emailService->updateDraft($email, $validated);

        return response()->json(['email' => $draft]);
    }

    /**
     * Send an existing draft.
     */
    public function sendDraft(Request $request, Email $email): JsonResponse
    {
        if (! $this->accessService->hasAccess($request->user(), $email->mailbox_id, 'member')) {
            return $this->errorResponse('Not found', 404);
        }

        if (! $email->is_draft) {
            return $this->errorResponse('Email is not a draft', 422);
        }

        $email->load('recipients');

        $data = array_merge([
            'mailbox_id' => $email->mailbox_id,
            'to' => $email->recipients->where('type', 'to')->pluck('address')->toArray(),
            'cc' => $email->recipients->where('type', 'cc')->pluck('address')->toArray(),
            'bcc' => $email->recipients->where('type', 'bcc')->pluck('address')->toArray(),
            'subject' => $email->subject,
            'body_html' => $email->body_html,
            'body_text' => $email->body_text,
            'in_reply_to' => $email->in_reply_to,
            'references' => $email->references,
            'thread_id' => $email->thread_id,
            'draft_id' => $email->id,
        ], $request->only(['mailbox_id', 'to', 'cc', 'bcc', 'subject', 'body_html', 'body_text']));

        if (empty($data['mailbox_id']) || empty($data['to'])) {
            return $this->errorResponse('Draft is missing required fields (mailbox or recipient)', 422);
        }

        $sent = $this->emailService->sendEmail($request->user(), $data);

        return response()->json(['email' => $sent]);
    }

    /**
     * Get pre-filled data for reply/reply-all/forward.
     */
    public function replyData(Request $request, Email $email): JsonResponse
    {
        if (! $this->accessService->hasAccess($request->user(), $email->mailbox_id)) {
            return $this->errorResponse('Not found', 404);
        }

        $type = $request->input('type', 'reply');
        if (! in_array($type, ['reply', 'reply_all', 'forward'])) {
            return $this->errorResponse('Invalid type', 422);
        }

        $email->load(['recipients', 'attachments']);
        $data = $this->emailService->buildReplyData($email, $type, $request->user());

        return response()->json($data);
    }

    /**
     * Get unread email counts per mailbox.
     */
    public function unreadCounts(Request $request): JsonResponse
    {
        $user = $request->user();
        $mailboxIds = $this->accessService->getAccessibleMailboxIds($user);

        if (empty($mailboxIds)) {
            return response()->json(['total' => 0, 'per_mailbox' => new \stdClass]);
        }

        $counts = Email::whereIn('emails.mailbox_id', $mailboxIds)
            ->where('emails.is_spam', false)
            ->where('emails.is_trashed', false)
            ->where('emails.is_draft', false)
            ->leftJoin('email_user_states', function ($join) use ($user) {
                $join->on('emails.id', '=', 'email_user_states.email_id')
                    ->where('email_user_states.user_id', '=', $user->id);
            })
            ->whereRaw('COALESCE(email_user_states.is_read, emails.is_read) = ?', [false])
            ->groupBy('emails.mailbox_id')
            ->select('emails.mailbox_id', DB::raw('COUNT(*) as unread_count'))
            ->pluck('unread_count', 'mailbox_id');

        $total = $counts->sum();

        return response()->json([
            'total' => $total,
            'per_mailbox' => $counts->isEmpty() ? new \stdClass : $counts,
        ]);
    }

    /**
     * Snooze an email until a future time.
     */
    public function snooze(Request $request, Email $email): JsonResponse
    {
        if (! $this->accessService->hasAccess($request->user(), $email->mailbox_id)) {
            return $this->errorResponse('Not found', 404);
        }

        $validated = $request->validate([
            'snooze_until' => ['required', 'date', 'after:now'],
        ]);

        EmailUserState::updateOrCreate(
            ['email_id' => $email->id, 'user_id' => $request->user()->id],
            ['snoozed_until' => $validated['snooze_until'], 'is_read' => true],
        );

        return $this->successResponse('Email snoozed');
    }

    /**
     * Remove snooze from an email.
     */
    public function unsnooze(Request $request, Email $email): JsonResponse
    {
        if (! $this->accessService->hasAccess($request->user(), $email->mailbox_id)) {
            return $this->errorResponse('Not found', 404);
        }

        EmailUserState::updateOrCreate(
            ['email_id' => $email->id, 'user_id' => $request->user()->id],
            ['snoozed_until' => null, 'is_read' => false],
        );

        return $this->successResponse('Snooze removed');
    }
}
