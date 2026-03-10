<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen endpoint, p256dh, and auth columns to accommodate encrypted values,
     * then encrypt any existing plaintext data.
     */
    public function up(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->text('endpoint')->change();
            $table->text('p256dh')->change();
            $table->text('auth')->change();
        });

        $subscriptions = DB::table('push_subscriptions')
            ->get(['id', 'endpoint', 'p256dh', 'auth']);

        foreach ($subscriptions as $sub) {
            $updates = [];

            foreach (['endpoint', 'p256dh', 'auth'] as $field) {
                if (empty($sub->$field)) {
                    continue;
                }

                // Skip values that are already encrypted
                try {
                    Crypt::decryptString($sub->$field);
                    continue;
                } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                    // Not encrypted yet — proceed
                }

                $updates[$field] = Crypt::encryptString($sub->$field);
            }

            if (!empty($updates)) {
                DB::table('push_subscriptions')
                    ->where('id', $sub->id)
                    ->update($updates);
            }
        }
    }

    /**
     * Reverse is not possible — encrypted values cannot be restored to plaintext.
     */
    public function down(): void
    {
        throw new \RuntimeException(
            'This migration cannot be reversed — encrypted push subscription data cannot be restored to plaintext.'
        );
    }
};
