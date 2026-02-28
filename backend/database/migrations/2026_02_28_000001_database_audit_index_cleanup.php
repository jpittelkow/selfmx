<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Database audit cleanup: add missing FK indexes on payments, add
     * correlation_id indexes on log tables, and drop the redundant
     * api_tokens.token standalone index (unique constraint already covers it).
     */
    public function up(): void
    {
        // Add missing indexes on payments foreign keys.
        // foreignId()->constrained() does not auto-create a standalone index
        // in SQLite, unlike MySQL. Both columns are used in WHERE clauses.
        Schema::table('payments', function (Blueprint $table) {
            $table->index('user_id');
            $table->index('stripe_customer_id');
        });

        // Add index on correlation_id for point-lookup filtering in AuditService
        // and AccessLogService. These tables grow with every request.
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index('correlation_id');
        });

        Schema::table('access_logs', function (Blueprint $table) {
            $table->index('correlation_id');
        });

        // Drop the redundant standalone index on api_tokens.token.
        // The ->unique() constraint already creates an index the query planner
        // uses for WHERE token = ? lookups. The extra ->index('token') is dead weight.
        Schema::table('api_tokens', function (Blueprint $table) {
            $table->dropIndex('api_tokens_token_index');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropIndex(['stripe_customer_id']);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['correlation_id']);
        });

        Schema::table('access_logs', function (Blueprint $table) {
            $table->dropIndex(['correlation_id']);
        });

        Schema::table('api_tokens', function (Blueprint $table) {
            $table->index('token');
        });
    }
};
