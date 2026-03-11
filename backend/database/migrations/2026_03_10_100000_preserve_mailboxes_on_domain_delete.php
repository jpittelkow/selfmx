<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add domain_name column so orphaned mailboxes can still display their full address
        // and be re-associated when the domain is re-added
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->string('domain_name')->nullable()->after('address');
        });

        // Populate domain_name from existing domains
        DB::table('mailboxes')
            ->whereNotNull('email_domain_id')
            ->update([
                'domain_name' => DB::raw(
                    '(SELECT name FROM email_domains WHERE email_domains.id = mailboxes.email_domain_id)'
                ),
            ]);

        // Change FK from cascade to set null
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropForeign(['email_domain_id']);
        });

        Schema::table('mailboxes', function (Blueprint $table) {
            $table->foreignId('email_domain_id')->nullable()->change();
            $table->foreign('email_domain_id')
                ->references('id')
                ->on('email_domains')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        // WARNING: This permanently deletes orphaned mailboxes (and their emails if
        // cascading FKs exist). Only roll back if you're sure no orphaned data matters.
        DB::table('mailboxes')->whereNull('email_domain_id')->delete();

        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropForeign(['email_domain_id']);
        });

        Schema::table('mailboxes', function (Blueprint $table) {
            $table->foreignId('email_domain_id')->nullable(false)->change();
            $table->foreign('email_domain_id')
                ->references('id')
                ->on('email_domains')
                ->onDelete('cascade');
        });

        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropColumn('domain_name');
        });
    }
};
