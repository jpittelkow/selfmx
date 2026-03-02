<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('mailbox_id')->constrained()->onDelete('cascade');
            $table->string('message_id', 995)->index();
            $table->foreignId('thread_id')->nullable()->constrained('email_threads')->onDelete('set null');
            $table->string('provider_message_id')->nullable();
            $table->string('direction', 10); // inbound, outbound
            $table->string('from_address');
            $table->string('from_name')->nullable();
            $table->string('subject', 998)->nullable();
            $table->mediumText('body_text')->nullable();
            $table->mediumText('body_html')->nullable();
            $table->json('headers')->nullable();
            $table->string('in_reply_to', 995)->nullable()->index();
            $table->text('references')->nullable();
            $table->boolean('is_read')->default(false);
            $table->boolean('is_starred')->default(false);
            $table->boolean('is_draft')->default(false);
            $table->boolean('is_spam')->default(false);
            $table->boolean('is_trashed')->default(false);
            $table->float('spam_score')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_read']);
            $table->index(['user_id', 'is_starred']);
            $table->index(['user_id', 'is_spam']);
            $table->index(['user_id', 'is_trashed']);
            $table->index(['mailbox_id', 'direction']);
            $table->index('thread_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emails');
    }
};
