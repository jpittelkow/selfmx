<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            // Add composite unique index to prevent duplicate emails per mailbox.
            // The standalone message_id index is kept since it's used for thread resolution
            // lookups that don't scope by mailbox_id.
            $table->unique(['mailbox_id', 'message_id'], 'emails_mailbox_message_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropUnique('emails_mailbox_message_id_unique');
        });
    }
};
