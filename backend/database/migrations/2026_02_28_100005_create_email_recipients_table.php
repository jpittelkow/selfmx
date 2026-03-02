<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_id')->constrained()->onDelete('cascade');
            $table->string('type', 3); // to, cc, bcc
            $table->string('address');
            $table->string('name')->nullable();

            $table->index('email_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_recipients');
    }
};
