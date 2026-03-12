<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name', 255);
            $table->text('body');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index('user_id');
        });

        Schema::table('mailboxes', function (Blueprint $table) {
            $table->foreignId('default_signature_id')->nullable()->after('signature')->constrained('signatures')->onDelete('set null');
        });

        // Migrate existing mailbox signatures into the signatures table
        $mailboxes = DB::table('mailboxes')
            ->whereNotNull('signature')
            ->where('signature', '!=', '')
            ->get();

        $usersWithDefault = [];

        foreach ($mailboxes as $mailbox) {
            $isDefault = !in_array($mailbox->user_id, $usersWithDefault);

            $signatureId = DB::table('signatures')->insertGetId([
                'user_id' => $mailbox->user_id,
                'name' => $mailbox->address . '@' . ($mailbox->domain_name ?? 'unknown'),
                'body' => $mailbox->signature,
                'is_default' => $isDefault,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('mailboxes')
                ->where('id', $mailbox->id)
                ->update(['default_signature_id' => $signatureId]);

            if ($isDefault) {
                $usersWithDefault[] = $mailbox->user_id;
            }
        }
    }

    public function down(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropForeign(['default_signature_id']);
            $table->dropColumn('default_signature_id');
        });

        Schema::dropIfExists('signatures');
    }
};
