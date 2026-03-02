<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('email_address');
            $table->string('display_name')->nullable();
            $table->string('avatar_url')->nullable();
            $table->text('notes')->nullable();
            $table->integer('email_count')->default(0);
            $table->timestamp('last_emailed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'email_address']);
            $table->index(['user_id', 'display_name']);
            $table->index(['user_id', 'email_count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
