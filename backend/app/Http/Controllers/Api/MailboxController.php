<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Models\Mailbox;
use App\Models\MailboxGroupAssignment;
use App\Models\MailboxUser;
use App\Services\AuditService;
use App\Services\Email\MailboxAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MailboxController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private AuditService $auditService,
        private MailboxAccessService $accessService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $accessible = $this->accessService->getAccessibleMailboxes($user);
        $mailboxIds = array_keys($accessible);

        $query = Mailbox::whereIn('id', $mailboxIds)->with(['emailDomain', 'defaultSignature']);

        if ($request->has('email_domain_id')) {
            $query->where('email_domain_id', $request->input('email_domain_id'));
        }

        $mailboxes = $query->orderByDesc('created_at')->get();

        // Append the user's role for each mailbox
        $mailboxes->each(function ($mailbox) use ($accessible) {
            $mailbox->setAttribute('user_role', $accessible[$mailbox->id] ?? null);
        });

        return response()->json(['mailboxes' => $mailboxes]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email_domain_id' => ['required', 'exists:email_domains,id'],
            'address' => ['required', 'string', 'max:255'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'signature' => ['sometimes', 'nullable', 'string'],
        ]);

        // Verify user owns the domain or is admin
        $domainQuery = \App\Models\EmailDomain::where('id', $validated['email_domain_id']);
        if (! $request->user()->isAdmin()) {
            $domainQuery->where('user_id', $request->user()->id);
        }
        $domain = $domainQuery->firstOrFail();

        // Check uniqueness within domain
        $existing = Mailbox::where('email_domain_id', $domain->id)
            ->where('address', strtolower($validated['address']))
            ->exists();

        if ($existing) {
            return $this->errorResponse('This address already exists on this domain', 422);
        }

        $mailbox = Mailbox::create([
            'user_id' => $request->user()->id,
            'email_domain_id' => $domain->id,
            'address' => strtolower($validated['address']),
            'domain_name' => $domain->name,
            'display_name' => $validated['display_name'] ?? null,
            'signature' => $validated['signature'] ?? null,
            'is_active' => true,
        ]);

        // Creating user automatically becomes owner
        MailboxUser::create([
            'mailbox_id' => $mailbox->id,
            'user_id' => $request->user()->id,
            'role' => 'owner',
        ]);

        $this->accessService->clearCache($request->user());

        $this->auditService->log('mailbox.created', $mailbox, [], [
            'address' => $mailbox->address,
            'domain' => $domain->name,
        ]);

        $mailbox->setAttribute('user_role', 'owner');

        return $this->createdResponse('Mailbox created successfully', ['mailbox' => $mailbox->load('emailDomain')]);
    }

    public function show(Request $request, Mailbox $mailbox): JsonResponse
    {
        if (! $this->accessService->hasAccess($request->user(), $mailbox->id)) {
            return $this->errorResponse('Not found', 404);
        }

        $mailbox->load('emailDomain');

        $accessible = $this->accessService->getAccessibleMailboxes($request->user());
        $mailbox->setAttribute('user_role', $accessible[$mailbox->id] ?? null);

        return response()->json(['mailbox' => $mailbox]);
    }

    public function update(Request $request, Mailbox $mailbox): JsonResponse
    {
        if (! $this->accessService->canManage($request->user(), $mailbox->id)) {
            return $this->errorResponse('Not found', 404);
        }

        $validated = $request->validate([
            'display_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'signature' => ['sometimes', 'nullable', 'string'],
            'default_signature_id' => ['sometimes', 'nullable', 'integer', 'exists:signatures,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // Verify the signature belongs to the requesting user
        if (!empty($validated['default_signature_id'])) {
            $sigOwner = \App\Models\Signature::where('id', $validated['default_signature_id'])->value('user_id');
            if ($sigOwner !== $request->user()->id) {
                return $this->errorResponse('Invalid signature', 422);
            }
        }

        $old = $mailbox->only(array_keys($validated));
        $mailbox->update($validated);

        $this->auditService->log('mailbox.updated', $mailbox, $old, $validated);

        return $this->successResponse('Mailbox updated successfully', ['mailbox' => $mailbox->fresh()->load(['emailDomain', 'defaultSignature'])]);
    }

    public function destroy(Request $request, Mailbox $mailbox): JsonResponse
    {
        if (! $this->accessService->canManage($request->user(), $mailbox->id)) {
            return $this->errorResponse('Not found', 404);
        }

        $this->auditService->log('mailbox.deleted', $mailbox, [
            'address' => $mailbox->address,
        ], []);

        $mailbox->delete();

        return $this->successResponse('Mailbox deleted successfully');
    }

    // ---- Member management endpoints ----

    public function members(Request $request, Mailbox $mailbox): JsonResponse
    {
        if (! $this->accessService->canManage($request->user(), $mailbox->id)) {
            return $this->errorResponse('Not found', 404);
        }

        $users = $mailbox->accessUsers()->with('user:id,name,email,avatar')->get()
            ->map(fn ($mu) => [
                'id' => $mu->id,
                'type' => 'user',
                'user' => $mu->user,
                'role' => $mu->role,
            ]);

        $groups = $mailbox->accessGroups()->with('group:id,name,slug')->get()
            ->map(fn ($mg) => [
                'id' => $mg->id,
                'type' => 'group',
                'group' => $mg->group,
                'role' => $mg->role,
            ]);

        return response()->json([
            'users' => $users,
            'groups' => $groups,
        ]);
    }

    public function addMember(Request $request, Mailbox $mailbox): JsonResponse
    {
        if (! $this->accessService->canManage($request->user(), $mailbox->id)) {
            return $this->errorResponse('Not found', 404);
        }

        $validated = $request->validate([
            'type' => ['required', 'in:user,group'],
            'target_id' => ['required', 'integer'],
            'role' => ['required', 'in:viewer,member,owner'],
        ]);

        if ($validated['type'] === 'user') {
            $exists = MailboxUser::where('mailbox_id', $mailbox->id)
                ->where('user_id', $validated['target_id'])
                ->exists();

            if ($exists) {
                return $this->errorResponse('User already has access to this mailbox', 422);
            }

            MailboxUser::create([
                'mailbox_id' => $mailbox->id,
                'user_id' => $validated['target_id'],
                'role' => $validated['role'],
            ]);
        } else {
            $exists = MailboxGroupAssignment::where('mailbox_id', $mailbox->id)
                ->where('group_id', $validated['target_id'])
                ->exists();

            if ($exists) {
                return $this->errorResponse('Group already has access to this mailbox', 422);
            }

            MailboxGroupAssignment::create([
                'mailbox_id' => $mailbox->id,
                'group_id' => $validated['target_id'],
                'role' => $validated['role'],
            ]);
        }

        $this->accessService->clearMailboxCache($mailbox->id);

        $this->auditService->log('mailbox.member_added', $mailbox, [], [
            'type' => $validated['type'],
            'target_id' => $validated['target_id'],
            'role' => $validated['role'],
        ]);

        return $this->createdResponse('Member added successfully');
    }

    public function updateMember(Request $request, Mailbox $mailbox, int $memberId): JsonResponse
    {
        if (! $this->accessService->canManage($request->user(), $mailbox->id)) {
            return $this->errorResponse('Not found', 404);
        }

        $validated = $request->validate([
            'type' => ['required', 'in:user,group'],
            'role' => ['required', 'in:viewer,member,owner'],
        ]);

        if ($validated['type'] === 'user') {
            $member = MailboxUser::where('id', $memberId)
                ->where('mailbox_id', $mailbox->id)
                ->firstOrFail();
        } else {
            $member = MailboxGroupAssignment::where('id', $memberId)
                ->where('mailbox_id', $mailbox->id)
                ->firstOrFail();
        }

        $member->update(['role' => $validated['role']]);

        $this->accessService->clearMailboxCache($mailbox->id);

        return $this->successResponse('Member role updated');
    }

    public function removeMember(Request $request, Mailbox $mailbox, int $memberId): JsonResponse
    {
        if (! $this->accessService->canManage($request->user(), $mailbox->id)) {
            return $this->errorResponse('Not found', 404);
        }

        $validated = $request->validate([
            'type' => ['required', 'in:user,group'],
        ]);

        if ($validated['type'] === 'user') {
            $member = MailboxUser::where('id', $memberId)
                ->where('mailbox_id', $mailbox->id)
                ->firstOrFail();
            $member->delete();
        } else {
            $member = MailboxGroupAssignment::where('id', $memberId)
                ->where('mailbox_id', $mailbox->id)
                ->firstOrFail();
            $member->delete();
        }

        $this->accessService->clearMailboxCache($mailbox->id);

        $this->auditService->log('mailbox.member_removed', $mailbox, [
            'member_id' => $memberId,
            'type' => $validated['type'],
        ], []);

        return $this->successResponse('Member removed');
    }
}
