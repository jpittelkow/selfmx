<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Models\EmailDomain;
use App\Services\AuditService;
use App\Services\Email\DomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmailDomainController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private DomainService $domainService,
        private AuditService $auditService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $domains = EmailDomain::where('user_id', $request->user()->id)
            ->with('catchallMailbox')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['domains' => $domains]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:email_domains,name'],
            'provider' => ['sometimes', 'string', 'in:mailgun,ses,sendgrid,postmark'],
            'provider_config' => ['sometimes', 'array'],
        ]);

        $domain = $this->domainService->createDomain(
            $request->user(),
            $validated['name'],
            $validated['provider'] ?? 'mailgun',
            $validated['provider_config'] ?? [],
        );

        return $this->createdResponse('Domain created successfully', ['domain' => $domain]);
    }

    public function show(Request $request, EmailDomain $emailDomain): JsonResponse
    {
        if ($emailDomain->user_id !== $request->user()->id) {
            return $this->errorResponse('Not found', 404);
        }

        $emailDomain->load(['mailboxes', 'catchallMailbox']);

        return response()->json(['domain' => $emailDomain]);
    }

    public function update(Request $request, EmailDomain $emailDomain): JsonResponse
    {
        if ($emailDomain->user_id !== $request->user()->id) {
            return $this->errorResponse('Not found', 404);
        }

        $validated = $request->validate([
            'catchall_mailbox_id' => ['sometimes', 'nullable', 'exists:mailboxes,id'],
            'is_active' => ['sometimes', 'boolean'],
            'provider_config' => ['sometimes', 'array'],
        ]);

        $old = $emailDomain->only(array_keys($validated));
        $emailDomain->update($validated);

        $this->auditService->log('email_domain.updated', $emailDomain, $old, $validated);

        return $this->successResponse('Domain updated successfully', ['domain' => $emailDomain->fresh()]);
    }

    public function destroy(Request $request, EmailDomain $emailDomain): JsonResponse
    {
        if ($emailDomain->user_id !== $request->user()->id) {
            return $this->errorResponse('Not found', 404);
        }

        $this->domainService->deleteDomain($emailDomain);

        return $this->successResponse('Domain deleted successfully');
    }

    public function verify(Request $request, EmailDomain $emailDomain): JsonResponse
    {
        if ($emailDomain->user_id !== $request->user()->id) {
            return $this->errorResponse('Not found', 404);
        }

        $result = $this->domainService->verifyDomain($emailDomain);

        return response()->json([
            'is_verified' => $result->isVerified,
            'dns_records' => $result->dnsRecords,
            'error' => $result->error,
        ]);
    }
}
