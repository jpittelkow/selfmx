<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Models\EmailThread;
use App\Models\EmailUserState;
use App\Services\Email\MailboxAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmailThreadController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private MailboxAccessService $accessService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $mailboxIds = $this->accessService->getAccessibleMailboxIds($user);
        $perPage = $request->input('per_page', config('app.pagination.default', 25));

        // Filter by specific mailbox or all accessible mailboxes
        if ($request->has('mailbox_id')) {
            $mailboxId = (int) $request->input('mailbox_id');
            if (! in_array($mailboxId, $mailboxIds)) {
                return $this->errorResponse('Not found', 404);
            }
            $filterMailboxIds = [$mailboxId];
        } else {
            $filterMailboxIds = $mailboxIds;
        }

        // Only include threads with at least one visible (non-trashed, non-spam, non-draft) email
        $query = EmailThread::whereHas('emails', function ($q) use ($filterMailboxIds) {
            $q->whereIn('mailbox_id', $filterMailboxIds)
                ->where('is_trashed', false)
                ->where('is_spam', false)
                ->where('is_draft', false);
        });

        $threads = $query->withCount(['emails' => function ($q) use ($filterMailboxIds) {
            $q->whereIn('mailbox_id', $filterMailboxIds)
                ->where('is_trashed', false)
                ->where('is_spam', false)
                ->where('is_draft', false);
        }])
            ->with(['latestEmail' => function ($q) use ($user) {
                $q->leftJoin('email_user_states', function ($join) use ($user) {
                    $join->on('emails.id', '=', 'email_user_states.email_id')
                        ->where('email_user_states.user_id', '=', $user->id);
                })
                    ->select('emails.id', 'emails.thread_id', 'emails.from_address', 'emails.from_name', 'emails.subject', 'emails.is_read', 'emails.is_starred', 'emails.sent_at', 'emails.direction', 'emails.mailbox_id')
                    ->selectRaw('COALESCE(email_user_states.is_read, emails.is_read) as effective_is_read')
                    ->selectRaw('COALESCE(email_user_states.is_starred, emails.is_starred) as effective_is_starred')
                    ->selectRaw('SUBSTR(emails.body_text, 1, 150) as preview_text')
                    ->withCount('attachments')
                    ->with([
                        'recipients:id,email_id,type,address,name',
                        'mailbox:id,address,email_domain_id',
                        'mailbox.emailDomain:id,name',
                    ]);
            }])
            ->when($request->input('sort') === 'priority', function ($q) use ($user) {
                // Sort by AI priority score (highest first), falling back to date
                $jsonExpr = match (DB::getDriverName()) {
                    'pgsql' => "COALESCE((result->>'score')::float, 0)",
                    default => "COALESCE(JSON_EXTRACT(result, '$.score'), 0)",
                };
                $q->addSelect([
                    'priority_score' => DB::table('email_ai_results')
                        ->whereColumn('email_ai_results.thread_id', 'email_threads.id')
                        ->where('email_ai_results.type', 'priority')
                        ->where('email_ai_results.user_id', $user->id)
                        ->selectRaw($jsonExpr)
                        ->orderByDesc('created_at')
                        ->limit(1),
                ])
                ->orderByDesc('priority_score')
                ->orderByDesc('email_threads.last_message_at');
            }, function ($q) {
                $q->orderByDesc('last_message_at');
            })
            ->paginate($perPage);

        return $this->dataResponse($threads);
    }

    public function show(Request $request, EmailThread $emailThread): JsonResponse
    {
        $user = $request->user();
        $mailboxIds = $this->accessService->getAccessibleMailboxIds($user);

        // Check that the user can access at least one email in this thread
        if (! $emailThread->emails()->whereIn('mailbox_id', $mailboxIds)->exists()) {
            return $this->errorResponse('Not found', 404);
        }

        $emailThread->load(['emails' => function ($q) use ($mailboxIds) {
            $q->whereIn('mailbox_id', $mailboxIds)
                ->where('is_trashed', false)
                ->orderBy('sent_at')
                ->with(['recipients', 'attachments', 'labels', 'mailbox.emailDomain']);
        }]);

        // Auto-mark all unread emails in the thread as read for this user
        $emailIds = $emailThread->emails->pluck('id');
        $existingStates = EmailUserState::where('user_id', $user->id)
            ->whereIn('email_id', $emailIds)
            ->pluck('is_read', 'email_id');

        $toUpsert = [];
        foreach ($emailThread->emails as $email) {
            $effectiveRead = $existingStates->has($email->id) ? $existingStates[$email->id] : $email->is_read;
            if (! $effectiveRead) {
                $toUpsert[] = [
                    'email_id' => $email->id,
                    'user_id' => $user->id,
                    'is_read' => true,
                ];
            }
        }
        if (! empty($toUpsert)) {
            EmailUserState::upsert($toUpsert, ['email_id', 'user_id'], ['is_read']);
        }

        return response()->json(['thread' => $emailThread]);
    }
}
