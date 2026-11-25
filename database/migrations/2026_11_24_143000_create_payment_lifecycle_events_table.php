<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add webhook retry metadata and payment_lifecycle_events table for refunds & disputes.
     */
    public function up(): void
    {
        Schema::table('payment_webhook_events', function (Blueprint $table) {
            $table->unsignedSmallInteger('attempts')->default(1)->after('status');
            $table->text('last_error')->nullable()->after('attempts');
        });

        Schema::create('payment_lifecycle_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('provider_event_id', 191);
            $table->string('provider_lifecycle_id', 191);
            $table->string('provider_transaction_id', 191);
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedBigInteger('payment_intent_id')->nullable();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('lifecycle_type', 40);
            $table->string('status', 32)->default('recorded');
            $table->unsignedBigInteger('amount_minor')->default(0);
            $table->string('currency', 10);
            $table->boolean('requires_admin_review')->default(true);
            $table->timestamp('reviewed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_event_id'], 'ple_provider_event_unique');
            $table->unique(['provider', 'provider_lifecycle_id', 'lifecycle_type'], 'ple_provider_lifecycle_type_unique');
            $table->index(['transaction_id', 'lifecycle_type'], 'ple_tx_type_index');

            $table->foreign('transaction_id')
                ->references('id')->on('transactions')
                ->onUpdate('cascade')
                ->onDelete('set null');

            $table->foreign('payment_intent_id')
                ->references('id')->on('payment_intents')
                ->onUpdate('cascade')
                ->onDelete('set null');

            $table->foreign('subscription_id')
                ->references('id')->on('subscriptions')
                ->onUpdate('cascade')
                ->onDelete('set null');

            $table->foreign('user_id')
                ->references('id')->on('users')
                ->onUpdate('cascade')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_lifecycle_events');

        Schema::table('payment_webhook_events', function (Blueprint $table) {
            $table->dropColumn(['attempts', 'last_error']);
        });
    }
};
