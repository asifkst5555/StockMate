<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add webhook processing lease/fencing columns (V-01/V-04) and nullable
     * Stripe PaymentIntent correlation columns (V-02) while preserving all historical data.
     */
    public function up(): void
    {
        Schema::table('payment_webhook_events', function (Blueprint $table) {
            $table->timestamp('processing_started_at')->nullable()->after('status');
            $table->timestamp('locked_until')->nullable()->after('processing_started_at');
            $table->string('processing_token', 64)->nullable()->after('locked_until');
            $table->timestamp('dead_lettered_at')->nullable()->after('processed_at');

            $table->index(['status', 'locked_until'], 'pwe_status_locked_until_index');
        });

        Schema::table('payment_intents', function (Blueprint $table) {
            $table->string('provider_payment_intent_id', 191)->nullable()->after('provider_reference');
            $table->unique(['gateway', 'provider_payment_intent_id'], 'payment_intents_gateway_pi_unique');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('provider_payment_intent_id', 191)->nullable()->after('transaction_id');
            $table->unique(['type', 'provider_payment_intent_id'], 'transactions_type_pi_unique');
        });
    }

    /**
     * Reverse the migrations cleanly without mutating historical financial rows.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique('transactions_type_pi_unique');
            $table->dropColumn('provider_payment_intent_id');
        });

        Schema::table('payment_intents', function (Blueprint $table) {
            $table->dropUnique('payment_intents_gateway_pi_unique');
            $table->dropColumn('provider_payment_intent_id');
        });

        Schema::table('payment_webhook_events', function (Blueprint $table) {
            $table->dropIndex('pwe_status_locked_until_index');
            $table->dropColumn([
                'processing_started_at',
                'locked_until',
                'processing_token',
                'dead_lettered_at',
            ]);
        });
    }
};
