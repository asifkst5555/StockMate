<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create server-side payment_intents table binding checkout sessions to DB pricing & ownership.
     */
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->unsignedBigInteger('user_id');
            $table->string('tenant_id')->nullable();
            $table->unsignedBigInteger('plan_id');
            $table->unsignedTinyInteger('gateway');
            $table->unsignedBigInteger('amount_minor');
            $table->decimal('amount_decimal', 15, 2);
            $table->string('currency', 10);
            $table->json('stores')->nullable();
            $table->string('provider_reference', 191)->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'provider_reference'], 'payment_intents_gateway_provider_ref_unique');
            $table->index(['user_id', 'status'], 'payment_intents_user_status_index');

            $table->foreign('user_id')
                ->references('id')->on('users')
                ->onUpdate('cascade')
                ->onDelete('cascade');

            $table->foreign('plan_id')
                ->references('id')->on('plans')
                ->onUpdate('cascade')
                ->onDelete('cascade');

            $table->foreign('transaction_id')
                ->references('id')->on('transactions')
                ->onUpdate('cascade')
                ->onDelete('set null');

            $table->foreign('subscription_id')
                ->references('id')->on('subscriptions')
                ->onUpdate('cascade')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_intents');
    }
};
