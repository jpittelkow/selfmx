<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('stripe_customer_id')->nullable()->constrained('stripe_customers')->onDelete('set null');
            $table->string('stripe_payment_intent_id')->unique();
            $table->integer('amount');
            $table->string('currency', 3);
            $table->string('status')->index();
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();
            $table->string('stripe_account_id')->nullable();
            $table->integer('application_fee_amount')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
