<?php

namespace App\Repositories;

use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Warehouse;

/**
 * Class WarehouseRepository
 */
class WarehouseRepository extends BaseRepository
{
    /**
     * @var array
     */
    protected $fieldSearchable = [
        'name',
        'phone',
        'country',
        'city',
        'email',
        'zip_code',
        'created_at',
    ];

    /**
     * Return searchable fields
     */
    public function getFieldsSearchable(): array
    {
        return $this->fieldSearchable;
    }

    /**
     * Configure the Model
     **/
    public function model()
    {
        return Warehouse::class;
    }

    public function warehouseCanDelete($id): bool
    {
        $warehouseModels = [
            Sale::class,
            Purchase::class,
            \App\Models\SaleReturn::class,
            \App\Models\PurchaseReturn::class,
            \App\Models\Adjustment::class,
            \App\Models\Expense::class,
            \App\Models\Quotation::class,
            \App\Models\Hold::class,
            \App\Models\StockMovement::class,
        ];

        if (canDelete($warehouseModels, 'warehouse_id', $id)) {
            return true;
        }

        $hasTransfers = \App\Models\Transfer::where('from_warehouse_id', $id)
            ->orWhere('to_warehouse_id', $id)
            ->exists();
        if ($hasTransfers) {
            return true;
        }

        $hasActiveStock = \App\Models\ManageStock::where('warehouse_id', $id)
            ->where('quantity', '>', 0)
            ->exists();

        return $hasActiveStock;
    }
}
