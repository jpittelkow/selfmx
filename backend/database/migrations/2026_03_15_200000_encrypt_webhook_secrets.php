<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen the secret column to accommodate encrypted values,
     * then encrypt any existing plaintext secrets.
     */
    public function up(): void
    {
        Schema::table('webhooks', function (Blueprint $table) {
            $table->text('secret')->nullable()->change();
        });

        // Encrypt existing plaintext secrets using raw DB queries
        // to bypass the Eloquent encrypted cast.
        $webhooks = DB::table('webhooks')
            ->whereNotNull('secret')
            ->where('secret', '!=', '')
            ->get(['id', 'secret']);

        foreach ($webhooks as $webhook) {
            // Skip values that are already encrypted — attempt to decrypt
            // and if it succeeds, the value is already encrypted.
            try {
                Crypt::decryptString($webhook->secret);
                continue; // Already encrypted, skip
            } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                // Not encrypted yet — proceed to encrypt
            }

            DB::table('webhooks')
                ->where('id', $webhook->id)
                ->update([
                    'secret' => Crypt::encryptString($webhook->secret),
                ]);
        }
    }

    /**
     * Reverse is not possible — encrypted secrets cannot be restored to plaintext.
     */
    public function down(): void
    {
        throw new \RuntimeException(
            'This migration cannot be reversed — encrypted secrets cannot be restored to their original plaintext values.'
        );
    }
};
