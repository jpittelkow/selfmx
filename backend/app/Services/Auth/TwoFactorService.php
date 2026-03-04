<?php

namespace App\Services\Auth;

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;

class TwoFactorService
{
    private Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
    }

    /**
     * Generate a new 2FA secret and QR code.
     */
    public function generateSecret(User $user): array
    {
        $secret = $this->google2fa->generateSecretKey();

        // Store the secret (unconfirmed) — forceFill since 2FA fields are guarded
        $user->forceFill([
            'two_factor_secret' => encrypt($secret),
            'two_factor_enabled' => false,
            'two_factor_confirmed_at' => null,
        ])->save();

        // Generate QR code
        $qrCodeUrl = $this->google2fa->getQRCodeUrl(
            config('app.name'),
            $user->email,
            $secret
        );

        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd()
        );
        $writer = new Writer($renderer);
        $qrCode = $writer->writeString($qrCodeUrl);
        $qrCodeBase64 = 'data:image/svg+xml;base64,' . base64_encode($qrCode);

        return [
            'secret' => $secret,
            'qr_code' => $qrCodeBase64,
        ];
    }

    /**
     * Verify a 2FA code.
     */
    public function verifyCode(User $user, string $code): bool
    {
        if (!$user->two_factor_secret) {
            return false;
        }

        $secret = decrypt($user->two_factor_secret);

        return $this->google2fa->verifyKey($secret, $code);
    }

    /**
     * Confirm 2FA setup and generate recovery codes.
     */
    public function confirmSetup(User $user): array
    {
        $recoveryCodes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $recoveryCodes,
        ])->save();

        return $recoveryCodes;
    }

    /**
     * Disable 2FA.
     */
    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();
    }

    /**
     * Verify a recovery code.
     */
    public function verifyRecoveryCode(User $user, string $code): bool
    {
        $recoveryCodes = $user->two_factor_recovery_codes ?? [];

        if (!in_array($code, $recoveryCodes)) {
            return false;
        }

        // Remove used recovery code
        $recoveryCodes = array_values(array_filter(
            $recoveryCodes,
            fn ($c) => $c !== $code
        ));

        $user->forceFill([
            'two_factor_recovery_codes' => $recoveryCodes,
        ])->save();

        return true;
    }

    /**
     * Verify a 2FA code (TOTP or recovery) during login.
     *
     * @throws \RuntimeException with user-facing message on validation failure
     */
    public function completePendingVerification(User $user, string $code, bool $isRecoveryCode): void
    {
        if ($isRecoveryCode) {
            if (!preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/i', $code)) {
                throw new \RuntimeException('Invalid recovery code format');
            }
            if (!$this->verifyRecoveryCode($user, strtoupper($code))) {
                throw new \RuntimeException('Invalid recovery code');
            }
        } else {
            if (!preg_match('/^\d{6}$/', $code)) {
                throw new \RuntimeException('Verification code must be 6 digits');
            }
            if (!$this->verifyCode($user, $code)) {
                throw new \RuntimeException('Invalid verification code');
            }
        }
    }

    /**
     * Regenerate recovery codes.
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $recoveryCodes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => $recoveryCodes,
        ])->save();

        return $recoveryCodes;
    }

    /**
     * Generate a set of recovery codes.
     */
    private function generateRecoveryCodes(int $count = 10): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = Str::upper(Str::random(4) . '-' . Str::random(4));
        }
        return $codes;
    }
}
