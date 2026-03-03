<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_domains', function (Blueprint $table) {
            $table->timestamp('dkim_rotated_at')->nullable()->after('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('email_domains', function (Blueprint $table) {
            $table->dropColumn('dkim_rotated_at');
        });
    }
};
