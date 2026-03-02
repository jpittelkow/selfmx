<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spam_filter_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('type', 10); // 'allow' or 'block'
            $table->string('match_type', 10)->default('exact'); // 'exact' or 'domain'
            $table->string('value', 320); // email address or domain
            $table->timestamps();

            $table->unique(['user_id', 'type', 'value']);
            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spam_filter_lists');
    }
};
