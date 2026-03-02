<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->timestamp('send_at')->nullable()->after('sent_at');
            $table->index(['send_at', 'is_draft']);
        });

        Schema::table('email_user_states', function (Blueprint $table) {
            $table->timestamp('snoozed_until')->nullable()->after('is_starred');
            $table->index(['user_id', 'snoozed_until']);
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropIndex(['send_at', 'is_draft']);
            $table->dropColumn('send_at');
        });

        Schema::table('email_user_states', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'snoozed_until']);
            $table->dropColumn('snoozed_until');
        });
    }
};
