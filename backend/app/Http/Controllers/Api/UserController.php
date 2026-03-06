<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\AdminAuthorizationTrait;
use App\Http\Traits\ApiResponseTrait;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\AuditService;
use App\Services\EmailConfigService;
use App\Services\GroupService;
use App\Services\NovuService;
use App\Services\PermissionService;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    use AdminAuthorizationTrait;
    use ApiResponseTrait;

    public function __construct(
        private AuditService $auditService,
        private NovuService $novuService,
        private PermissionService $permissionService
    ) {}

    /**
     * List all users with pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', config('app.pagination.default'));
        $search = $request->input('search');
        $groupSlug = $request->input('group');

        $query = User::query()->with('groups:id,name,slug');

        if ($search) {
            $escaped = \App\Support\Str::escapeLike($search);
            $query->where(function ($q) use ($escaped) {
                $q->where('name', 'like', "%{$escaped}%")
                  ->orWhere('email', 'like', "%{$escaped}%");
            });
        }

        if ($groupSlug) {
            $query->whereHas('groups', function ($q) use ($groupSlug) {
                $q->where('slug', $groupSlug);
            });
        }

        $users = $query->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return $this->dataResponse($users);
    }

    /**
     * Get a specific user.
     */
    public function show(User $user): JsonResponse
    {
        $user->load('groups:id,name,slug');

        return $this->dataResponse([
            'user' => $user->makeHidden(['password', 'two_factor_secret', 'two_factor_recovery_codes']),
        ]);
    }

    /**
     * Create a new user.
     */
    public function store(Request $request, EmailConfigService $emailConfigService, GroupService $groupService): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', Password::defaults()],
            'admin' => ['sometimes', 'boolean'],
            'skip_verification' => ['sometimes', 'boolean'],
        ]);

        $groupService->ensureDefaultGroupsExist();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        if ($validated['admin'] ?? false) {
            $user->assignGroup('admin');
        } else {
            $groupService->assignDefaultGroupToUser($user);
        }

        $skipVerification = $validated['skip_verification'] ?? false;
        if ($emailConfigService->isConfigured() && !$skipVerification) {
            $user->sendEmailVerificationNotification();
        } elseif ($skipVerification) {
            $user->markEmailAsVerified();
        }

        $user->load('groups:id,name,slug');

        return $this->createdResponse('User created successfully', [
            'user' => $user->makeHidden(['password', 'two_factor_secret', 'two_factor_recovery_codes']),
        ]);
    }

    /**
     * Update a user (name, email, password). Admin role is changed via updateGroups().
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'password' => ['sometimes', 'string', Password::defaults()],
        ]);

        $oldValues = $user->only(array_keys($validated));
        $user->update($validated);
        $newValues = $user->fresh()->only(array_keys($validated));

        $this->auditService->logModelChange($user, 'user.updated', $oldValues, $newValues);

        $this->novuService->syncSubscriber($user->fresh());

        $user->load('groups:id,name,slug');

        return $this->successResponse('User updated successfully', [
            'user' => $user->makeHidden(['password', 'two_factor_secret', 'two_factor_recovery_codes'])->fresh(),
        ]);
    }

    /**
     * Delete a user.
     */
    public function destroy(User $user, UserService $userService): JsonResponse
    {
        if ($error = $this->ensureNotLastAdmin($user, 'delete')) {
            return $error;
        }

        if ($user->id === auth()->id()) {
            return $this->errorResponse('Cannot delete your own account', 400);
        }

        $userService->deleteUser($user);

        return $this->successResponse('User deleted successfully');
    }

    /**
     * Toggle admin group membership (add or remove from admin group).
     */
    public function toggleAdmin(User $user): JsonResponse
    {
        if ($error = $this->ensureNotLastAdmin($user, 'remove admin status from')) {
            return $error;
        }

        if ($user->id === auth()->id()) {
            return $this->errorResponse('Cannot remove admin status from your own account', 400);
        }

        $wasInAdminGroup = $user->inGroup('admin');
        $adminGroup = UserGroup::where('slug', 'admin')->first();

        if (!$adminGroup) {
            return $this->errorResponse('Admin group not found', 500);
        }

        if ($wasInAdminGroup) {
            $user->removeFromGroup('admin');
            $this->auditService->log('user.admin_revoked', $user, ['group' => 'admin'], []);
        } else {
            $user->assignGroup('admin');
            $this->auditService->log('user.admin_granted', $user, [], ['group' => 'admin']);
        }

        $this->permissionService->clearUserCache($user);
        $user->load('groups:id,name,slug');

        return $this->successResponse('Admin status updated successfully', [
            'user' => $user->makeHidden(['password', 'two_factor_secret', 'two_factor_recovery_codes'])->fresh(),
        ]);
    }

    /**
     * Reset user password.
     */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', Password::defaults()],
        ]);

        $user->update([
            'password' => $validated['password'],
        ]);

        return $this->successResponse('Password reset successfully');
    }

    /**
     * Toggle user disabled status (soft delete alternative).
     */
    public function toggleDisabled(User $user): JsonResponse
    {
        if ($error = $this->ensureNotLastAdmin($user, 'disable')) {
            return $error;
        }

        if ($user->id === auth()->id()) {
            return $this->errorResponse('Cannot disable your own account', 400);
        }

        $enabling = (bool) $user->disabled_at;
        $oldDisabledAt = $user->disabled_at?->toISOString();
        $user->forceFill([
            'disabled_at' => $enabling ? null : now(),
        ])->save();
        $user->refresh();

        $this->auditService->log(
            $enabling ? 'user.enabled' : 'user.disabled',
            $user,
            ['disabled_at' => $oldDisabledAt],
            ['disabled_at' => $user->disabled_at?->toISOString()]
        );

        $message = $enabling ? 'User enabled successfully' : 'User disabled successfully';

        return $this->successResponse($message, [
            'user' => $user->makeHidden(['password', 'two_factor_secret', 'two_factor_recovery_codes'])->fresh(),
        ]);
    }

    /**
     * Resend verification email for a user (admin only).
     * Rate limited to 1 per 5 minutes per user.
     */
    public function resendVerification(User $user): JsonResponse
    {
        if ($user->hasVerifiedEmail()) {
            return $this->errorResponse('User is already verified', 400);
        }

        $key = 'resend-verification:' . $user->id;
        if (RateLimiter::tooManyAttempts($key, 1)) {
            $retryAfter = RateLimiter::availableIn($key);
            return $this->errorResponse(
                'Please wait before resending. You can resend again in ' . $retryAfter . ' seconds.',
                429
            );
        }

        RateLimiter::hit($key, 300); // 5 minutes

        $user->sendEmailVerificationNotification();

        return $this->successResponse('Verification email sent successfully');
    }

    /**
     * Update a user's group memberships.
     */
    public function updateGroups(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'group_ids' => ['required', 'array'],
            'group_ids.*' => ['integer', 'exists:user_groups,id'],
        ]);

        $adminGroup = UserGroup::where('slug', 'admin')->first();
        if ($adminGroup && $user->inGroup('admin') && ! in_array($adminGroup->id, $validated['group_ids'], true)) {
            if ($error = $this->ensureNotLastAdmin($user, 'remove from admin group')) {
                return $error;
            }
        }

        $oldGroupIds = $user->groups()->pluck('user_groups.id')->all();
        $user->groups()->sync($validated['group_ids']);
        $user->load('groups:id,name,slug');

        $this->auditService->log(
            'user.groups_updated',
            $user,
            ['group_ids' => $oldGroupIds],
            ['group_ids' => $validated['group_ids']]
        );

        $this->permissionService->clearUserCache($user);

        return $this->successResponse('Groups updated successfully', [
            'user' => $user->makeHidden(['password', 'two_factor_secret', 'two_factor_recovery_codes']),
        ]);
    }
}
