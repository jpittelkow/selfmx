<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_id')->constrained()->onDelete('cascade');
            $table->string('filename');
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('size');
            $table->string('storage_path', 500);
            $table->string('content_id')->nullable(); // CID for inline images
            $table->boolean('is_inline')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index('email_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_attachments');
    }
};
