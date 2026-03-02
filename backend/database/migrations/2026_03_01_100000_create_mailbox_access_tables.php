<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('role', 20); // viewer, member, owner
            $table->timestamps();

            $table->unique(['mailbox_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('mailbox_group_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_id')->constrained()->onDelete('cascade');
            $table->foreignId('group_id')->constrained('user_groups')->onDelete('cascade');
            $table->string('role', 20); // viewer, member, owner
            $table->timestamps();

            $table->unique(['mailbox_id', 'group_id']);
            $table->index('group_id');
        });

        Schema::create('email_user_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->boolean('is_read')->default(false);
            $table->boolean('is_starred')->default(false);
            $table->timestamps();

            $table->unique(['email_id', 'user_id']);
            $table->index(['user_id', 'is_read']);
        });

        // Backfill: every existing mailbox owner gets an 'owner' row in mailbox_users
        $now = now()->toDateTimeString();
        DB::statement("
            INSERT INTO mailbox_users (mailbox_id, user_id, role, created_at, updated_at)
            SELECT id, user_id, 'owner', '{$now}', '{$now}' FROM mailboxes
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('email_user_states');
        Schema::dropIfExists('mailbox_group_assignments');
        Schema::dropIfExists('mailbox_users');
    }
};
