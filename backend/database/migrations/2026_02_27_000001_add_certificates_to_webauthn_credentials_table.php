<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add missing columns required by laragear/webauthn v4.
     */
    public function up(): void
    {
        $missing = [];

        if (! Schema::hasColumn('webauthn_credentials', 'user_id')) {
            $missing[] = 'user_id';
        }

        if (! Schema::hasColumn('webauthn_credentials', 'certificates')) {
            $missing[] = 'certificates';
        }

        if (! Schema::hasColumn('webauthn_credentials', 'disabled_at')) {
            $missing[] = 'disabled_at';
        }

        if (empty($missing)) {
            return;
        }

        Schema::table('webauthn_credentials', function (Blueprint $table) use ($missing) {
            if (in_array('user_id', $missing)) {
                $table->uuid('user_id')->after('authenticatable_id');
            }

            if (in_array('certificates', $missing)) {
                $table->json('certificates')->nullable()->after('attestation_format');
            }

            if (in_array('disabled_at', $missing)) {
                $table->timestamp('disabled_at')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('webauthn_credentials', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('webauthn_credentials', 'user_id')) {
                $columns[] = 'user_id';
            }
            if (Schema::hasColumn('webauthn_credentials', 'certificates')) {
                $columns[] = 'certificates';
            }
            if (Schema::hasColumn('webauthn_credentials', 'disabled_at')) {
                $columns[] = 'disabled_at';
            }
            if (! empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
