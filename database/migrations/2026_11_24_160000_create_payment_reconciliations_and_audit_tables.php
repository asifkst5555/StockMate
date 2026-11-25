<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create payment_reconciliations (N-02) and payment_recovery_audits (N-04) tables.
     * Fully reversible; preserves all existing payment, transaction, and subscription records.
     */
    public function up(): void
    {
        Schema::create('payment_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('provider_charge_id', 191);
            $table->string('provider_payment_intent_id', 191)->nullable();
            $table->string('provider_event_id', 191)->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedBigInteger('payment_intent_id')->nullable();
            $table->string('reason_code', 64);
            $table->string('status', 32)->default('pending');
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_charge_id', 'reason_code'], 'pr_provider_charge_reason_unique');
            $table->index(['status', 'detected_at'], 'pr_status_detected_index');

            $table->foreign('transaction_id')
                ->references('id')->on('transactions')
                ->onUpdate('cascade')
                ->onDelete('set null');

            $table->foreign('payment_intent_id')
                ->references('id')->on('payment_intents')
                ->onUpdate('cascade')
                ->onDelete('set null');

            $table->foreign('resolved_by')
                ->references('id')->on('users')
                ->onUpdate('cascade')
                ->onDelete('set null');
        });

        Schema::create('payment_recovery_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_user_id');
            $table->string('target_type', 40);
            $table->unsignedBigInteger('target_id');
            $table->string('action', 40);
            $table->text('reason');
            $table->string('previous_status', 40)->nullable();
            $table->string('new_status', 40)->nullable();
            $table->timestamps();

            $table->index(['target_type', 'target_id'], 'pra_target_index');

            $table->foreign('actor_user_id')
                ->references('id')->on('users')
                ->onUpdate('cascade')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_recovery_audits');
        Schema::dropIfExists('payment_reconciliations');
    }
};
