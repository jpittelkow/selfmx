<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_ai_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_id')->constrained()->onDelete('cascade');
            $table->foreignId('thread_id')->nullable()->constrained('email_threads')->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('type', 30); // summary, labels, priority, replies
            $table->json('result');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['thread_id', 'type', 'user_id']);
            $table->index(['email_id', 'type', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_ai_results');
    }
};
