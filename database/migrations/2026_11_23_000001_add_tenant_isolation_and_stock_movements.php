<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tenantTables = [
        'purchases',
        'purchases_return',
        'sales_return',
        'expenses',
        'holds',
        'manage_stocks',
        'coupon_codes',
    ];

    /**
     * Additive, non-destructive migration:
     * 1. Adds tenant_id + FK to core transactional tables previously missing tenant isolation.
     * 2. Backfills tenant_id from warehouses.tenant_id where applicable.
     * 3. Consolidates any duplicate manage_stocks(warehouse_id, product_id) rows and adds a unique index.
     * 4. Creates stock_movements ledger table for full stock audit history.
     */
    public function up(): void
    {
        foreach ($this->tenantTables as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'tenant_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->string('tenant_id')->nullable()->after('id')->index();
                    $table->foreign('tenant_id')
                        ->references('id')
                        ->on('tenants')
                        ->onUpdate('cascade')
                        ->onDelete('cascade');
                });
            }
        }

        // Safely backfill tenant_id from warehouses for existing records
        $warehouseLinkedTables = [
            'purchases',
            'purchases_return',
            'sales_return',
            'expenses',
            'holds',
            'manage_stocks',
        ];
        foreach ($warehouseLinkedTables as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'warehouse_id') && Schema::hasColumn($tableName, 'tenant_id')) {
                DB::table($tableName)
                    ->whereNull($tableName . '.tenant_id')
                    ->join('warehouses', 'warehouses.id', '=', $tableName . '.warehouse_id')
                    ->update([$tableName . '.tenant_id' => DB::raw('warehouses.tenant_id')]);
            }
        }

        // Detect and safely merge any duplicate (warehouse_id, product_id) rows before adding unique constraint
        if (Schema::hasTable('manage_stocks')) {
            $duplicates = DB::table('manage_stocks')
                ->select('warehouse_id', 'product_id', DB::raw('MIN(id) as keep_id'), DB::raw('SUM(quantity) as total_qty'), DB::raw('COUNT(*) as cnt'))
                ->groupBy('warehouse_id', 'product_id')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            foreach ($duplicates as $dup) {
                DB::table('manage_stocks')
                    ->where('id', $dup->keep_id)
                    ->update(['quantity' => $dup->total_qty]);

                DB::table('manage_stocks')
                    ->where('warehouse_id', $dup->warehouse_id)
                    ->where('product_id', $dup->product_id)
                    ->where('id', '!=', $dup->keep_id)
                    ->delete();
            }

            Schema::table('manage_stocks', function (Blueprint $table) {
                $table->unique(['warehouse_id', 'product_id'], 'manage_stocks_warehouse_product_unique');
            });
        }

        // Create stock_movements audit history table
        if (! Schema::hasTable('stock_movements')) {
            Schema::create('stock_movements', function (Blueprint $table) {
                $table->id();
                $table->string('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('warehouse_id');
                $table->unsignedBigInteger('product_id');
                $table->string('type', 50)->index(); // sale, purchase, sale_return, purchase_return, transfer_in, transfer_out, adjustment_add, adjustment_sub, manual
                $table->double('quantity_change');
                $table->double('quantity_before')->default(0);
                $table->double('quantity_after')->default(0);
                $table->double('unit_cost')->nullable();
                $table->string('reference_type', 100)->nullable();
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->text('note')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->timestamps();

                $table->foreign('tenant_id')->references('id')->on('tenants')->onUpdate('cascade')->onDelete('cascade');
                $table->foreign('warehouse_id')->references('id')->on('warehouses')->onUpdate('cascade')->onDelete('cascade');
                $table->foreign('product_id')->references('id')->on('products')->onUpdate('cascade')->onDelete('cascade');
                $table->index(['warehouse_id', 'product_id', 'created_at'], 'stock_movements_wh_prod_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');

        if (Schema::hasTable('manage_stocks')) {
            Schema::table('manage_stocks', function (Blueprint $table) {
                $table->dropUnique('manage_stocks_warehouse_product_unique');
            });
        }

        foreach ($this->tenantTables as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'tenant_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropForeign(['tenant_id']);
                    $table->dropColumn('tenant_id');
                });
            }
        }
    }
};
