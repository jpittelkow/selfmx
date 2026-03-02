<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('email_domain_id')->constrained()->onDelete('cascade');
            $table->string('address'); // local part before @, or '*' for catchall
            $table->string('display_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('signature')->nullable();
            $table->timestamps();

            $table->unique(['email_domain_id', 'address']);
            $table->index('user_id');
        });

        // Now add the FK constraint for catchall_mailbox_id
        Schema::table('email_domains', function (Blueprint $table) {
            $table->foreign('catchall_mailbox_id')
                ->references('id')
                ->on('mailboxes')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('email_domains', function (Blueprint $table) {
            $table->dropForeign(['catchall_mailbox_id']);
        });
        Schema::dropIfExists('mailboxes');
    }
};
