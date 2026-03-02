<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50);
            $table->string('provider_event_id')->unique();
            $table->string('event_type', 50);
            $table->json('payload');
            $table->string('status', 20)->default('processed'); // processed, failed, duplicate
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['provider', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_webhook_logs');
    }
};
