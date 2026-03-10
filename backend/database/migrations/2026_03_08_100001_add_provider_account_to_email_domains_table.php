<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_domains', function (Blueprint $table) {
            $table->foreignId('email_provider_account_id')
                ->nullable()
                ->after('provider')
                ->constrained('email_provider_accounts')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('email_domains', function (Blueprint $table) {
            $table->dropForeign(['email_provider_account_id']);
            $table->dropColumn('email_provider_account_id');
        });
    }
};
