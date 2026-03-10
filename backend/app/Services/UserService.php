<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class UserService
{
    public function __construct(
        private AuditService $auditService,
    ) {}

    /**
     * Delete a user and all related data.
     *
     * Centralizes deletion logic so both self-deletion (ProfileController)
     * and admin deletion (UserController) use the same cascade.
     * WebAuthn credentials are flushed via the User model's `deleting` event.
     */
    public function deleteUser(User $user, ?int $performedByUserId = null): void
    {
        $this->auditService->log('user.deleted', $user, [
            'name' => $user->name,
            'email' => $user->email,
        ], [], $performedByUserId);

        // Clean up avatar file from disk
        if ($user->avatar && str_starts_with($user->avatar, '/storage/avatars/')) {
            Storage::disk('public')->delete('avatars/' . basename($user->avatar));
        }

        // Delete related data (order: leaf relations first)
        $user->socialAccounts()->delete();
        Setting::where('user_id', $user->id)->delete();
        $user->notifications()->delete();
        $user->aiProviders()->delete();
        $user->pushSubscriptions()->delete();
        $user->apiTokens()->delete();
        if (Schema::hasTable('user_onboardings')) {
            $user->onboarding()->delete();
        }

        $user->delete();
    }
}
