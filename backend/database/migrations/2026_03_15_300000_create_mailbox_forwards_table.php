<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_forwards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('mailbox_id')->constrained()->onDelete('cascade');
            $table->string('forward_to');
            $table->boolean('keep_local_copy')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('mailbox_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_forwards');
    }
};
