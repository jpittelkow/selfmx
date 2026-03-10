<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\AdminAuthorizationTrait;
use App\Http\Traits\ApiResponseTrait;
use App\Services\AuditService;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    use AdminAuthorizationTrait;
    use ApiResponseTrait;

    public function __construct(
        private UserService $userService,
        private AuditService $auditService,
    ) {}

    /**
     * Get user profile.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load(['socialAccounts:id,user_id,provider,nickname,avatar']);

        return $this->dataResponse(['user' => $user]);
    }

    /**
     * Update user profile.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'avatar' => ['sometimes', 'nullable', 'url', 'max:500'],
        ]);

        // Check if email is being changed
        $emailChanged = isset($validated['email']) && $validated['email'] !== $user->email;

        // Capture original values before mutation for audit logging
        $original = $user->only(array_keys($validated));

        if ($emailChanged) {
            $validated['email_verified_at'] = null;
        }

        $user->update($validated);

        $this->auditService->logModelChange($user, 'profile.updated', $original, $user->only(array_keys($validated)));

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return $this->successResponse('Profile updated successfully', [
            'user' => $user,
            'email_verification_sent' => $emailChanged,
        ]);
    }

    /**
     * Update user password.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $request->user()->update([
            'password' => $validated['password'],
        ]);

        $this->auditService->log('profile.password_changed', $request->user());

        return $this->successResponse('Password updated successfully');
    }

    /**
     * Upload user avatar.
     */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'max:2048'],
        ]);

        $user = $request->user();

        try {
            // Delete old avatar file if it's a local upload
            if ($user->avatar && str_starts_with($user->avatar, '/storage/avatars/')) {
                Storage::disk('public')->delete('avatars/' . basename($user->avatar));
            }

            $extension = $request->file('avatar')->getClientOriginalExtension();
            $filename = $user->id . '_' . Str::random(16) . '.' . $extension;
            $path = $request->file('avatar')->storeAs('avatars', $filename, 'public');
            $url = '/storage/' . $path;

            $user->update(['avatar' => $url]);

            $this->auditService->log('profile.avatar_uploaded', $user);

            return $this->successResponse('Avatar uploaded successfully', [
                'avatar_url' => $url,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to upload avatar: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete user avatar.
     */
    public function deleteAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->avatar) {
            return $this->errorResponse('No avatar to delete', 404);
        }

        try {
            // Only delete from disk if it's a locally uploaded file
            if (str_starts_with($user->avatar, '/storage/avatars/')) {
                Storage::disk('public')->delete('avatars/' . basename($user->avatar));
            }

            $user->update(['avatar' => null]);

            $this->auditService->log('profile.avatar_deleted', $user);

            return $this->successResponse('Avatar deleted successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to delete avatar: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete user account.
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        if ($error = $this->ensureNotLastAdmin($user, 'delete')) {
            return $error;
        }

        $this->auditService->log('profile.deleted', $user);

        $this->userService->deleteUser($user, $user->id);

        return $this->successResponse('Account deleted successfully');
    }
}
