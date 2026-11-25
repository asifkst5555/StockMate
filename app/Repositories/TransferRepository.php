<?php

namespace App\Repositories;

use App\Models\ManageStock;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\Transfer;
use App\Models\TransferItem;
use App\Models\Warehouse;
use App\Services\StockMovementService;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Class TransferRepository
 */
class TransferRepository extends BaseRepository
{
    /**
     * @var array
     */
    protected $fieldSearchable = [
        'date',
        'tax_rate',
        'tax_amount',
        'discount',
        'shipping',
        'grand_total',
        'note',
        'created_at',
        'reference_code',
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
        'note',
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
        return Transfer::class;
    }

    /**
     * @return mixed
     */
    public function storeTransfer($input)
    {
        try {
            DB::beginTransaction();

            $fromWhId = (int) ($input['from_warehouse_id'] ?? 0);
            $toWhId = (int) ($input['to_warehouse_id'] ?? 0);
            if ($fromWhId <= 0 || $toWhId <= 0) {
                throw new UnprocessableEntityHttpException('Source and destination warehouses are required.');
            }
            if ($fromWhId === $toWhId) {
                throw new UnprocessableEntityHttpException('Source and destination warehouses cannot be the same.');
            }

            $fromWarehouse = Warehouse::whereKey($fromWhId)->first();
            $toWarehouse = Warehouse::whereKey($toWhId)->first();

            if (! $fromWarehouse || ! $toWarehouse) {
                throw new UnprocessableEntityHttpException('Source and destination warehouses must belong to the active store.');
            }
            if ((string) $fromWarehouse->tenant_id !== (string) $toWarehouse->tenant_id) {
                throw new UnprocessableEntityHttpException('Source and destination warehouses must belong to the same tenant.');
            }

            $status = $this->validateStatus($input['status'] ?? null);
            $transferItemsInput = $input['transfer_items'] ?? [];
            if (! is_array($transferItemsInput) || empty($transferItemsInput)) {
                throw new UnprocessableEntityHttpException('At least one transfer item is required.');
            }

            /** @var StockMovementService $stockService */
            $stockService = app(StockMovementService::class);
            $productsById = $this->validateAndLoadProducts($transferItemsInput, (string) $fromWarehouse->tenant_id, $stockService);

            $pairs = [];
            foreach ($transferItemsInput as $transferItem) {
                $pid = (int) $transferItem['product_id'];
                $pairs[] = ['warehouse_id' => $fromWhId, 'product_id' => $pid];
                $pairs[] = ['warehouse_id' => $toWhId, 'product_id' => $pid];
            }
            $stockService->lockStockRows($pairs);

            $transferInputArray = [
                'tenant_id' => $fromWarehouse->tenant_id,
                'from_warehouse_id' => $fromWhId,
                'to_warehouse_id' => $toWhId,
                'date' => $input['date'] ?? date('Y/m/d'),
                'tax_rate' => (float) ($input['tax_rate'] ?? 0),
                'tax_amount' => 0.0,
                'discount' => (float) ($input['discount'] ?? 0),
                'shipping' => (float) ($input['shipping'] ?? 0),
                'grand_total' => 0.0,
                'note' => $input['note'] ?? null,
                'status' => $status,
            ];

            /** @var Transfer $transfer */
            $transfer = Transfer::create($transferInputArray);
            $referenceCode = 'TR_111' . $transfer->id;
            $transfer->update(['reference_code' => $referenceCode]);

            $transfer = $this->storeTransferItems($transfer, $input, $productsById, $stockService);

            DB::commit();

            return $transfer;
        } catch (ModelNotFoundException|HttpExceptionInterface $e) {
            DB::rollBack();
            throw $e;
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    /**
     * @param  array<int, Product>  $productsById
     * @return mixed
     */
    public function storeTransferItems($transfer, $input, array $productsById = [], ?StockMovementService $stockService = null)
    {
        $stockService = $stockService ?: app(StockMovementService::class);
        $status = $this->validateStatus($transfer->status);

        foreach ($input['transfer_items'] as $transferItem) {
            $pid = (int) $transferItem['product_id'];
            $productModel = $productsById[$pid] ?? Product::withoutGlobalScope('tenant')->findOrFail($pid);
            $qty = $stockService->assertPositiveQuantity($transferItem['quantity'] ?? null, 'Transfer quantity must be greater than zero.');
            $target = $this->targetQuantitiesForStatus($status, $qty);

            $stockService->reconcileTransferItem(
                (int) $transfer->from_warehouse_id,
                (int) $transfer->to_warehouse_id,
                $pid,
                0.0,
                0.0,
                $target['source'],
                $target['dest'],
                (int) $transfer->id,
                (float) $productModel->product_cost,
                $transfer->reference_code
            );

            $item = $this->calculationTransferItems($transferItem, $productModel);
            $transferItemModel = new TransferItem($item);
            $transfer->transferItems()->save($transferItemModel);
        }

        return $this->applyServerHeaderTotals($transfer, $input);
    }

    /**
     * @return mixed
     */
    public function calculationTransferItems($transferItem, ?Product $product = null, ?TransferItem $existingItem = null)
    {
        $transferItem['tax_value'] = $transferItem['tax_value'] ?? 0;
        $transferItem['discount_value'] = $transferItem['discount_value'] ?? 0;

        $validator = Validator::make($transferItem, TransferItem::$rules);
        if ($validator->fails()) {
            throw new UnprocessableEntityHttpException($validator->errors()->first());
        }

        if (! $product) {
            $product = Product::whereKey($transferItem['product_id'] ?? null)->first();
            if (! $product) {
                throw new UnprocessableEntityHttpException('Selected transfer product does not belong to the active store.');
            }
        }

        $unitPrice = $this->resolveAuthoritativeProductPrice($product, $transferItem, $existingItem);
        $qty = (float) $transferItem['quantity'];
        $discountType = (int) $transferItem['discount_type'];
        $discountValue = (float) $transferItem['discount_value'];
        $taxType = (int) $transferItem['tax_type'];
        $taxValue = (float) $transferItem['tax_value'];

        $normalized = Arr::only($transferItem, [
            'transfer_item_id',
            'product_id',
            'tax_type',
            'discount_type',
        ]);
        $normalized['product_id'] = (int) $product->id;
        $normalized['quantity'] = $qty;
        $normalized['product_price'] = $unitPrice;
        $normalized['tax_type'] = $taxType;
        $normalized['tax_value'] = $taxValue;
        $normalized['discount_type'] = $discountType;
        $normalized['discount_value'] = $discountValue;

        // Discount calculation
        $perItemDiscountAmount = 0.0;
        $normalized['net_unit_price'] = $unitPrice;
        if ($discountType === Transfer::PERCENTAGE) {
            if ($discountValue <= 100.0 && $discountValue >= 0.0) {
                $normalized['discount_amount'] = ($discountValue * $unitPrice / 100.0) * $qty;
                $perItemDiscountAmount = $normalized['discount_amount'] / $qty;
                $normalized['net_unit_price'] -= $perItemDiscountAmount;
            } else {
                throw new UnprocessableEntityHttpException('Please enter discount value between 0 to 100.');
            }
        } elseif ($discountType === Transfer::FIXED) {
            if ($discountValue <= $unitPrice && $discountValue >= 0.0) {
                $normalized['discount_amount'] = $discountValue * $qty;
                $perItemDiscountAmount = $normalized['discount_amount'] / $qty;
                $normalized['net_unit_price'] -= $perItemDiscountAmount;
            } else {
                throw new UnprocessableEntityHttpException("Please enter  discount's value between product's price.");
            }
        } else {
            throw new UnprocessableEntityHttpException('Invalid discount type.');
        }

        // Tax calculation
        $perItemTaxAmount = 0.0;
        if ($taxValue <= 100.0 && $taxValue >= 0.0) {
            if ($taxType === Transfer::EXCLUSIVE) {
                $normalized['tax_amount'] = (($normalized['net_unit_price'] * $taxValue) / 100.0) * $qty;
                $perItemTaxAmount = $normalized['tax_amount'] / $qty;
            } elseif ($taxType === Transfer::INCLUSIVE) {
                $normalized['tax_amount'] = ($normalized['net_unit_price'] * $taxValue) / (100.0 + $taxValue) * $qty;
                $perItemTaxAmount = $normalized['tax_amount'] / $qty;
                $normalized['net_unit_price'] -= $perItemTaxAmount;
            } else {
                throw new UnprocessableEntityHttpException('Invalid tax type.');
            }
        } else {
            throw new UnprocessableEntityHttpException('Please enter tax value between 0 to 100 ');
        }

        $normalized['sub_total'] = ($normalized['net_unit_price'] + $perItemTaxAmount) * $qty;

        return $normalized;
    }

    /**
     * @return mixed
     */
    public function updateTransfer($input, $id)
    {
        try {
            DB::beginTransaction();

            /** @var Transfer $transfer */
            $transfer = Transfer::with('transferItems')->whereKey($id)->lockForUpdate()->firstOrFail();

            $fromWhId = (int) ($input['from_warehouse_id'] ?? $transfer->from_warehouse_id);
            $toWhId = (int) ($input['to_warehouse_id'] ?? $transfer->to_warehouse_id);
            if ($fromWhId === $toWhId) {
                throw new UnprocessableEntityHttpException('Source and destination warehouses cannot be the same.');
            }
            $fromWarehouse = Warehouse::whereKey($fromWhId)->first();
            $toWarehouse = Warehouse::whereKey($toWhId)->first();
            if (! $fromWarehouse || ! $toWarehouse) {
                throw new UnprocessableEntityHttpException('Source and destination warehouses must belong to the active store.');
            }
            if ((string) $fromWarehouse->tenant_id !== (string) $toWarehouse->tenant_id) {
                throw new UnprocessableEntityHttpException('Source and destination warehouses must belong to the same tenant.');
            }
            if ($fromWhId !== (int) $transfer->from_warehouse_id || $toWhId !== (int) $transfer->to_warehouse_id) {
                throw new UnprocessableEntityHttpException('Changing transfer warehouses on update is not supported; reverse and create a new transfer instead.');
            }

            $newStatus = $this->validateStatus($input['status'] ?? null);
            $transferItemsInput = $input['transfer_items'] ?? [];
            if (! is_array($transferItemsInput) || empty($transferItemsInput)) {
                throw new UnprocessableEntityHttpException('At least one transfer item is required.');
            }

            /** @var StockMovementService $stockService */
            $stockService = app(StockMovementService::class);
            $productsById = $this->validateAndLoadProducts($transferItemsInput, (string) $fromWarehouse->tenant_id, $stockService);

            $existingItemsById = $transfer->transferItems->keyBy('id');
            $seenChildIds = [];
            $keptItemIds = [];

            foreach ($transferItemsInput as $transferItem) {
                $rawItemId = $transferItem['transfer_item_id'] ?? null;
                if ($rawItemId === null || $rawItemId === '') {
                    continue;
                }
                if (! is_numeric($rawItemId) || (int) $rawItemId <= 0 || (string) (int) $rawItemId !== (string) $rawItemId) {
                    throw new UnprocessableEntityHttpException('Invalid transfer item ID.');
                }
                $childId = (int) $rawItemId;
                if (isset($seenChildIds[$childId])) {
                    throw new UnprocessableEntityHttpException('Duplicate transfer item IDs are not permitted.');
                }
                $seenChildIds[$childId] = true;

                /** @var TransferItem|null $existingItem */
                $existingItem = $existingItemsById->get($childId);
                if (! $existingItem) {
                    throw new UnprocessableEntityHttpException('Transfer item not found or does not belong to this transfer.');
                }
                if ((int) $transferItem['product_id'] !== (int) $existingItem->product_id) {
                    throw new UnprocessableEntityHttpException('Changing product on an existing transfer item is not supported; remove the line and add the new product instead.');
                }
                $keptItemIds[] = $childId;
            }

            // Deterministically lock ALL involved (warehouse_id, product_id) rows upfront: kept, added, AND removed
            $pairs = [];
            foreach ($transfer->transferItems as $existingItem) {
                $pid = (int) $existingItem->product_id;
                $pairs[] = ['warehouse_id' => $fromWhId, 'product_id' => $pid];
                $pairs[] = ['warehouse_id' => $toWhId, 'product_id' => $pid];
            }
            foreach ($transferItemsInput as $transferItem) {
                $pid = (int) $transferItem['product_id'];
                $pairs[] = ['warehouse_id' => $fromWhId, 'product_id' => $pid];
                $pairs[] = ['warehouse_id' => $toWhId, 'product_id' => $pid];
            }
            $stockService->lockStockRows($pairs);

            // Inspect recorded stock movements strictly under lock before any mutation
            $legacyStates = $this->inspectAndValidateLegacyState($transfer);

            $transferItemOldIds = $existingItemsById->keys()->map(fn ($v) => (int) $v)->all();
            $removeItemIds = array_values(array_diff($transferItemOldIds, $keptItemIds));

            // 1. Reverse removed items first (using actual recorded movement state)
            if (! empty($removeItemIds)) {
                foreach ($removeItemIds as $removeItemId) {
                    /** @var TransferItem $oldTransferItem */
                    $oldTransferItem = $existingItemsById->get($removeItemId);
                    $state = $legacyStates[$oldTransferItem->id];
                    $prod = $productsById[(int) $oldTransferItem->product_id]
                        ?? Product::withoutGlobalScope('tenant')->find((int) $oldTransferItem->product_id);

                    $stockService->reconcileTransferItem(
                        $fromWhId,
                        $toWhId,
                        (int) $oldTransferItem->product_id,
                        $state['source_active'],
                        $state['dest_active'],
                        0.0,
                        0.0,
                        (int) $transfer->id,
                        $prod ? (float) $prod->product_cost : null,
                        $transfer->reference_code
                    );
                }

                $transfer->transferItems()->whereIn('id', $removeItemIds)->delete();
            }

            // 2. Reconcile existing kept items
            foreach ($transferItemsInput as $transferItem) {
                $rawItemId = $transferItem['transfer_item_id'] ?? null;
                if ($rawItemId === null || $rawItemId === '') {
                    continue;
                }
                $childId = (int) $rawItemId;
                /** @var TransferItem $existingItem */
                $existingItem = $existingItemsById->get($childId);
                $pid = (int) $existingItem->product_id;
                $productModel = $productsById[$pid];
                $newQty = $stockService->assertPositiveQuantity($transferItem['quantity'] ?? null, 'Transfer quantity must be greater than zero.');
                $target = $this->targetQuantitiesForStatus($newStatus, $newQty);
                $state = $legacyStates[$existingItem->id];

                $calculated = $this->calculationTransferItems($transferItem, $productModel, $existingItem);

                $stockService->reconcileTransferItem(
                    $fromWhId,
                    $toWhId,
                    $pid,
                    $state['source_active'],
                    $state['dest_active'],
                    $target['source'],
                    $target['dest'],
                    (int) $transfer->id,
                    (float) $productModel->product_cost,
                    $transfer->reference_code
                );

                unset($calculated['transfer_item_id']);
                $existingItem->update($calculated);
            }

            // 3. Reconcile newly added items
            foreach ($transferItemsInput as $transferItem) {
                $rawItemId = $transferItem['transfer_item_id'] ?? null;
                if ($rawItemId !== null && $rawItemId !== '') {
                    continue;
                }
                $pid = (int) $transferItem['product_id'];
                $productModel = $productsById[$pid];
                $newQty = $stockService->assertPositiveQuantity($transferItem['quantity'] ?? null, 'Transfer quantity must be greater than zero.');
                $target = $this->targetQuantitiesForStatus($newStatus, $newQty);

                $calculated = $this->calculationTransferItems($transferItem, $productModel);

                $stockService->reconcileTransferItem(
                    $fromWhId,
                    $toWhId,
                    $pid,
                    0.0,
                    0.0,
                    $target['source'],
                    $target['dest'],
                    (int) $transfer->id,
                    (float) $productModel->product_cost,
                    $transfer->reference_code
                );

                unset($calculated['transfer_item_id']);
                $transferItemModel = new TransferItem($calculated);
                $transfer->transferItems()->save($transferItemModel);
            }

            $transfer = $this->updateTransferCalculation($input, $transfer);

            DB::commit();

            return $transfer;
        } catch (ModelNotFoundException|HttpExceptionInterface $e) {
            DB::rollBack();
            throw $e;
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    /**
     * Inspect actual recorded StockMovements for a Transfer and validate legacy consistency.
     * Fails closed with HTTP 422 (naming exact transfer ID) if movement history is ambiguous.
     *
     * @return array<int, array{source_active: float, dest_active: float, item: TransferItem}>
     */
    public function inspectAndValidateLegacyState(Transfer $transfer): array
    {
        $transfer->loadMissing('transferItems');
        $fromWhId = (int) $transfer->from_warehouse_id;
        $toWhId = (int) $transfer->to_warehouse_id;
        $itemsByProduct = [];
        foreach ($transfer->transferItems as $item) {
            $pid = (int) $item->product_id;
            if (isset($itemsByProduct[$pid])) {
                throw new UnprocessableEntityHttpException(
                    "Ambiguous transfer items for transfer #{$transfer->id}: duplicate product #{$pid}."
                );
            }
            $itemsByProduct[$pid] = $item;
        }

        $movements = StockMovement::withoutGlobalScope('tenant')
            ->where('reference_type', Transfer::class)
            ->where('reference_id', $transfer->id)
            ->lockForUpdate()
            ->get();

        $allowedTypes = ['transfer_out', 'transfer_in', 'transfer_reversal_out', 'transfer_reversal_in'];
        $netByWhProduct = [];

        foreach ($movements as $mov) {
            if (! in_array($mov->type, $allowedTypes, true)) {
                throw new UnprocessableEntityHttpException(
                    "Ambiguous legacy stock movement history for transfer #{$transfer->id}: unexpected movement type '{$mov->type}'."
                );
            }
            $mWhId = (int) $mov->warehouse_id;
            $mProdId = (int) $mov->product_id;
            if ($mWhId !== $fromWhId && $mWhId !== $toWhId) {
                throw new UnprocessableEntityHttpException(
                    "Ambiguous legacy stock movement history for transfer #{$transfer->id}: movement warehouse #{$mWhId} does not match transfer warehouses."
                );
            }
            if (! isset($itemsByProduct[$mProdId])) {
                throw new UnprocessableEntityHttpException(
                    "Ambiguous legacy stock movement history for transfer #{$transfer->id}: orphan movement for product #{$mProdId}."
                );
            }
            $key = "{$mWhId}:{$mProdId}";
            $netByWhProduct[$key] = ($netByWhProduct[$key] ?? 0.0) + (float) $mov->quantity_change;
        }

        $eps = 1e-6;
        $states = [];
        $anyMovementRecorded = false;

        foreach ($transfer->transferItems as $item) {
            $pid = (int) $item->product_id;
            $qty = (float) $item->quantity;
            $netSourceDelta = $netByWhProduct["{$fromWhId}:{$pid}"] ?? 0.0;
            $netDestDelta = $netByWhProduct["{$toWhId}:{$pid}"] ?? 0.0;

            if (abs($netSourceDelta) <= $eps) {
                $sourceActive = 0.0;
            } elseif (abs($netSourceDelta + $qty) <= $eps) {
                $sourceActive = $qty;
                $anyMovementRecorded = true;
            } else {
                throw new UnprocessableEntityHttpException(
                    "Ambiguous legacy stock movement history for transfer #{$transfer->id} (product #{$pid})."
                );
            }

            if (abs($netDestDelta) <= $eps) {
                $destActive = 0.0;
            } elseif (abs($netDestDelta - $qty) <= $eps) {
                $destActive = $qty;
                $anyMovementRecorded = true;
            } else {
                throw new UnprocessableEntityHttpException(
                    "Ambiguous legacy stock movement history for transfer #{$transfer->id} (product #{$pid})."
                );
            }

            if ($destActive > $eps && $sourceActive <= $eps) {
                throw new UnprocessableEntityHttpException(
                    "Ambiguous legacy stock movement history for transfer #{$transfer->id}: destination stock credited without source deduction."
                );
            }

            $states[$item->id] = [
                'source_active' => $sourceActive,
                'dest_active' => $destActive,
                'item' => $item,
            ];
        }

        if ($transfer->transferItems->isNotEmpty()
            && in_array((int) $transfer->status, [Transfer::SENT, Transfer::COMPLETED], true)
            && ! $anyMovementRecorded
        ) {
            throw new UnprocessableEntityHttpException(
                "Ambiguous legacy stock movement history for transfer #{$transfer->id}: non-pending transfer has no recorded stock movements."
            );
        }

        return $states;
    }

    /**
     * @param  Transfer|int  $transferOrId
     */
    public function updateTransferCalculation($input, $transferOrId)
    {
        $transfer = $transferOrId instanceof Transfer
            ? $transferOrId
            : Transfer::findOrFail($transferOrId);

        return $this->applyServerHeaderTotals($transfer, $input);
    }

    private function applyServerHeaderTotals(Transfer $transfer, array $input): Transfer
    {
        $subTotalAmount = (float) $transfer->transferItems()->sum('sub_total');
        $discount = (float) ($input['discount'] ?? 0);
        $taxRate = (float) ($input['tax_rate'] ?? 0);
        $shipping = (float) ($input['shipping'] ?? 0);

        if ($discount < 0.0 || $discount > $subTotalAmount) {
            throw new UnprocessableEntityHttpException('Discount amount should not be greater than total.');
        }

        $afterDiscount = $subTotalAmount - $discount;

        if ($taxRate < 0.0 || $taxRate > 100.0) {
            throw new UnprocessableEntityHttpException('Please enter tax value between 0 to 100.');
        }

        $taxAmount = $afterDiscount * $taxRate / 100.0;
        $preShippingTotal = $afterDiscount + $taxAmount;

        if ($shipping < 0.0 || $shipping > $preShippingTotal) {
            throw new UnprocessableEntityHttpException(__('messages.error.shipping_amount_not_be_greater'));
        }

        $grandTotal = $preShippingTotal + $shipping;
        $status = $this->validateStatus($input['status'] ?? $transfer->status);

        $transfer->update([
            'date' => $input['date'] ?? $transfer->date,
            'note' => array_key_exists('note', $input) ? $input['note'] : $transfer->note,
            'status' => $status,
            'discount' => $discount,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'shipping' => $shipping,
            'grand_total' => $grandTotal,
        ]);

        $transfer->load('transferItems', 'fromWarehouse', 'toWarehouse');

        return $transfer;
    }

    private function validateStatus($status): int
    {
        if (! is_numeric($status)) {
            throw new UnprocessableEntityHttpException('Invalid transfer status.');
        }
        $intStatus = (int) $status;
        if (! in_array($intStatus, [Transfer::COMPLETED, Transfer::SENT, Transfer::PENDING], true)) {
            throw new UnprocessableEntityHttpException('Invalid transfer status.');
        }

        return $intStatus;
    }

    /**
     * @return array{source: float, dest: float}
     */
    private function targetQuantitiesForStatus(int $status, float $qty): array
    {
        return match ($status) {
            Transfer::PENDING => ['source' => 0.0, 'dest' => 0.0],
            Transfer::SENT => ['source' => $qty, 'dest' => 0.0],
            Transfer::COMPLETED => ['source' => $qty, 'dest' => $qty],
            default => throw new UnprocessableEntityHttpException('Invalid transfer status.'),
        };
    }

    /**
     * @return array<int, Product>
     */
    private function validateAndLoadProducts(array $transferItemsInput, string $tenantId, StockMovementService $stockService): array
    {
        $productsById = [];
        $seenProductIds = [];

        foreach ($transferItemsInput as $transferItem) {
            $stockService->assertPositiveQuantity($transferItem['quantity'] ?? null, 'Transfer quantity must be greater than zero.');
            $pid = (int) ($transferItem['product_id'] ?? 0);
            if ($pid <= 0) {
                throw new UnprocessableEntityHttpException('Selected transfer product does not belong to the active store.');
            }
            if (isset($seenProductIds[$pid])) {
                throw new UnprocessableEntityHttpException('Duplicate product lines are not permitted in a transfer.');
            }
            $seenProductIds[$pid] = true;

            $productModel = Product::whereKey($pid)->first();
            if (! $productModel || (string) $productModel->tenant_id !== $tenantId) {
                throw new UnprocessableEntityHttpException('Selected transfer product does not belong to the active store.');
            }

            $submittedUnit = $transferItem['purchase_unit'] ?? ($transferItem['sale_unit'] ?? null);
            $stockService->assertSupportedUnitParity($productModel, $submittedUnit, 'purchase');

            $productsById[$pid] = $productModel;
        }

        return $productsById;
    }

    private function resolveAuthoritativeProductPrice(Product $product, array $transferItem, ?TransferItem $existingItem = null): float
    {
        $baselineCost = (float) ($product->product_cost ?? $product->product_price ?? 0);
        $hasSubmitted = array_key_exists('product_price', $transferItem)
            && $transferItem['product_price'] !== null
            && $transferItem['product_price'] !== '';

        if (! $hasSubmitted) {
            return $existingItem ? (float) $existingItem->product_price : $baselineCost;
        }

        if (! is_numeric($transferItem['product_price'])) {
            throw new UnprocessableEntityHttpException('Product price must be a valid number.');
        }

        $submittedPrice = (float) $transferItem['product_price'];
        if ($submittedPrice < 0.0) {
            throw new UnprocessableEntityHttpException('Product price cannot be negative.');
        }

        if (abs($submittedPrice - $baselineCost) <= 0.0001) {
            return $submittedPrice;
        }

        if ($existingItem && abs($submittedPrice - (float) $existingItem->product_price) <= 0.0001) {
            return $submittedPrice;
        }

        if (! $this->canOverrideTransferPrice()) {
            throw new UnprocessableEntityHttpException('Unauthorized transfer product price override.');
        }

        return $submittedPrice;
    }

    private function canOverrideTransferPrice(): bool
    {
        $user = Auth::user();
        if (! $user) {
            return false;
        }

        return $user->hasRole([Role::SUPER_ADMIN, Role::ADMIN]) || $user->can('manage_products');
    }
}
