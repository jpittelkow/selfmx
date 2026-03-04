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
        $query = EmailDomain::where('user_id', $request->user()->id)
            ->with('catchallMailbox');

        if ($search = $request->query('search')) {
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
            $query->where('name', 'like', "%{$escaped}%");
        }

        if ($request->has('verified')) {
            $query->where('is_verified', $request->boolean('verified'));
        }

        if ($provider = $request->query('provider')) {
            $query->where('provider', $provider);
        }

        $domains = $query->orderByDesc('created_at')->get();

        return response()->json(['domains' => $domains]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('email_domains', 'name')->where('user_id', $request->user()->id)],
            'provider' => ['sometimes', 'string', 'in:mailgun,ses,sendgrid,postmark'],
            'provider_config' => ['sometimes', 'array'],
        ]);

        $result = $this->domainService->createDomain(
            $request->user(),
            $validated['name'],
            $validated['provider'] ?? 'mailgun',
            $validated['provider_config'] ?? [],
        );

        $data = ['domain' => $result['domain']];
        if (! empty($result['warnings'])) {
            $data['warnings'] = $result['warnings'];
        }

        return $this->createdResponse('Domain created successfully', $data);
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
            'catchall_mailbox_id' => ['sometimes', 'nullable', Rule::exists('mailboxes', 'id')->where('email_domain_id', $emailDomain->id)],
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
