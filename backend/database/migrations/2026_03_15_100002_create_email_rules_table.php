<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->string('match_mode', 3)->default('all'); // 'all' (AND) or 'any' (OR)
            $table->json('conditions');
            $table->json('actions');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('stop_processing')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_rules');
    }
};
