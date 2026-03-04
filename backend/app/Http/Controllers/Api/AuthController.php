<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Enums\Permission;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Auth\AuthService;
use App\Services\EmailConfigService;
use App\Services\GroupService;
use App\Services\NovuService;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private AuditService $auditService,
        private AuthService $authService,
        private NovuService $novuService,
        private SettingService $settingService
    ) {}

    /**
     * Check if an email is available for registration.
     * Always returns available to prevent email enumeration.
     * Real uniqueness validation happens on the register endpoint.
     */
    public function checkEmail(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        return $this->dataResponse(['available' => true]);
    }

    /**
     * Register a new user.
     */
    public function register(Request $request, EmailConfigService $emailConfigService): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        // Check uniqueness separately to return a generic error (prevent email enumeration).
        // The unique check above was removed so Laravel doesn't return "email already taken".
        if (User::where('email', $validated['email'])->exists()) {
            Log::info('Registration attempted with existing email', ['email' => $validated['email']]);
            return $this->errorResponse('Registration could not be completed. Please try again or contact support.', 422);
        }

        $groupService = app(GroupService::class);

        // Wrap in transaction to prevent race condition where multiple users
        // could register simultaneously and all see count === 0
        $user = DB::transaction(function () use ($validated, $groupService) {
            $isFirstUser = User::lockForUpdate()->count() === 0;
            if ($isFirstUser) {
                $groupService->ensureDefaultGroupsExist();
            }

            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ]);

            if ($isFirstUser) {
                $user->assignGroup('admin');
            } else {
                $groupService->assignDefaultGroupToUser($user);
            }

            return $user;
        });

        if ($emailConfigService->isConfigured()) {
            event(new Registered($user));
        } else {
            $user->markEmailAsVerified();
        }

        Auth::login($user);
        
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $this->auditService->logAuth('register', $user);

        $this->novuService->syncSubscriber($user);

        return $this->createdResponse('Registration successful', ['user' => $user]);
    }

    /**
     * Login user.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $result = $this->authService->attemptLogin(
            $credentials['email'],
            $credentials['password'],
            $request->boolean('remember'),
        );

        if (!$result['authenticated'] && !isset($result['user'])) {
            $this->auditService->logAuth('login_failed', null, ['email' => $credentials['email']], 'warning');
            return $this->errorResponse('Invalid credentials', 401);
        }

        if ($result['disabled'] ?? false) {
            $this->auditService->logAuth('login_failed', $result['user'], ['reason' => 'account_disabled'], 'warning');

            try {
                Auth::guard('web')->logout();
                if ($request->hasSession()) {
                    $request->session()->invalidate();
                }
            } catch (\Exception $e) {
                // Session not available in test environment
            }

            return $this->errorResponse('This account has been disabled. Please contact your administrator.', 403);
        }

        if ($result['requires_2fa'] ?? false) {
            if ($request->hasSession()) {
                $request->session()->put('2fa:user_id', $result['user']->id);
            }

            try {
                Auth::guard('web')->logout();
            } catch (\Exception $e) {
                // Session not available in test environment
            }

            return $this->successResponse('Two-factor authentication required', ['requires_2fa' => true]);
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $this->auditService->logAuth('login', $result['user']);
        $this->novuService->syncSubscriber($result['user']);

        return $this->successResponse('Login successful', ['user' => $result['user']]);
    }

    /**
     * Logout user.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($user) {
            $this->auditService->logAuth('logout', $user);
        }

        return $this->successResponse('Logged out successfully');
    }

    /**
     * Get authenticated user.
     */
    public function user(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load(['socialAccounts:id,user_id,provider,nickname,avatar', 'groups:id,name,slug', 'groups.permissions']);

        $permissions = $user->inGroup('admin')
            ? Permission::all()
            : $user->groups->flatMap(fn ($g) => $g->permissions->pluck('permission'))->unique()->values()->all();

        return $this->dataResponse([
            'user' => $user,
            'sso_accounts' => $user->socialAccounts->pluck('provider'),
            'groups' => $user->groups->pluck('slug'),
            'permissions' => $permissions,
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            'timezone' => $user->getTimezone(),
        ]);
    }

    /**
     * Request password reset link.
     *
     * Note: Always returns success to prevent user enumeration when email is configured.
     * The actual email is only sent if the user exists.
     */
    public function forgotPassword(Request $request, EmailConfigService $emailConfigService): JsonResponse
    {
        if (!$emailConfigService->isConfigured()) {
            return $this->errorResponse(
                'Password reset is not available. Please contact your administrator.',
                503
            );
        }

        if (!$this->settingService->get('auth', 'password_reset_enabled', true)) {
            return $this->errorResponse(
                'Password reset is disabled. Please contact your administrator.',
                503
            );
        }

        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // Attempt to send the reset link, but don't reveal whether the user exists
        Password::sendResetLink($request->only('email'));

        Log::info('Password reset link requested', ['email' => $request->email]);
        $this->auditService->logAuth('password_reset_requested', null, ['email' => $request->email]);

        // Always return success message to prevent user enumeration
        return $this->successResponse('If an account exists with this email, a password reset link has been sent.');
    }

    /**
     * Reset password.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));

                app(AuditService::class)->logAuth('password_reset', $user);
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return $this->successResponse('Password reset successful');
        }

        Log::warning('Password reset failed', ['email' => $request->email, 'status' => $status]);

        return response()->json([
            'message' => 'Password reset failed',
            'error' => __($status),
        ], 400);
    }

    /**
     * Verify email address.
     */
    public function verifyEmail(Request $request): JsonResponse
    {
        $request->validate([
            'id' => ['required'],
            'hash' => ['required'],
        ]);

        $user = User::find($request->id);

        if (!$user || !hash_equals(hash_hmac('sha256', $user->getEmailForVerification(), config('app.key')), $request->hash)) {
            return $this->errorResponse('Invalid verification link', 400);
        }

        if ($user->hasVerifiedEmail()) {
            return $this->successResponse('Email already verified');
        }

        $user->markEmailAsVerified();
        event(new Verified($user));

        return $this->successResponse('Email verified successfully');
    }

    /**
     * Resend verification email.
     */
    public function resendVerification(Request $request, EmailConfigService $emailConfigService): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return $this->successResponse('Email already verified');
        }

        if (!$emailConfigService->isConfigured()) {
            return $this->errorResponse(
                'Email verification is not available. Please contact your administrator.',
                503
            );
        }

        $user->sendEmailVerificationNotification();

        return $this->successResponse('Verification link sent');
    }
}
