<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Http\Requests\CraeteAdjustmentRequest;
use App\Http\Requests\UpdateAdjustmentRequest;
use App\Http\Resources\AdjustmentCollection;
use App\Http\Resources\AdjustmentResource;
use App\Models\Adjustment;
use App\Models\AdjustmentItem;
use App\Models\ManageStock;
use App\Repositories\AdjustmentRepository;
use App\Services\StockMovementService;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class AdjustmentAPIController extends AppBaseController
{
    /** @var AdjustmentRepository */
    private $adjustmentRepository;

    public function __construct(AdjustmentRepository $adjustmentRepository)
    {
        $this->adjustmentRepository = $adjustmentRepository;
    }

    public function index(Request $request): AdjustmentCollection
    {
        $this->authorizeModuleAction('adjustments', 'view');

        $perPage = getPageSize($request);
        $warehouseId = $request->get('warehouse_id');

        $this->adjustmentRepository->resetScope();
        if ($warehouseId !== null && $warehouseId !== '' && $warehouseId !== 'null' && (int) $warehouseId > 0) {
            $whId = (int) $warehouseId;
            $this->adjustmentRepository->scopeQuery(function ($query) use ($whId) {
                return $query->where('warehouse_id', $whId);
            });
        }

        try {
            $adjustments = $this->adjustmentRepository->paginate($perPage);
        } finally {
            $this->adjustmentRepository->resetScope();
        }

        AdjustmentResource::usingWithCollection();

        return new AdjustmentCollection($adjustments);
    }

    public function store(CraeteAdjustmentRequest $request): AdjustmentResource
    {
        $this->authorizeModuleAction('adjustments', 'create');

        $input = $request->all();
        $adjustment = $this->adjustmentRepository->storeAdjustment($input);

        return new AdjustmentResource($adjustment);
    }

    public function show(Adjustment $adjustment): AdjustmentResource
    {
        $this->authorizeModuleAction('adjustments', 'view');

        $adjustment = $adjustment->load('adjustmentItems.product');

        return new AdjustmentResource($adjustment);
    }

    public function edit(Adjustment $adjustment): AdjustmentResource
    {
        $this->authorizeModuleAction('adjustments', 'edit');

        $adjustment = $adjustment->load('adjustmentItems.product.stocks', 'warehouse');

        return new AdjustmentResource($adjustment);
    }

    public function update(UpdateAdjustmentRequest $request, $id): AdjustmentResource
    {
        $this->authorizeModuleAction('adjustments', 'edit');

        $input = $request->all();
        $adjustment = $this->adjustmentRepository->updateAdjustment($input, $id);

        return new AdjustmentResource($adjustment);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function destroy(int $id)
    {
        $this->authorizeModuleAction('adjustments', 'delete');

        try {
            DB::beginTransaction();

            $adjustment = Adjustment::with('adjustmentItems')
                ->where('id', $id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var StockMovementService $stockService */
            $stockService = app(StockMovementService::class);

            $pairs = [];
            foreach ($adjustment->adjustmentItems as $item) {
                $pairs[] = ['warehouse_id' => $adjustment->warehouse_id, 'product_id' => $item->product_id];
            }
            $stockService->lockStockRows($pairs);

            foreach ($adjustment->adjustmentItems as $adjustmentItem) {
                $delta = ((int) $adjustmentItem->method_type === AdjustmentItem::METHOD_ADDITION)
                    ? -(float) $adjustmentItem->quantity
                    : (float) $adjustmentItem->quantity;

                $stockService->reverse(
                    (int) $adjustment->warehouse_id,
                    (int) $adjustmentItem->product_id,
                    $delta,
                    'adjustment_delete_reversal',
                    Adjustment::class,
                    (int) $adjustment->id,
                    null,
                    'Adjustment deletion reversal (' . ($adjustment->reference_code ?? ('#' . $adjustment->id)) . ')',
                    'Cannot delete adjustment: insufficient stock available to reverse addition.'
                );
            }

            $this->adjustmentRepository->delete($adjustment->id);

            DB::commit();

            return $this->sendSuccess('Adjustment delete successfully');
        } catch (ModelNotFoundException|HttpExceptionInterface $e) {
            DB::rollBack();
            throw $e;
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }
}
