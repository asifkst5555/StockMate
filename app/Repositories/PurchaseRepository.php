<?php

namespace App\Repositories;

use App\Models\ManageStock;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Class PurchaseRepository
 */
class PurchaseRepository extends BaseRepository
{
    /**
     * @var array
     */
    protected $fieldSearchable = [
        'date',
        'reference_code',
        'tax_rate',
        'tax_amount',
        'discount',
        'shipping',
        'grand_total',
        'received_amount',
        'paid_amount',
        'payment_type',
        'notes',
        'created_at',
    ];

    /**
     * @var string[]
     */
    protected $allowedFields = [
        'date',
        'tax_rate',
        'tax_amount',
        'discount',
        'shipping',
        'grand_total',
        'received_amount',
        'notes',
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
        return Purchase::class;
    }

    public function storePurchase($input)
    {
        try {
            DB::beginTransaction();
            foreach ($input['purchase_items'] as $purchase_items) {
                if (($purchase_items['quantity'] ?? 0) <= 0) {
                    throw new UnprocessableEntityHttpException('Please enter a quantity greater than zero.');
                }
            }

            $purchaseInputArray = Arr::only($input, [
                'supplier_id',
                'warehouse_id',
                'date',
                'tax_rate',
                'tax_amount',
                'discount',
                'shipping',
                'grand_total',
                'received_amount',
                'paid_amount',
                'partial_amount',
                'payment_type',
                'payment_status',
                'notes',
                'status',
            ]);

            /** @var \App\Services\StockMovementService $stockService */
            $stockService = app(\App\Services\StockMovementService::class);
            foreach ($input['purchase_items'] as $purchaseItem) {
                $stockService->assertPositiveQuantity($purchaseItem['quantity'] ?? null, 'Please enter a quantity greater than zero.');
                $productModel = \App\Models\Product::whereKey($purchaseItem['product_id'] ?? null)->first();
                if ($productModel) {
                    $stockService->assertSupportedUnitParity($productModel, $purchaseItem['purchase_unit'] ?? null, 'purchase');
                }
            }

            /** @var Purchase $purchase */
            $purchase = Purchase::create($purchaseInputArray);

            $purchase = $this->storePurchaseItems($purchase, $input);

            // Only increment stock when purchase status is RECEIVED
            if ((int) ($purchase->status ?? Purchase::RECEIVED) === Purchase::RECEIVED) {
                $pairs = [];
                foreach ($input['purchase_items'] as $purchaseItem) {
                    $pairs[] = ['warehouse_id' => (int) $input['warehouse_id'], 'product_id' => (int) $purchaseItem['product_id']];
                }
                $stockService->lockStockRows($pairs);

                foreach ($input['purchase_items'] as $purchaseItem) {
                    $stockService->increase(
                        (int) $input['warehouse_id'],
                        (int) $purchaseItem['product_id'],
                        (float) $purchaseItem['quantity'],
                        'purchase',
                        Purchase::class,
                        (int) $purchase->id,
                        isset($purchaseItem['product_cost']) ? (float) $purchaseItem['product_cost'] : null
                    );
                }
            }

            DB::commit();

            return $purchase;
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    /**
     * @return mixed
     */
    public function calculationPurchaseItems($purchaseItem)
    {
        $validator = Validator::make($purchaseItem, PurchaseItem::$rules);
        if ($validator->fails()) {
            throw new UnprocessableEntityHttpException($validator->errors()->first());
        }

        //discount calculation
        $perItemDiscountAmount = 0;
        $purchaseItem['net_unit_cost'] = $purchaseItem['product_cost'];
        if ($purchaseItem['discount_type'] == Purchase::PERCENTAGE) {
            if ($purchaseItem['discount_value'] <= 100 && $purchaseItem['discount_value'] >= 0) {
                $purchaseItem['discount_amount'] = ($purchaseItem['discount_value'] * $purchaseItem['product_cost'] / 100) * $purchaseItem['quantity'];
                $perItemDiscountAmount = $purchaseItem['discount_amount'] / $purchaseItem['quantity'];
                $purchaseItem['net_unit_cost'] -= $perItemDiscountAmount;
            } else {
                throw new UnprocessableEntityHttpException('Please enter discount value between 0 to 100.');
            }
        } elseif ($purchaseItem['discount_type'] == Purchase::FIXED) {
            if ($purchaseItem['discount_value'] <= $purchaseItem['product_cost'] && $purchaseItem['discount_value'] >= 0) {
                $purchaseItem['discount_amount'] = $purchaseItem['discount_value'] * $purchaseItem['quantity'];
                $perItemDiscountAmount = $purchaseItem['discount_amount'] / $purchaseItem['quantity'];
                $purchaseItem['net_unit_cost'] -= $perItemDiscountAmount;
            } else {
                throw new UnprocessableEntityHttpException("Please enter  discount's value between product's price.");
            }
        }
        //tax calculation
        $perItemTaxAmount = 0;
        if ($purchaseItem['tax_value'] <= 100 && $purchaseItem['tax_value'] >= 0) {
            if ($purchaseItem['tax_type'] == Purchase::EXCLUSIVE) {
                $purchaseItem['tax_amount'] = (($purchaseItem['net_unit_cost'] * $purchaseItem['tax_value']) / 100) * $purchaseItem['quantity'];
                $perItemTaxAmount = $purchaseItem['tax_amount'] / $purchaseItem['quantity'];
            } elseif ($purchaseItem['tax_type'] == Purchase::INCLUSIVE) {
                $purchaseItem['tax_amount'] = ($purchaseItem['net_unit_cost'] * $purchaseItem['tax_value']) / (100 + $purchaseItem['tax_value']) * $purchaseItem['quantity'];
                $perItemTaxAmount = $purchaseItem['tax_amount'] / $purchaseItem['quantity'];
                $purchaseItem['net_unit_cost'] -= $perItemTaxAmount;
            }
        } else {
            throw new UnprocessableEntityHttpException('Please enter tax value between 0 to 100 ');
        }
        $purchaseItem['sub_total'] = ($purchaseItem['net_unit_cost'] + $perItemTaxAmount) * $purchaseItem['quantity'];

        return $purchaseItem;
    }

    /**
     * @return mixed
     */
    public function storePurchaseItems($purchase, $input)
    {
        foreach ($input['purchase_items'] as $purchaseItem) {
            $items = $this->calculationPurchaseItems($purchaseItem);
            $purchaseItem = new PurchaseItem($items);
            $purchase->purchaseItems()->save($purchaseItem);
        }

        $subTotalAmount = $purchase->purchaseItems()->sum('sub_total');
        if ($input['discount'] <= $subTotalAmount) {
            $input['grand_total'] = $subTotalAmount - $input['discount'];
        } else {
            throw new UnprocessableEntityHttpException('Discount amount should not be greater than total.');
        }
        if ($input['tax_rate'] <= 100 && $input['tax_rate'] >= 0) {
            $input['tax_amount'] = $input['grand_total'] * $input['tax_rate'] / 100;
        } else {
            throw new UnprocessableEntityHttpException('Please enter tax value between 0 to 100.');
        }
        $input['grand_total'] = $input['grand_total'] + $input['tax_amount'];
        if ($input['shipping'] <= $input['grand_total'] && $input['shipping'] >= 0) {
            $input['grand_total'] += $input['shipping'];
        } else {
            throw new UnprocessableEntityHttpException(__('messages.error.shipping_amount_not_be_greater'));
        }

        $input['reference_code'] = getSettingValue('purchase_code') . '_111' . $purchase->id;
        $purchase->update($input);

        return $purchase;
    }

    /**
     * @return mixed
     */
    public function updatePurchase($input, $id)
    {
        try {
            DB::beginTransaction();

            /** @var \App\Services\StockMovementService $stockService */
            $stockService = app(\App\Services\StockMovementService::class);

            foreach ($input['purchase_items'] as $purchase_items) {
                $stockService->assertPositiveQuantity($purchase_items['quantity'] ?? null, 'Please enter a quantity greater than zero.');
                $productModel = \App\Models\Product::whereKey($purchase_items['product_id'] ?? null)->first();
                if ($productModel) {
                    $stockService->assertSupportedUnitParity($productModel, $purchase_items['purchase_unit'] ?? null, 'purchase');
                }
            }

            $purchase = Purchase::with('purchaseItems')->whereKey($id)->lockForUpdate()->firstOrFail();
            $wasReceived = ((int) $purchase->status === Purchase::RECEIVED);
            $newStatus = isset($input['status']) ? (int) $input['status'] : (int) $purchase->status;
            $willBeReceived = ($newStatus === Purchase::RECEIVED);

            if ($wasReceived && isset($input['warehouse_id']) && (int) $input['warehouse_id'] !== (int) $purchase->warehouse_id) {
                throw new UnprocessableEntityHttpException('Changing warehouse on a received purchase is not supported; reverse and create a new purchase instead.');
            }

            $effectiveWarehouseId = (int) ($input['warehouse_id'] ?? $purchase->warehouse_id);

            // If transitioning RECEIVED -> non-RECEIVED, reverse all previously received items first
            if ($wasReceived && ! $willBeReceived) {
                $pairs = [];
                foreach ($purchase->purchaseItems as $existingItem) {
                    $pairs[] = ['warehouse_id' => (int) $purchase->warehouse_id, 'product_id' => (int) $existingItem->product_id];
                }
                $stockService->lockStockRows($pairs);

                foreach ($purchase->purchaseItems as $existingItem) {
                    $stockService->reverse(
                        (int) $purchase->warehouse_id,
                        (int) $existingItem->product_id,
                        -(float) $existingItem->quantity,
                        'purchase_status_reversal',
                        Purchase::class,
                        (int) $purchase->id,
                        isset($existingItem->product_cost) ? (float) $existingItem->product_cost : null,
                        'Purchase reverted from RECEIVED (' . ($purchase->reference_code ?? ('#' . $purchase->id)) . ')',
                        'Quantity must be less than Available quantity.'
                    );
                }
            }

            // Should individual item edits mutate stock right now? Only when remaining RECEIVED -> RECEIVED.
            $mutateStockDuringItemEdits = ($wasReceived && $willBeReceived);

            $purchaseItemIds = $purchase->purchaseItems()->pluck('id')->toArray();
            $purchaseItmOldIds = [];
            foreach ($input['purchase_items'] as $key => $purchaseItem) {
                $purchaseItmOldIds[$key] = $purchaseItem['purchase_item_id'] ?? null;
                $purchaseItemArr = Arr::only($purchaseItem, [
                    'purchase_item_id',
                    'product_id',
                    'product_cost',
                    'net_unit_cost',
                    'tax_type',
                    'tax_value',
                    'tax_amount',
                    'discount_type',
                    'discount_value',
                    'discount_amount',
                    'purchase_unit',
                    'quantity',
                    'sub_total',
                ]);
                if (! is_null($purchaseItemArr['purchase_item_id'] ?? null)) {
                    $this->updateItem($purchaseItemArr, $effectiveWarehouseId, $purchase, $mutateStockDuringItemEdits);
                }
                if (is_null($purchaseItemArr['purchase_item_id'] ?? null)) {
                    $purchaseItem = $this->calculationPurchaseItems($purchaseItem);
                    $purchaseItemArr = Arr::only($purchaseItem, [
                        'purchase_item_id',
                        'product_id',
                        'product_cost',
                        'net_unit_cost',
                        'tax_type',
                        'tax_value',
                        'tax_amount',
                        'discount_type',
                        'discount_value',
                        'discount_amount',
                        'purchase_unit',
                        'quantity',
                        'sub_total',
                    ]);
                    $purchase->purchaseItems()->create($purchaseItemArr);
                    if ($mutateStockDuringItemEdits) {
                        $stockService->increase(
                            $effectiveWarehouseId,
                            (int) $purchaseItem['product_id'],
                            (float) $purchaseItem['quantity'],
                            'purchase_update_add',
                            Purchase::class,
                            (int) $purchase->id,
                            isset($purchaseItem['product_cost']) ? (float) $purchaseItem['product_cost'] : null
                        );
                    }
                }
            }

            $removeItemIds = array_diff($purchaseItemIds, $purchaseItmOldIds);
            if (! empty(array_values($removeItemIds))) {
                if ($mutateStockDuringItemEdits) {
                    foreach ($removeItemIds as $removeItemId) {
                        $oldProduct = $purchase->purchaseItems()->whereKey($removeItemId)->first();
                        if (! $oldProduct) {
                            continue;
                        }
                        $stockService->reverse(
                            $effectiveWarehouseId,
                            (int) $oldProduct->product_id,
                            -(float) $oldProduct->quantity,
                            'purchase_update_reversal',
                            Purchase::class,
                            (int) $purchase->id,
                            isset($oldProduct->product_cost) ? (float) $oldProduct->product_cost : null,
                            'Purchase item removed (' . ($purchase->reference_code ?? ('#' . $purchase->id)) . ')',
                            'Quantity must be less than Available quantity.'
                        );
                    }
                }
                $purchase->purchaseItems()->whereIn('id', array_values($removeItemIds))->delete();
            }

            $purchase = $this->updatePurchaseCalculation($input, $id);

            // If transitioning PENDING/ORDERED -> RECEIVED, credit stock ONCE for all current items
            if (! $wasReceived && $willBeReceived) {
                $purchase->load('purchaseItems');
                $pairs = [];
                foreach ($purchase->purchaseItems as $item) {
                    $pairs[] = ['warehouse_id' => (int) $purchase->warehouse_id, 'product_id' => (int) $item->product_id];
                }
                $stockService->lockStockRows($pairs);

                foreach ($purchase->purchaseItems as $item) {
                    $stockService->increase(
                        (int) $purchase->warehouse_id,
                        (int) $item->product_id,
                        (float) $item->quantity,
                        'purchase',
                        Purchase::class,
                        (int) $purchase->id,
                        isset($item->product_cost) ? (float) $item->product_cost : null,
                        'Purchase received (' . ($purchase->reference_code ?? ('#' . $purchase->id)) . ')'
                    );
                }
            }

            DB::commit();

            return $purchase;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            DB::rollBack();
            throw $e;
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    /**
     * @return mixed
     */
    public function updatePurchaseCalculation($input, $id)
    {
        $purchase = Purchase::findOrFail($id);
        $subTotalAmount = $purchase->purchaseItems()->sum('sub_total');

        if ($input['discount'] > $subTotalAmount || $input['discount'] < 0) {
            throw new UnprocessableEntityHttpException('Discount amount should not be greater than total.');
        }
        $input['grand_total'] = $subTotalAmount - $input['discount'];
        if ($input['tax_rate'] > 100 || $input['tax_rate'] < 0) {
            throw new UnprocessableEntityHttpException('Please enter tax value between 0 to 100.');
        }
        $input['tax_amount'] = $input['grand_total'] * $input['tax_rate'] / 100;

        $input['grand_total'] += $input['tax_amount'];

        if ($input['shipping'] > $input['grand_total'] || $input['shipping'] < 0) {
            throw new UnprocessableEntityHttpException(__('messages.error.shipping_amount_not_be_greater'));
        }

        $input['grand_total'] += $input['shipping'];

        $purchaseInputArray = Arr::only($input, [
            'supplier_id',
            'warehouse_id',
            'date',
            'tax_rate',
            'tax_amount',
            'discount',
            'shipping',
            'grand_total',
            'received_amount',
            'paid_amount',
            'partial_amount',
            'payment_type',
            'notes',
            'status',
            'payment_status',
        ]);
        $purchase->update($purchaseInputArray);

        return $purchase;
    }

    public function updateItem($purchaseItem, $warehouseId, ?Purchase $purchase = null, bool $mutateStock = true): bool
    {
        try {
            $purchaseItem = $this->calculationPurchaseItems($purchaseItem);
            // SEC-A2: Resolve PurchaseItem strictly through the authorized parent Purchase
            $oldItem = $purchase
                ? $purchase->purchaseItems()->whereKey($purchaseItem['purchase_item_id'])->first()
                : PurchaseItem::whereKey($purchaseItem['purchase_item_id'])->whereHas('purchase')->first();
            if (! $oldItem) {
                throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Purchase item not found.');
            }
            if ($mutateStock) {
                /** @var \App\Services\StockMovementService $stockService */
                $stockService = app(\App\Services\StockMovementService::class);
                $newQty = $stockService->assertPositiveQuantity($purchaseItem['quantity'] ?? null, 'Please enter a quantity greater than zero.');
                $oldQty = (float) $oldItem->quantity;
                if ($oldQty != $newQty) {
                    $delta = $newQty - $oldQty;
                    $stockService->applyDelta(
                        (int) $warehouseId,
                        (int) $purchaseItem['product_id'],
                        $delta,
                        $delta > 0 ? 'purchase_update_add' : 'purchase_update_sub',
                        Purchase::class,
                        (int) ($purchase?->id ?? $oldItem->purchase_id),
                        isset($purchaseItem['product_cost']) ? (float) $purchaseItem['product_cost'] : null,
                        null,
                        'Quantity must be less than Available quantity.'
                    );
                }
            }

            unset($purchaseItem['purchase_item_id'], $purchaseItem['purchase_id']);
            $oldItem->update($purchaseItem);

            return true;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            throw $e;
        } catch (Exception $e) {
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }
}
