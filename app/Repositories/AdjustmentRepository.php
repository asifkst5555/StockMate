<?php

namespace App\Repositories;

use App\Models\Adjustment;
use App\Models\AdjustmentItem;
use App\Models\ManageStock;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\StockMovementService;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Class SaleRepository
 */
class AdjustmentRepository extends BaseRepository
{
    /**
     * @var array
     */
    protected $fieldSearchable = [
        'date',
        'reference_code',
        'warehouse_id',
        'total_products',
        'created_at',
    ];

    /**
     * @var string[]
     */
    protected $allowedFields = [
        'date',
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
    public function model(): string
    {
        return Adjustment::class;
    }

    public function storeAdjustment($input): Adjustment
    {
        try {
            DB::beginTransaction();

            $whId = (int) ($input['warehouse_id'] ?? 0);
            $warehouse = Warehouse::whereKey($whId)->first();
            if (! $warehouse) {
                throw new UnprocessableEntityHttpException('Selected warehouse does not belong to the active store.');
            }

            $itemsInput = $input['adjustment_items'] ?? [];
            if (! is_array($itemsInput) || empty($itemsInput)) {
                throw new UnprocessableEntityHttpException('At least one adjustment item is required.');
            }

            $input['total_products'] = count($itemsInput);
            $input['date'] = $input['date'] ?? date('Y/m/d');
            $adjustmentInputArray = [
                'tenant_id' => $warehouse->tenant_id,
                'date' => $input['date'],
                'warehouse_id' => $whId,
                'total_products' => $input['total_products'],
            ];
            $adjustment = Adjustment::create($adjustmentInputArray);
            $reference_code = 'AD_111' . $adjustment->id;
            $adjustment->update(['reference_code' => $reference_code]);

            $adjustment = $this->storeAdjustmentItems($adjustment, $input, $warehouse);

            DB::commit();

            return $adjustment;
        } catch (ModelNotFoundException|HttpExceptionInterface $e) {
            DB::rollBack();
            throw $e;
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    public function storeAdjustmentItems($adjustment, $input, ?Warehouse $warehouse = null)
    {
        /** @var StockMovementService $stockService */
        $stockService = app(StockMovementService::class);
        $warehouse = $warehouse ?: Warehouse::withoutGlobalScope('tenant')->findOrFail((int) $adjustment->warehouse_id);

        $this->validateAndLoadProducts($input['adjustment_items'], (string) $warehouse->tenant_id, $stockService);

        $pairs = [];
        foreach ($input['adjustment_items'] as $adjustmentItem) {
            $pairs[] = ['warehouse_id' => (int) $adjustment->warehouse_id, 'product_id' => (int) $adjustmentItem['product_id']];
        }
        $stockService->lockStockRows($pairs);

        foreach ($input['adjustment_items'] as $adjustmentItem) {
            $qty = $stockService->assertPositiveQuantity($adjustmentItem['quantity'] ?? null, 'Adjustment quantity must be greater than zero.');
            $methodType = (int) ($adjustmentItem['method_type'] ?? 0);
            if (! in_array($methodType, [AdjustmentItem::METHOD_ADDITION, AdjustmentItem::METHOD_SUBTRACTION], true)) {
                throw new UnprocessableEntityHttpException('Invalid adjustment method type.');
            }

            AdjustmentItem::create([
                'adjustment_id' => $adjustment->id,
                'product_id' => (int) $adjustmentItem['product_id'],
                'method_type' => $methodType,
                'quantity' => $qty,
            ]);

            $stockService->adjust(
                (int) $adjustment->warehouse_id,
                (int) $adjustmentItem['product_id'],
                $qty,
                $methodType,
                (int) $adjustment->id
            );
        }

        return $adjustment;
    }

    public function updateAdjustment($input, $id)
    {
        try {
            DB::beginTransaction();

            $adjustment = Adjustment::with('adjustmentItems')->whereKey($id)->lockForUpdate()->firstOrFail();

            if (isset($input['warehouse_id']) && (int) $input['warehouse_id'] !== (int) $adjustment->warehouse_id) {
                throw new UnprocessableEntityHttpException('Changing adjustment warehouse on update is not supported; delete and create a new adjustment instead.');
            }

            $warehouse = Warehouse::whereKey((int) $adjustment->warehouse_id)->first();
            if (! $warehouse) {
                throw new UnprocessableEntityHttpException('Selected warehouse does not belong to the active store.');
            }

            $itemsInput = $input['adjustment_items'] ?? [];
            if (! is_array($itemsInput) || empty($itemsInput)) {
                throw new UnprocessableEntityHttpException('At least one adjustment item is required.');
            }

            $input['total_products'] = count($itemsInput);
            $input['date'] = $input['date'] ?? date('Y/m/d');
            $adjustmentInputArray = Arr::only($input, [
                'date',
                'total_products',
            ]);
            $adjustment->update($adjustmentInputArray);

            $adjustment = $this->updateAdjustmentItems($adjustment, $input, $warehouse);

            DB::commit();

            return $adjustment;
        } catch (ModelNotFoundException|HttpExceptionInterface $e) {
            DB::rollBack();
            throw $e;
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    public function updateAdjustmentItems($adjustment, $input, ?Warehouse $warehouse = null)
    {
        /** @var StockMovementService $stockService */
        $stockService = app(StockMovementService::class);
        $adjustment->loadMissing('adjustmentItems');
        $warehouse = $warehouse ?: Warehouse::withoutGlobalScope('tenant')->findOrFail((int) $adjustment->warehouse_id);

        $this->validateAndLoadProducts($input['adjustment_items'], (string) $warehouse->tenant_id, $stockService);

        $existingItemsById = $adjustment->adjustmentItems->keyBy('id');
        $seenChildIds = [];
        $keptItemIds = [];

        foreach ($input['adjustment_items'] as $adjustmentItem) {
            $rawItemId = $adjustmentItem['adjustment_item_id'] ?? null;
            if ($rawItemId === null || $rawItemId === '') {
                continue;
            }
            if (! is_numeric($rawItemId) || (int) $rawItemId <= 0 || (string) (int) $rawItemId !== (string) $rawItemId) {
                throw new UnprocessableEntityHttpException('Invalid adjustment item ID.');
            }
            $childId = (int) $rawItemId;
            if (isset($seenChildIds[$childId])) {
                throw new UnprocessableEntityHttpException('Duplicate adjustment item IDs are not permitted.');
            }
            $seenChildIds[$childId] = true;

            /** @var AdjustmentItem|null $existingItem */
            $existingItem = $existingItemsById->get($childId);
            if (! $existingItem) {
                throw new UnprocessableEntityHttpException('Adjustment item not found or does not belong to this adjustment.');
            }
            if ((int) $adjustmentItem['product_id'] !== (int) $existingItem->product_id) {
                throw new UnprocessableEntityHttpException('Changing product on an existing adjustment item is not supported; remove the line and add the new product instead.');
            }
            $keptItemIds[] = $childId;
        }

        // Lock ALL involved (warehouse_id, product_id) rows upfront: existing/removed stored product_ids + incoming product_ids
        $pairs = [];
        foreach ($adjustment->adjustmentItems as $existingItem) {
            $pairs[] = ['warehouse_id' => (int) $adjustment->warehouse_id, 'product_id' => (int) $existingItem->product_id];
        }
        foreach ($input['adjustment_items'] as $item) {
            $pairs[] = ['warehouse_id' => (int) $adjustment->warehouse_id, 'product_id' => (int) $item['product_id']];
        }
        $stockService->lockStockRows($pairs);

        $adjustmentItmOldIds = $existingItemsById->keys()->map(fn ($v) => (int) $v)->all();
        $removeItemIds = array_values(array_diff($adjustmentItmOldIds, $keptItemIds));

        if (! empty($removeItemIds)) {
            foreach ($removeItemIds as $removeItemId) {
                /** @var AdjustmentItem $oldItem */
                $oldItem = $existingItemsById->get($removeItemId);
                $reverseDelta = ((int) $oldItem->method_type === AdjustmentItem::METHOD_ADDITION)
                    ? -(float) $oldItem->quantity
                    : (float) $oldItem->quantity;

                $stockService->reverse(
                    (int) $adjustment->warehouse_id,
                    (int) $oldItem->product_id,
                    $reverseDelta,
                    'adjustment_update_reversal',
                    Adjustment::class,
                    (int) $adjustment->id,
                    null,
                    'Adjustment item removed (' . ($adjustment->reference_code ?? ('#' . $adjustment->id)) . ')',
                    'Cannot remove adjustment item: insufficient stock available to reverse addition.'
                );
            }
            $adjustment->adjustmentItems()->whereIn('id', $removeItemIds)->delete();
        }

        foreach ($input['adjustment_items'] as $adjustmentItem) {
            $rawItemId = $adjustmentItem['adjustment_item_id'] ?? null;
            $qty = $stockService->assertPositiveQuantity($adjustmentItem['quantity'] ?? null, 'Adjustment quantity must be greater than zero.');
            $newMethod = (int) ($adjustmentItem['method_type'] ?? 0);
            if (! in_array($newMethod, [AdjustmentItem::METHOD_ADDITION, AdjustmentItem::METHOD_SUBTRACTION], true)) {
                throw new UnprocessableEntityHttpException('Invalid adjustment method type.');
            }

            if ($rawItemId === null || $rawItemId === '') {
                AdjustmentItem::create([
                    'adjustment_id' => $adjustment->id,
                    'product_id' => (int) $adjustmentItem['product_id'],
                    'method_type' => $newMethod,
                    'quantity' => $qty,
                ]);

                $stockService->adjust(
                    (int) $adjustment->warehouse_id,
                    (int) $adjustmentItem['product_id'],
                    $qty,
                    $newMethod,
                    (int) $adjustment->id,
                    'Adjustment item added (' . ($adjustment->reference_code ?? ('#' . $adjustment->id)) . ')'
                );
            } else {
                /** @var AdjustmentItem $exitAdjustmentItem */
                $exitAdjustmentItem = $existingItemsById->get((int) $rawItemId);

                $oldSigned = ((int) $exitAdjustmentItem->method_type === AdjustmentItem::METHOD_ADDITION)
                    ? (float) $exitAdjustmentItem->quantity
                    : -(float) $exitAdjustmentItem->quantity;

                $newSigned = ($newMethod === AdjustmentItem::METHOD_ADDITION) ? $qty : -$qty;
                $netDelta = $newSigned - $oldSigned;

                $stockService->applyDelta(
                    (int) $adjustment->warehouse_id,
                    (int) $exitAdjustmentItem->product_id,
                    $netDelta,
                    $netDelta >= 0 ? 'adjustment_add' : 'adjustment_sub',
                    Adjustment::class,
                    (int) $adjustment->id,
                    null,
                    'Adjustment item updated (' . ($adjustment->reference_code ?? ('#' . $adjustment->id)) . ')',
                    'Quantity exceeds quantity available in stock.'
                );

                $exitAdjustmentItem->update([
                    'quantity' => $qty,
                    'method_type' => $newMethod,
                ]);
            }
        }

        return $adjustment;
    }

    /**
     * @return array<int, Product>
     */
    private function validateAndLoadProducts(array $itemsInput, string $tenantId, StockMovementService $stockService): array
    {
        $productsById = [];
        $seenProductIds = [];

        foreach ($itemsInput as $adjustmentItem) {
            $stockService->assertPositiveQuantity($adjustmentItem['quantity'] ?? null, 'Adjustment quantity must be greater than zero.');
            $methodType = (int) ($adjustmentItem['method_type'] ?? 0);
            if (! in_array($methodType, [AdjustmentItem::METHOD_ADDITION, AdjustmentItem::METHOD_SUBTRACTION], true)) {
                throw new UnprocessableEntityHttpException('Invalid adjustment method type.');
            }

            $pid = (int) ($adjustmentItem['product_id'] ?? 0);
            if ($pid <= 0) {
                throw new UnprocessableEntityHttpException('Selected adjustment product does not belong to the active store.');
            }
            if (isset($seenProductIds[$pid])) {
                throw new UnprocessableEntityHttpException('Duplicate product lines are not permitted in an adjustment.');
            }
            $seenProductIds[$pid] = true;

            $productModel = Product::whereKey($pid)->first();
            if (! $productModel || (string) $productModel->tenant_id !== $tenantId) {
                throw new UnprocessableEntityHttpException('Selected adjustment product does not belong to the active store.');
            }

            $submittedUnit = $adjustmentItem['sale_unit'] ?? ($adjustmentItem['purchase_unit'] ?? null);
            $stockService->assertSupportedUnitParity($productModel, $submittedUnit, 'sale');

            $productsById[$pid] = $productModel;
        }

        return $productsById;
    }
}
