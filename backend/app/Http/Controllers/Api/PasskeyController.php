<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Auth\PasskeyService;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laragear\WebAuthn\Http\Requests\AssertedRequest;
use Laragear\WebAuthn\Http\Requests\AssertionRequest;
use Laragear\WebAuthn\Http\Requests\AttestationRequest;
use Laragear\WebAuthn\Http\Requests\AttestedRequest;

class PasskeyController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private PasskeyService $passkeyService,
        private AuditService $auditService,
        private SettingService $settingService
    ) {}

    /**
     * List authenticated user's passkeys.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $passkeys = $this->passkeyService->listPasskeys($user);

        return $this->dataResponse(['passkeys' => $passkeys]);
    }

    /**
     * Get registration (attestation) options for adding a new passkey.
     */
    public function registerOptions(AttestationRequest $request)
    {
        return $request->toCreate();
    }

    /**
     * Complete passkey registration.
     */
    public function register(AttestedRequest $request): JsonResponse
    {
        $name = $request->input('name', 'Passkey');

        try {
            $request->save(['alias' => $name]);
            $this->auditService->logAuth('passkey_registered', $request->user(), ['alias' => $name]);

            return $this->successResponse('Passkey registered successfully');
        } catch (\Throwable $e) {
            Log::warning('Passkey registration failed', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);
            $this->auditService->logAuth('passkey_registration_failed', $request->user(), ['error' => $e->getMessage()], 'warning');

            return $this->errorResponse('Passkey registration failed. Please try again.', 400);
        }
    }

    /**
     * Rename a passkey.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();

        if (!$this->passkeyService->renamePasskey($user, $id, $request->input('name'))) {
            return $this->errorResponse('Passkey not found', 404);
        }

        return $this->successResponse('Passkey updated');
    }

    /**
     * Delete a passkey.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$this->passkeyService->deletePasskey($user, $id)) {
            return $this->errorResponse('Passkey not found', 404);
        }

        $this->auditService->logAuth('passkey_deleted', $user, ['credential_id' => $id]);

        return $this->successResponse('Passkey removed');
    }

    /**
     * Get login (assertion) options for passkey authentication.
     * Optional email narrows to that user's credentials (non-discoverable flow).
     */
    public function loginOptions(AssertionRequest $request)
    {
        if ($this->settingService->get('auth', 'passkey_mode', 'disabled') === 'disabled') {
            return $this->errorResponse('Passkey sign-in is not enabled', 403);
        }

        $email = $request->input('email');

        return $request->toVerify($email ? ['email' => $email] : null);
    }

    /**
     * Authenticate with passkey and establish session.
     */
    public function login(AssertedRequest $request): JsonResponse
    {
        if ($this->settingService->get('auth', 'passkey_mode', 'disabled') === 'disabled') {
            return $this->errorResponse('Passkey sign-in is not enabled', 403);
        }

        try {
            $user = $request->login();
        } catch (\Throwable $e) {
            Log::warning('Passkey login verification failed', ['error' => $e->getMessage()]);
            $this->auditService->logAuth('passkey_login_failed', null, ['error' => $e->getMessage()], 'warning');

            return $this->errorResponse('Passkey verification failed', 401);
        }

        if (!$user) {
            $this->auditService->logAuth('passkey_login_failed', null, [], 'warning');

            return $this->errorResponse('Invalid passkey', 401);
        }

        if ($user->isDisabled()) {
            Auth::logout();
            $request->session()->invalidate();
            $this->auditService->logAuth('login_failed', $user, ['reason' => 'account_disabled'], 'warning');

            return $this->errorResponse('This account has been disabled.', 403);
        }

        $this->auditService->logAuth('passkey_login', $user);

        return $this->successResponse('Login successful', ['user' => $user]);
    }
}
