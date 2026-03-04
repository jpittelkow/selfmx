<?php

namespace App\Services;

use App\Models\User;

class UserService
{
    public function __construct(
        private AuditService $auditService,
    ) {}

    /**
     * Delete a user and all associated data.
     *
     * Database cascade constraints handle most related records automatically.
     * Explicit deletes here ensure cleanup of any records without FK cascades
     * (e.g., Sanctum tokens, Laravel sessions) and provide a single
     * authoritative deletion path for both self-deletion and admin deletion.
     */
    public function deleteUser(User $user): void
    {
        // Revoke all Sanctum API tokens (personal_access_tokens — may not have FK cascade)
        $user->tokens()->delete();

        // Explicit cleanup for relations that ProfileController previously handled
        $user->socialAccounts()->delete();
        $user->settings()->delete();
        $user->notifications()->delete();
        $user->aiProviders()->delete();
        $user->apiTokens()->delete();
        $user->pushSubscriptions()->delete();

        $this->auditService->log('user.deleted', $user, [
            'name' => $user->name,
            'email' => $user->email,
        ], []);

        // Delete the user (remaining FKs cascade at DB level)
        $user->delete();
    }
}
