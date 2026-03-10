<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Models\EmailProviderAccount;
use App\Services\Email\ProviderAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmailProviderAccountController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private ProviderAccountService $accountService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $accounts = EmailProviderAccount::where('user_id', $request->user()->id)
            ->withCount('domains')
            ->orderBy('provider')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn ($account) => $this->formatAccount($account));

        return response()->json(['accounts' => $accounts]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in(EmailProviderAccount::supportedProviders())],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('email_provider_accounts', 'name')->where('user_id', $request->user()->id),
            ],
            'credentials' => ['required', 'array'],
        ]);

        $account = $this->accountService->createAccount(
            $request->user(),
            $validated['provider'],
            $validated['name'],
            $validated['credentials'],
        );

        // Auto-import active domains from the provider (best-effort — don't fail account creation)
        $importResult = ['imported' => [], 'skipped' => [], 'errors' => []];
        try {
            $importResult = $this->accountService->importDomainsFromProvider($account);
        } catch (\Exception $e) {
            $importResult['errors'][] = 'Auto-import failed: ' . $e->getMessage();
        }

        $message = 'Provider account created successfully';
        $importedCount = count($importResult['imported']);
        if ($importedCount > 0) {
            $message .= ". Imported {$importedCount} domain(s) from provider.";
        }

        return $this->createdResponse($message, [
            'account' => $this->formatAccount($account->fresh()->loadCount('domains')),
            'imported_domains' => array_map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'is_verified' => $d->is_verified,
            ], $importResult['imported']),
            'skipped_domains' => $importResult['skipped'],
            'import_errors' => $importResult['errors'],
        ]);
    }

    public function show(Request $request, EmailProviderAccount $providerAccount): JsonResponse
    {
        if ($providerAccount->user_id !== $request->user()->id) {
            return $this->errorResponse('Not found', 404);
        }

        $providerAccount->loadCount('domains');

        return response()->json([
            'account' => $this->formatAccount($providerAccount),
            'credential_fields' => EmailProviderAccount::credentialFieldsFor($providerAccount->provider),
            'has_credentials' => ! empty($providerAccount->credentials),
        ]);
    }

    public function update(Request $request, EmailProviderAccount $providerAccount): JsonResponse
    {
        if ($providerAccount->user_id !== $request->user()->id) {
            return $this->errorResponse('Not found', 404);
        }

        $validated = $request->validate([
            'name' => [
                'sometimes', 'string', 'max:255',
                Rule::unique('email_provider_accounts', 'name')
                    ->where('user_id', $request->user()->id)
                    ->ignore($providerAccount->id),
            ],
            'credentials' => ['sometimes', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $account = $this->accountService->updateAccount($providerAccount, $validated);

        return $this->successResponse('Provider account updated successfully', [
            'account' => $this->formatAccount($account),
        ]);
    }

    public function destroy(Request $request, EmailProviderAccount $providerAccount): JsonResponse
    {
        if ($providerAccount->user_id !== $request->user()->id) {
            return $this->errorResponse('Not found', 404);
        }

        $this->accountService->deleteAccount($providerAccount);

        return $this->deleteResponse('Provider account deleted successfully');
    }

    public function test(Request $request, EmailProviderAccount $providerAccount): JsonResponse
    {
        if ($providerAccount->user_id !== $request->user()->id) {
            return $this->errorResponse('Not found', 404);
        }

        $result = $this->accountService->testConnection($providerAccount);

        return response()->json($result);
    }

    public function setDefault(Request $request, EmailProviderAccount $providerAccount): JsonResponse
    {
        if ($providerAccount->user_id !== $request->user()->id) {
            return $this->errorResponse('Not found', 404);
        }

        $this->accountService->setDefault($providerAccount);

        return $this->successResponse('Provider account set as default');
    }

    /**
     * List domains from the provider API, indicating which are already imported.
     */
    public function listProviderDomains(Request $request, EmailProviderAccount $providerAccount): JsonResponse
    {
        if ($providerAccount->user_id !== $request->user()->id) {
            return $this->errorResponse('Not found', 404);
        }

        try {
            $result = $this->accountService->fetchProviderDomains($providerAccount);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to fetch domains from provider: ' . $e->getMessage(), 502);
        }

        return response()->json($result);
    }

    /**
     * Import specific (or all active) domains from a provider account.
     */
    public function importDomains(Request $request, EmailProviderAccount $providerAccount): JsonResponse
    {
        if ($providerAccount->user_id !== $request->user()->id) {
            return $this->errorResponse('Not found', 404);
        }

        $validated = $request->validate([
            'domains' => ['sometimes', 'array'],
            'domains.*' => ['string', 'max:253'],
        ]);

        $domainNames = $validated['domains'] ?? null;

        try {
            $result = $this->accountService->importDomainsFromProvider($providerAccount, $domainNames);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to import domains: ' . $e->getMessage(), 502);
        }

        $importedCount = count($result['imported']);
        $message = $importedCount > 0
            ? "Imported {$importedCount} domain(s) successfully."
            : 'No new domains to import.';

        return $this->successResponse($message, [
            'imported_domains' => array_map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'is_verified' => $d->is_verified,
            ], $result['imported']),
            'skipped_domains' => $result['skipped'],
            'import_errors' => $result['errors'],
        ]);
    }

    private function formatAccount(EmailProviderAccount $account): array
    {
        return [
            'id' => $account->id,
            'user_id' => $account->user_id,
            'provider' => $account->provider,
            'name' => $account->name,
            'is_default' => $account->is_default,
            'is_active' => $account->is_active,
            'health_status' => $account->health_status,
            'last_health_check' => $account->last_health_check,
            'domains_count' => $account->domains_count ?? $account->domains()->count(),
            'created_at' => $account->created_at,
            'updated_at' => $account->updated_at,
        ];
    }
}
