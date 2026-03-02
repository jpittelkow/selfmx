<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name', 100);
            $table->string('color', 7)->nullable(); // hex color
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        Schema::create('email_label_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_id')->constrained()->onDelete('cascade');
            $table->foreignId('email_label_id')->constrained()->onDelete('cascade');

            $table->unique(['email_id', 'email_label_id']);
            $table->index('email_label_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_label_assignments');
        Schema::dropIfExists('email_labels');
    }
};
