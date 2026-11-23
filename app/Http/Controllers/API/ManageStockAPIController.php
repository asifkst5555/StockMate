<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Http\Resources\ManageStockCollection;
use App\Http\Resources\ManageStockResource;
use App\Models\ManageStock;
use App\Models\StockMovement;
use App\Repositories\ManageStockRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Class UserAPIController
 */
class ManageStockAPIController extends AppBaseController
{
    private $manageStockRepository;

    public function __construct(ManageStockRepository $manageStockRepository)
    {
        $this->manageStockRepository = $manageStockRepository;
    }

    public function stockReport(Request $request): ManageStockCollection
    {
        $request->request->remove('filter');
        $perPage = getPageSize($request);
        $search = $request->get('search');
        $warehouseId = $request->get('warehouse_id');
        if ($search && $search != 'null') {
            $stocks = $this->manageStockRepository
                ->whereHas('warehouse')
                ->whereHas('product.productCategory',
                    function ($query) use ($search) {
                        $query->where('products.code', 'like', '%'.$search.'%')
                            ->orWhere('products.name', 'like', '%'.$search.'%')
                            ->orWhere('products.product_cost', 'like', '%'.$search.'%')
                            ->orWhere('products.product_price', 'like', '%'.$search.'%')
                            ->orWhere('product_categories.name', 'like', '%'.$search.'%');
                    })->where('warehouse_id', $warehouseId)->paginate($perPage);
        } else {
            $stocks = $this->manageStockRepository
                ->whereHas('warehouse')
                ->where('warehouse_id', $warehouseId)->paginate($perPage);
        }
        ManageStockResource::usingWithCollection();

        return new ManageStockCollection($stocks);
    }

    public function stockMovements(Request $request): JsonResponse
    {
        $perPage = getPageSize($request);
        $query = StockMovement::with(['warehouse:id,name', 'product:id,name,code', 'user:id,first_name,last_name'])
            ->latest('id');

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->get('warehouse_id'));
        }
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->get('product_id'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->get('type'));
        }

        return $this->sendResponse($query->paginate($perPage), 'Stock movements retrieved successfully.');
    }

    public function stockValuation(Request $request): JsonResponse
    {
        $query = ManageStock::query()
            ->join('products', 'products.id', '=', 'manage_stocks.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'manage_stocks.warehouse_id')
            ->selectRaw('manage_stocks.warehouse_id, warehouses.name as warehouse_name, COUNT(DISTINCT manage_stocks.product_id) as sku_count, COALESCE(SUM(manage_stocks.quantity), 0) as total_quantity, COALESCE(SUM(manage_stocks.quantity * products.product_cost), 0) as cost_valuation, COALESCE(SUM(manage_stocks.quantity * products.product_price), 0) as retail_valuation')
            ->groupBy('manage_stocks.warehouse_id', 'warehouses.name');

        if ($request->filled('warehouse_id')) {
            $query->where('manage_stocks.warehouse_id', $request->get('warehouse_id'));
        }

        $byWarehouse = $query->get();

        $summary = [
            'total_quantity' => (float) $byWarehouse->sum('total_quantity'),
            'total_cost_valuation' => round((float) $byWarehouse->sum('cost_valuation'), 2),
            'total_retail_valuation' => round((float) $byWarehouse->sum('retail_valuation'), 2),
            'potential_profit' => round((float) $byWarehouse->sum('retail_valuation') - (float) $byWarehouse->sum('cost_valuation'), 2),
            'warehouses' => $byWarehouse,
        ];

        return $this->sendResponse($summary, 'Stock valuation retrieved successfully.');
    }
}
