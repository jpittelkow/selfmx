<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_tokens', function (Blueprint $table) {
            $table->string('key_prefix', 16)->nullable()->after('token');
            $table->unsignedInteger('rate_limit')->nullable()->after('abilities');
            $table->foreignId('rotated_from_id')->nullable()->constrained('api_tokens')->nullOnDelete()->after('rate_limit');
            $table->timestamp('revoked_at')->nullable()->after('expires_at');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('api_tokens', function (Blueprint $table) {
            $table->dropForeign(['rotated_from_id']);
            $table->dropColumn(['key_prefix', 'rate_limit', 'rotated_from_id', 'revoked_at', 'deleted_at']);
        });
    }
};
