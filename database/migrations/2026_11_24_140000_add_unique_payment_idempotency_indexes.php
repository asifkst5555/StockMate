<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add database-level idempotency unique constraints on transactions and subscriptions.
     *
     * Safety guard: Fails fast without deleting or mutating financial history if
     * duplicate or blank transaction_id records exist in the target database.
     */
    public function up(): void
    {
        $blankTransactions = DB::table('transactions')
            ->whereNull('transaction_id')
            ->orWhere('transaction_id', '')
            ->count();

        if ($blankTransactions > 0) {
            throw new RuntimeException(
                "Cannot add unique index on transactions(transaction_id, type): {$blankTransactions} row(s) have NULL or empty transaction_id. Resolve manually before migrating."
            );
        }

        $duplicateTransactions = DB::select(
            'SELECT transaction_id, type, COUNT(*) AS cnt FROM transactions GROUP BY transaction_id, type HAVING cnt > 1 LIMIT 1'
        );

        if (! empty($duplicateTransactions)) {
            throw new RuntimeException(
                'Cannot add unique index on transactions(transaction_id, type): duplicate provider transaction records exist. Reconcile manually before migrating.'
            );
        }

        $duplicateSubscriptions = DB::select(
            'SELECT transaction_id, COUNT(*) AS cnt FROM subscriptions WHERE transaction_id IS NOT NULL GROUP BY transaction_id HAVING cnt > 1 LIMIT 1'
        );

        if (! empty($duplicateSubscriptions)) {
            throw new RuntimeException(
                'Cannot add unique index on subscriptions(transaction_id): multiple subscriptions reference the same transaction_id. Reconcile manually before migrating.'
            );
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->unique(['transaction_id', 'type'], 'transactions_transaction_id_type_unique');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            // MySQL allows multiple NULL values in a UNIQUE index, preserving free/manual subscriptions.
            $table->unique('transaction_id', 'subscriptions_transaction_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // Temporarily drop FK so MySQL InnoDB allows dropping the unique index backing it
            $table->dropForeign(['transaction_id']);
            $table->dropUnique('subscriptions_transaction_id_unique');
            $table->foreign('transaction_id')
                ->references('id')->on('transactions')
                ->onUpdate('CASCADE')
                ->onDelete('CASCADE');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique('transactions_transaction_id_type_unique');
        });
    }
};
