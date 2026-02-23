<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('notification_type');
            $table->string('channel', 32);
            $table->string('status', 16);
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->timestamp('attempted_at');
            $table->timestamps();

            $table->index(['user_id', 'channel', 'attempted_at']);
            $table->index(['status', 'attempted_at']);
            $table->index(['notification_type', 'attempted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
