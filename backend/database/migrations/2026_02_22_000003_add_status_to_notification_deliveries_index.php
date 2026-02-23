<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'channel', 'attempted_at']);
            $table->index(['user_id', 'channel', 'status', 'attempted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'channel', 'status', 'attempted_at']);
            $table->index(['user_id', 'channel', 'attempted_at']);
        });
    }
};
