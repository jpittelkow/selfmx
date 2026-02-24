<?php

namespace App\Services\Auth;

use App\Models\User;

class PasskeyService
{
    /**
     * List user's registered passkeys with safe metadata.
     */
    public function listPasskeys(User $user): array
    {
        $credentials = $user->webauthnCredentials()->orderBy('created_at', 'desc')->get();

        return $credentials->map(fn ($cred) => [
            'id' => $cred->id,
            'alias' => $cred->alias ?? 'Passkey',
            'created_at' => $cred->created_at?->toIso8601String(),
            'updated_at' => $cred->updated_at?->toIso8601String(),
        ])->values()->all();
    }

    /**
     * Delete a passkey by credential id.
     */
    public function deletePasskey(User $user, string $credentialId): bool
    {
        $credential = $user->webauthnCredentials()->where('id', $credentialId)->first();

        if (!$credential) {
            return false;
        }

        $credential->delete();

        return true;
    }

    /**
     * Rename a passkey.
     */
    public function renamePasskey(User $user, string $credentialId, string $name): bool
    {
        $credential = $user->webauthnCredentials()->where('id', $credentialId)->first();

        if (!$credential) {
            return false;
        }

        $credential->forceFill(['alias' => $name])->save();

        return true;
    }
}
