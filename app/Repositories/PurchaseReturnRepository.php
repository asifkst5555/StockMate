<?php

namespace App\Repositories;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Services\StockMovementService;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Class PurchaseReturnRepository
 */
class PurchaseReturnRepository extends BaseRepository
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
        return PurchaseReturn::class;
    }

    /**
     * Validate and prepare all Purchase Return items, unit parity, pricing bounds,
     * shared linked+unlinked supplier/warehouse cumulative quota, and deterministic stock lock pairs.
     *
     * S1 Lock Order:
     * 1. Matching RECEIVED Purchases for (supplier_id, warehouse_id) + optional linked Purchase ID (sorted by ID ASC)
     * 2. Relevant PurchaseReturn rows (ordered by ID ASC)
     * 3. ManageStock rows (sorted by warehouse_id, product_id via StockMovementService::lockStockRows)
     *
     * @return array{
     *     linked_purchase: ?Purchase,
     *     items: array<int, array<string, mixed>>,
     *     pairs: array<int, array{warehouse_id: int, product_id: int}>,
     *     order: array<string, float>
     * }
     */
    private function prepareAndValidatePurchaseReturn(
        array $input,
        ?PurchaseReturn $existingReturn,
        StockMovementService $stockService
    ): array {
        $items = $input['purchase_return_items'] ?? [];
        if (empty($items) || ! is_array($items)) {
            throw new UnprocessableEntityHttpException('At least one purchase return item is required.');
        }

        $supplierId = (int) ($input['supplier_id'] ?? 0);
        $warehouseId = (int) ($input['warehouse_id'] ?? 0);
        $purchaseId = ! empty($input['purchase_id']) ? (int) $input['purchase_id'] : null;

        // Deterministic S1 lock on all matching RECEIVED supplier+warehouse purchases (plus explicit purchase_id if given)
        $lockIds = Purchase::where('supplier_id', $supplierId)
            ->where('warehouse_id', $warehouseId)
            ->where('status', Purchase::RECEIVED)
            ->pluck('id')
            ->all();
        if ($purchaseId !== null) {
            $lockIds[] = $purchaseId;
        }
        $lockIds = array_values(array_unique(array_map('intval', $lockIds)));
        sort($lockIds);

        $lockedPurchases = ! empty($lockIds)
            ? Purchase::whereIn('id', $lockIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id')
            : collect();

        $linkedPurchase = null;
        if ($purchaseId !== null) {
            $linkedPurchase = $lockedPurchases->get($purchaseId);
            if (! $linkedPurchase) {
                throw new UnprocessableEntityHttpException('Purchase Does Not exist');
            }
            if ((int) $linkedPurchase->status !== Purchase::RECEIVED) {
                throw new UnprocessableEntityHttpException('Cannot return items from a purchase that has not been received.');
            }
            if ((int) $linkedPurchase->supplier_id !== $supplierId || (int) $linkedPurchase->warehouse_id !== $warehouseId) {
                throw new UnprocessableEntityHttpException('Purchase return supplier and warehouse must match the original purchase.');
            }
        }

        $eligiblePurchases = $lockedPurchases->filter(static function (Purchase $p) use ($supplierId, $warehouseId): bool {
            return (int) $p->supplier_id === $supplierId
                && (int) $p->warehouse_id === $warehouseId
                && (int) $p->status === Purchase::RECEIVED;
        });

        if ($eligiblePurchases->isEmpty()) {
            throw new UnprocessableEntityHttpException('Purchase Does Not exist');
        }

        $eligiblePurchaseIds = $eligiblePurchases->keys()->all();

        // Lock all PurchaseReturn rows for this supplier+warehouse (linked & unlinked share one quota per D2)
        $poolReturnsQuery = PurchaseReturn::where('supplier_id', $supplierId)
            ->where('warehouse_id', $warehouseId);
        if ($existingReturn) {
            $poolReturnsQuery->where('id', '!=', $existingReturn->id);
        }
        $poolOtherReturns = $poolReturnsQuery->orderBy('id')->lockForUpdate()->get();
        $poolOtherReturnIds = $poolOtherReturns->pluck('id')->all();
        $linkedOtherReturns = $linkedPurchase
            ? $poolOtherReturns->filter(static fn (PurchaseReturn $r): bool => (int) $r->purchase_id === (int) $linkedPurchase->id)
            : collect();
        $linkedOtherReturnIds = $linkedOtherReturns->pluck('id')->all();

        $allPurchaseItems = PurchaseItem::whereIn('purchase_id', $eligiblePurchaseIds)->get();
        $purchaseItemsByPurchase = $allPurchaseItems->groupBy('purchase_id');

        // Track linked returns per (purchase_id, product_id) so exhausted purchases cannot mask lower-cost remaining lots
        $linkedReturnIds = $poolOtherReturns->whereNotNull('purchase_id')->pluck('id')->all();
        $linkedQtyRemainingByPurchaseProduct = [];
        if (! empty($linkedReturnIds)) {
            $linkedItems = PurchaseReturnItem::whereIn('purchase_return_id', $linkedReturnIds)
                ->get(['purchase_return_id', 'product_id', 'quantity']);
            $purchaseByReturnId = $poolOtherReturns->pluck('purchase_id', 'id');
            foreach ($linkedItems as $li) {
                $pIdForReturn = (int) ($purchaseByReturnId->get($li->purchase_return_id) ?? 0);
                $prodIdForReturn = (int) $li->product_id;
                if ($pIdForReturn > 0 && $prodIdForReturn > 0) {
                    $key = "{$pIdForReturn}:{$prodIdForReturn}";
                    $linkedQtyRemainingByPurchaseProduct[$key] = ($linkedQtyRemainingByPurchaseProduct[$key] ?? 0.0) + (float) $li->quantity;
                }
            }
        }

        $poolMetaByProduct = [];
        $poolOrigGrandTotal = 0.0;
        $poolOrigTotalNetWithTax = 0.0;

        foreach ($eligiblePurchases as $ep) {
            $poolOrigGrandTotal += max(0.0, (float) ($ep->grand_total ?? 0));
            $epItems = $purchaseItemsByPurchase->get($ep->id, collect());
            $epSubTotal = (float) $epItems->sum('sub_total');
            $epNetWithTax = $epSubTotal > 0.0
                ? max(0.0, ($epSubTotal - max(0.0, (float) ($ep->discount ?? 0))) + max(0.0, (float) ($ep->tax_amount ?? 0)))
                : 0.0;
            $poolOrigTotalNetWithTax += $epNetWithTax;
            $epOrderFactor = $epSubTotal > 0.0 ? ($epNetWithTax / $epSubTotal) : 1.0;

            foreach ($epItems as $epItem) {
                $pid = (int) $epItem->product_id;
                $qty = (float) ($epItem->quantity ?? 0);
                $cost = (float) ($epItem->product_cost ?? 0);
                $sub = (float) ($epItem->sub_total ?? 0);
                $rawUnit = $epItem->getRawOriginal('purchase_unit');
                $effUnitSub = $qty > 0.0 ? ($sub / $qty) : 0.0;
                $effNetWithTaxPerUnit = $effUnitSub * $epOrderFactor;

                // Deduct prior linked returns attributed to this exact purchase before marking lot as unexhausted
                $linkedKey = "{$ep->id}:{$pid}";
                $linkedToConsume = min($qty, $linkedQtyRemainingByPurchaseProduct[$linkedKey] ?? 0.0);
                $linkedQtyRemainingByPurchaseProduct[$linkedKey] = max(0.0, ($linkedQtyRemainingByPurchaseProduct[$linkedKey] ?? 0.0) - $linkedToConsume);
                $unlinkedAvailableQtyInLine = max(0.0, $qty - $linkedToConsume);

                if (! isset($poolMetaByProduct[$pid])) {
                    $poolMetaByProduct[$pid] = [
                        'purchased_qty' => 0.0,
                        'max_unit_cost' => 0.0,
                        'orig_sub_total' => 0.0,
                        'max_effective_unit_sub' => 0.0,
                        'max_effective_net_with_tax_unit' => 0.0,
                        'unexhausted_distinct_costs' => [],
                        'min_unexhausted_cost' => null,
                        'max_unexhausted_cost' => null,
                        'min_unexhausted_effective_unit_sub' => null,
                        'min_unexhausted_net_with_tax_unit' => null,
                        'max_unexhausted_effective_unit_sub' => 0.0,
                        'max_unexhausted_net_with_tax_unit' => 0.0,
                        'raw_units' => [],
                    ];
                }
                $poolMetaByProduct[$pid]['purchased_qty'] += $qty;
                $poolMetaByProduct[$pid]['max_unit_cost'] = max($poolMetaByProduct[$pid]['max_unit_cost'], $cost);
                $poolMetaByProduct[$pid]['orig_sub_total'] += $sub;
                $poolMetaByProduct[$pid]['max_effective_unit_sub'] = max($poolMetaByProduct[$pid]['max_effective_unit_sub'], $effUnitSub);
                $poolMetaByProduct[$pid]['max_effective_net_with_tax_unit'] = max($poolMetaByProduct[$pid]['max_effective_net_with_tax_unit'], $effNetWithTaxPerUnit);
                if ($unlinkedAvailableQtyInLine > 1e-9) {
                    $costKey = number_format($cost, 4, '.', '');
                    $poolMetaByProduct[$pid]['unexhausted_distinct_costs'][$costKey] = true;
                    $poolMetaByProduct[$pid]['min_unexhausted_cost'] = $poolMetaByProduct[$pid]['min_unexhausted_cost'] === null
                        ? $cost
                        : min($poolMetaByProduct[$pid]['min_unexhausted_cost'], $cost);
                    $poolMetaByProduct[$pid]['max_unexhausted_cost'] = $poolMetaByProduct[$pid]['max_unexhausted_cost'] === null
                        ? $cost
                        : max($poolMetaByProduct[$pid]['max_unexhausted_cost'], $cost);
                    $poolMetaByProduct[$pid]['min_unexhausted_effective_unit_sub'] = $poolMetaByProduct[$pid]['min_unexhausted_effective_unit_sub'] === null
                        ? $effUnitSub
                        : min($poolMetaByProduct[$pid]['min_unexhausted_effective_unit_sub'], $effUnitSub);
                    $poolMetaByProduct[$pid]['min_unexhausted_net_with_tax_unit'] = $poolMetaByProduct[$pid]['min_unexhausted_net_with_tax_unit'] === null
                        ? $effNetWithTaxPerUnit
                        : min($poolMetaByProduct[$pid]['min_unexhausted_net_with_tax_unit'], $effNetWithTaxPerUnit);
                    $poolMetaByProduct[$pid]['max_unexhausted_effective_unit_sub'] = max(
                        $poolMetaByProduct[$pid]['max_unexhausted_effective_unit_sub'],
                        $effUnitSub
                    );
                    $poolMetaByProduct[$pid]['max_unexhausted_net_with_tax_unit'] = max(
                        $poolMetaByProduct[$pid]['max_unexhausted_net_with_tax_unit'],
                        $effNetWithTaxPerUnit
                    );
                }
                if (! empty($rawUnit) && is_numeric($rawUnit)) {
                    $poolMetaByProduct[$pid]['raw_units'][] = (int) $rawUnit;
                }
            }
        }
        foreach ($poolMetaByProduct as &$pm) {
            $pm['raw_units'] = array_values(array_unique($pm['raw_units']));
        }
        unset($pm);

        $linkedMetaByProduct = [];
        $linkedItemsTotalSubTotal = 0.0;
        if ($linkedPurchase) {
            foreach ($purchaseItemsByPurchase->get($linkedPurchase->id, collect()) as $origItem) {
                $pid = (int) $origItem->product_id;
                $qty = (float) ($origItem->quantity ?? 0);
                $cost = (float) ($origItem->product_cost ?? 0);
                $sub = (float) ($origItem->sub_total ?? 0);
                $rawUnit = $origItem->getRawOriginal('purchase_unit');

                if (! isset($linkedMetaByProduct[$pid])) {
                    $linkedMetaByProduct[$pid] = [
                        'purchased_qty' => 0.0,
                        'max_unit_cost' => 0.0,
                        'orig_sub_total' => 0.0,
                        'raw_units' => [],
                    ];
                }
                $linkedMetaByProduct[$pid]['purchased_qty'] += $qty;
                $linkedMetaByProduct[$pid]['max_unit_cost'] = max($linkedMetaByProduct[$pid]['max_unit_cost'], $cost);
                $linkedMetaByProduct[$pid]['orig_sub_total'] += $sub;
                if (! empty($rawUnit) && is_numeric($rawUnit)) {
                    $linkedMetaByProduct[$pid]['raw_units'][] = (int) $rawUnit;
                }
                $linkedItemsTotalSubTotal += $sub;
            }
            foreach ($linkedMetaByProduct as &$lm) {
                $lm['raw_units'] = array_values(array_unique($lm['raw_units']));
            }
            unset($lm);
        }

        $existingReturnItemsById = $existingReturn
            ? $existingReturn->purchaseReturnItems->keyBy('id')
            : collect();

        $seenEditItemIds = [];
        $preparedItems = [];
        $requestedQtyByProduct = [];
        $requestedSubTotalByProduct = [];
        $pairs = [];

        foreach ($items as $rawItem) {
            $productId = (int) ($rawItem['product_id'] ?? 0);
            if ($productId <= 0) {
                throw new UnprocessableEntityHttpException('Invalid product specified for purchase return.');
            }

            // Tenant-scoped Product verification (S4)
            $product = Product::whereKey($productId)->first();
            if (! $product) {
                throw new UnprocessableEntityHttpException('Selected product does not belong to the active store.');
            }

            if ($linkedPurchase && (! isset($linkedMetaByProduct[$productId]) || $linkedMetaByProduct[$productId]['purchased_qty'] <= 0.0)) {
                throw new UnprocessableEntityHttpException('Purchase Does Not exist');
            }
            if (! isset($poolMetaByProduct[$productId]) || $poolMetaByProduct[$productId]['purchased_qty'] <= 0.0) {
                throw new UnprocessableEntityHttpException('Purchase Does Not exist');
            }

            $refMeta = $linkedPurchase ? $linkedMetaByProduct[$productId] : $poolMetaByProduct[$productId];

            if (! is_null($rawItem['purchase_return_item_id'] ?? null)) {
                $editItemId = (int) $rawItem['purchase_return_item_id'];
                if (! $existingReturn || ! $existingReturnItemsById->has($editItemId)) {
                    throw new NotFoundHttpException('Purchase return item not found.');
                }
                if (isset($seenEditItemIds[$editItemId])) {
                    throw new UnprocessableEntityHttpException('Duplicate purchase_return_item_id in update payload.');
                }
                $seenEditItemIds[$editItemId] = true;

                $existingItem = $existingReturnItemsById->get($editItemId);
                if ((int) $existingItem->product_id !== $productId) {
                    throw new UnprocessableEntityHttpException('Cannot change product_id on an existing purchase return item.');
                }
            }

            $qty = $stockService->assertPositiveQuantity($rawItem['quantity'] ?? null, 'Return quantity must be greater than zero.');

            // Unit ownership & parity + original PurchaseItem unit validation (E)
            $submittedUnit = is_array($rawItem['purchase_unit'] ?? null)
                ? ($rawItem['purchase_unit']['id'] ?? $rawItem['purchase_unit']['value'] ?? null)
                : ($rawItem['purchase_unit'] ?? null);
            $stockService->assertSupportedUnitParity($product, $submittedUnit, 'purchase');

            if (! empty($submittedUnit) && is_numeric($submittedUnit) && ! empty($refMeta['raw_units'])) {
                if (! in_array((int) $submittedUnit, $refMeta['raw_units'], true)) {
                    throw new UnprocessableEntityHttpException('Return unit must match the original purchase item unit.');
                }
            }

            $resolvedUnit = (! empty($submittedUnit) && is_numeric($submittedUnit))
                ? (int) $submittedUnit
                : ($refMeta['raw_units'][0] ?? (is_numeric($product->purchase_unit) ? (int) $product->purchase_unit : 0));

            // Pricing bound D3/H + Microfix 3 conservative unlinked multi-cost safeguard:
            // For linked returns: bound to linkedPurchase item cost.
            // For standalone unlinked returns: only consider unexhausted purchases; if multiple distinct unit costs exist
            // across unexhausted lots, fail closed above minimum eligible cost so caller must link exact purchase_id.
            $hasSubmittedCost = isset($rawItem['product_cost']) && $rawItem['product_cost'] !== '' && $rawItem['product_cost'] !== null;
            if ($linkedPurchase) {
                $allowedUnitCostUpper = $refMeta['max_unit_cost'];
                $defaultUnitCost = round($allowedUnitCostUpper, 4);
            } else {
                if ($refMeta['min_unexhausted_cost'] === null) {
                    throw new UnprocessableEntityHttpException('Remaining purchased quantity to return is 0.');
                }
                $hasMultipleUnexhaustedCosts = count($refMeta['unexhausted_distinct_costs']) > 1;
                $allowedUnitCostUpper = $hasMultipleUnexhaustedCosts
                    ? $refMeta['min_unexhausted_cost']
                    : $refMeta['max_unexhausted_cost'];
                $defaultUnitCost = round($allowedUnitCostUpper, 4);
            }

            $submittedCost = $hasSubmittedCost ? (float) $rawItem['product_cost'] : $defaultUnitCost;

            if ($submittedCost < 0.0 || ! is_finite($submittedCost)) {
                throw new UnprocessableEntityHttpException('Return product cost cannot be negative.');
            }
            if ($submittedCost > ($allowedUnitCostUpper + 1e-6)) {
                $msg = (! $linkedPurchase && count($refMeta['unexhausted_distinct_costs']) > 1)
                    ? 'Multiple received purchases exist with different unit costs for this product; specify purchase_id to return above the minimum eligible unit cost.'
                    : 'Return unit cost cannot exceed the original purchase item unit cost.';
                throw new UnprocessableEntityHttpException($msg);
            }

            $normalizedItem = $rawItem;
            $normalizedItem['product_id'] = $productId;
            $normalizedItem['product_cost'] = $submittedCost;
            $normalizedItem['quantity'] = $qty;
            $normalizedItem['purchase_unit'] = $resolvedUnit;
            $normalizedItem['discount_type'] = $rawItem['discount_type'] ?? PurchaseReturn::FIXED;
            $normalizedItem['discount_value'] = $rawItem['discount_value'] ?? 0;
            $normalizedItem['tax_type'] = $rawItem['tax_type'] ?? PurchaseReturn::EXCLUSIVE;
            $normalizedItem['tax_value'] = $rawItem['tax_value'] ?? 0;

            $calculated = $this->calculationPurchaseReturnItems($normalizedItem);
            $preparedItems[] = $calculated;

            $requestedQtyByProduct[$productId] = ($requestedQtyByProduct[$productId] ?? 0.0) + $qty;
            $requestedSubTotalByProduct[$productId] = ($requestedSubTotalByProduct[$productId] ?? 0.0) + (float) $calculated['sub_total'];
            $pairs[] = ['warehouse_id' => $warehouseId, 'product_id' => $productId];
        }

        // Cumulative quantity & line refundable cap checks (BOTH linked per-purchase AND D2 shared pool)
        $linkedReturnedOrigMerchandiseShare = 0.0;
        $poolMaxNetWithTaxAllowed = 0.0;

        foreach ($requestedQtyByProduct as $productId => $requestedQty) {
            if ($linkedPurchase) {
                $lMeta = $linkedMetaByProduct[$productId];
                $linkedPriorQty = 0.0;
                $linkedPriorSub = 0.0;
                if (! empty($linkedOtherReturnIds)) {
                    $linkedPriorQty = (float) PurchaseReturnItem::whereIn('purchase_return_id', $linkedOtherReturnIds)
                        ->where('product_id', $productId)
                        ->sum('quantity');
                    $linkedPriorSub = (float) PurchaseReturnItem::whereIn('purchase_return_id', $linkedOtherReturnIds)
                        ->where('product_id', $productId)
                        ->sum('sub_total');
                }
                $linkedRemaining = max(0.0, round($lMeta['purchased_qty'] - $linkedPriorQty, 6));
                if ($requestedQty > ($linkedRemaining + 1e-9)) {
                    throw new UnprocessableEntityHttpException(
                        'Remaining purchased quantity to return is ' . $linkedRemaining . ' and you are trying to return ' . $requestedQty
                    );
                }

                $linkedOrigEffUnit = $lMeta['purchased_qty'] > 0.0 ? ($lMeta['orig_sub_total'] / $lMeta['purchased_qty']) : 0.0;
                $maxLinkedLineRefund = $linkedOrigEffUnit * $requestedQty;
                if ($requestedSubTotalByProduct[$productId] > ($maxLinkedLineRefund + 0.02)) {
                    throw new UnprocessableEntityHttpException(
                        'Purchase return line amount exceeds the original purchase line refundable amount after line discount and tax.'
                    );
                }
                if (($linkedPriorSub + $requestedSubTotalByProduct[$productId]) > ($lMeta['orig_sub_total'] + 0.02)) {
                    throw new UnprocessableEntityHttpException(
                        'Cumulative purchase return line amount exceeds the original purchase line total.'
                    );
                }
                $linkedReturnedOrigMerchandiseShare += $maxLinkedLineRefund;
            }

            // Shared supplier+warehouse pool quota check (applies to BOTH linked and unlinked per D2)
            $pMeta = $poolMetaByProduct[$productId];
            $poolPriorQty = 0.0;
            $poolPriorSub = 0.0;
            if (! empty($poolOtherReturnIds)) {
                $poolPriorQty = (float) PurchaseReturnItem::whereIn('purchase_return_id', $poolOtherReturnIds)
                    ->where('product_id', $productId)
                    ->sum('quantity');
                $poolPriorSub = (float) PurchaseReturnItem::whereIn('purchase_return_id', $poolOtherReturnIds)
                    ->where('product_id', $productId)
                    ->sum('sub_total');
            }
            $poolRemaining = max(0.0, round($pMeta['purchased_qty'] - $poolPriorQty, 6));
            if ($requestedQty > ($poolRemaining + 1e-9)) {
                throw new UnprocessableEntityHttpException(
                    'Remaining purchased quantity to return is ' . $poolRemaining . ' and you are trying to return ' . $requestedQty
                );
            }

            $effectiveUnitSubUpper = $linkedPurchase
                ? $pMeta['max_effective_unit_sub']
                : (count($pMeta['unexhausted_distinct_costs']) > 1
                    ? ($pMeta['min_unexhausted_effective_unit_sub'] ?? 0.0)
                    : $pMeta['max_unexhausted_effective_unit_sub']);

            if ($requestedSubTotalByProduct[$productId] > (($effectiveUnitSubUpper * $requestedQty) + 0.02)) {
                throw new UnprocessableEntityHttpException(
                    'Purchase return line amount exceeds original purchased line refundable amount.'
                );
            }
            if (($poolPriorSub + $requestedSubTotalByProduct[$productId]) > ($pMeta['orig_sub_total'] + 0.02)) {
                throw new UnprocessableEntityHttpException(
                    'Cumulative purchase return line amount exceeds total received purchase line value.'
                );
            }

            $effectiveNetWithTaxUnitUpper = $linkedPurchase
                ? $pMeta['max_effective_net_with_tax_unit']
                : (count($pMeta['unexhausted_distinct_costs']) > 1
                    ? ($pMeta['min_unexhausted_net_with_tax_unit'] ?? 0.0)
                    : $pMeta['max_unexhausted_net_with_tax_unit']);
            $poolMaxNetWithTaxAllowed += $effectiveNetWithTaxUnitUpper * $requestedQty;
        }

        // Server-side order totals calculation (S2)
        $subTotalAmount = array_reduce($preparedItems, static fn (float $carry, array $item): float => $carry + (float) $item['sub_total'], 0.0);
        $orderDiscount = (float) ($input['discount'] ?? 0);
        if ($orderDiscount < 0.0 || $orderDiscount > ($subTotalAmount + 1e-6)) {
            throw new UnprocessableEntityHttpException('Discount amount should not be greater than total.');
        }

        $afterDiscount = max(0.0, $subTotalAmount - $orderDiscount);
        $taxRate = (float) ($input['tax_rate'] ?? 0);
        if ($taxRate < 0.0 || $taxRate > 100.0) {
            throw new UnprocessableEntityHttpException('Please enter tax value between 0 to 100.');
        }

        $taxAmount = $afterDiscount * $taxRate / 100;
        $preShippingTotal = $afterDiscount + $taxAmount;

        $shipping = (float) ($input['shipping'] ?? 0);
        if ($shipping < 0.0 || $shipping > ($preShippingTotal + 1e-6)) {
            throw new UnprocessableEntityHttpException(__('messages.error.shipping_amount_not_be_greater'));
        }

        $grandTotal = $preShippingTotal + $shipping;

        if ($linkedPurchase) {
            $linkedPriorGrandTotal = (float) $linkedOtherReturns->sum('grand_total');
            $linkedOrigGrandTotal = max(0.0, (float) ($linkedPurchase->grand_total ?? 0));

            if ($linkedItemsTotalSubTotal > 0.0) {
                $linkedNetWithTax = max(
                    0.0,
                    ($linkedItemsTotalSubTotal - max(0.0, (float) ($linkedPurchase->discount ?? 0))) + max(0.0, (float) ($linkedPurchase->tax_amount ?? 0))
                );
                $maxLinkedProRata = $linkedNetWithTax * ($linkedReturnedOrigMerchandiseShare / $linkedItemsTotalSubTotal);

                if ($preShippingTotal > ($maxLinkedProRata + 0.02)) {
                    throw new UnprocessableEntityHttpException(
                        'Purchase return credit exceeds original purchase net refundable total (accounting for original discount and tax).'
                    );
                }
            }

            if (($linkedPriorGrandTotal + $grandTotal) > ($linkedOrigGrandTotal + 0.02)) {
                throw new UnprocessableEntityHttpException(
                    'Cumulative purchase return total cannot exceed original purchase grand total.'
                );
            }
        } else {
            if ($preShippingTotal > ($poolMaxNetWithTaxAllowed + 0.02)) {
                throw new UnprocessableEntityHttpException(
                    'Purchase return credit exceeds eligible received purchases net refundable total.'
                );
            }
        }

        $poolPriorNetBeforeShipping = (float) $poolOtherReturns->sum(
            static fn (PurchaseReturn $r): float => max(0.0, (float) ($r->grand_total ?? 0) - max(0.0, (float) ($r->shipping ?? 0)))
        );
        if (($poolPriorNetBeforeShipping + $preShippingTotal) > ($poolOrigTotalNetWithTax + 0.02)) {
            throw new UnprocessableEntityHttpException(
                'Cumulative supplier purchase return credit exceeds eligible received purchase values after discounts and taxes.'
            );
        }

        $poolPriorGrandTotal = (float) $poolOtherReturns->sum('grand_total');
        if (($poolPriorGrandTotal + $grandTotal) > ($poolOrigGrandTotal + 0.02)) {
            throw new UnprocessableEntityHttpException(
                'Cumulative supplier purchase return total cannot exceed total received purchase grand totals.'
            );
        }

        return [
            'linked_purchase' => $linkedPurchase,
            'items' => $preparedItems,
            'pairs' => $pairs,
            'order' => [
                'discount' => $orderDiscount,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'shipping' => $shipping,
                'grand_total' => $grandTotal,
            ],
        ];
    }

    /**
     * @return mixed
     */
    public function storePurchaseReturn($input)
    {
        try {
            DB::beginTransaction();

            /** @var StockMovementService $stockService */
            $stockService = app(StockMovementService::class);

            $input['notes'] = $input['notes'] ?? ($input['note'] ?? null);
            $status = (int) ($input['status'] ?? PurchaseReturn::COMPLETED);
            if (! in_array($status, [PurchaseReturn::COMPLETED, PurchaseReturn::PENDING], true)) {
                throw new UnprocessableEntityHttpException('Invalid purchase return status.');
            }
            $input['status'] = $status;

            $prepared = $this->prepareAndValidatePurchaseReturn($input, null, $stockService);
            $linkedPurchase = $prepared['linked_purchase'];

            $purchaseReturnInputArray = Arr::only($input, [
                'supplier_id',
                'warehouse_id',
                'date',
                'tax_rate',
                'discount',
                'shipping',
                'received_amount',
                'paid_amount',
                'payment_type',
                'notes',
                'status',
                'payment_status',
            ]);
            $purchaseReturnInputArray['tax_rate'] = $prepared['order']['tax_rate'];
            $purchaseReturnInputArray['tax_amount'] = $prepared['order']['tax_amount'];
            $purchaseReturnInputArray['discount'] = $prepared['order']['discount'];
            $purchaseReturnInputArray['shipping'] = $prepared['order']['shipping'];
            $purchaseReturnInputArray['grand_total'] = $prepared['order']['grand_total'];

            if ((int) ($input['payment_status'] ?? PurchaseReturn::UNPAID) === PurchaseReturn::PAID) {
                $purchaseReturnInputArray['received_amount'] = $prepared['order']['grand_total'];
            } else {
                $purchaseReturnInputArray['received_amount'] = isset($input['received_amount']) && is_numeric($input['received_amount'])
                    ? (float) $input['received_amount']
                    : 0.0;
            }

            if ($linkedPurchase) {
                $purchaseReturnInputArray['purchase_id'] = $linkedPurchase->id;
            }

            $purchaseReturn = PurchaseReturn::create($purchaseReturnInputArray);
            if ($linkedPurchase) {
                $linkedPurchase->update(['is_return' => 1]);
            }

            foreach ($prepared['items'] as $preparedItem) {
                $itemRow = Arr::only($preparedItem, [
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
                $purchaseReturn->purchaseReturnItems()->create($itemRow);
            }

            $purchaseReturn->update([
                'reference_code' => getSettingValue('purchase_return_code') . '_111' . $purchaseReturn->id,
            ]);

            // D1: Only COMPLETED purchase returns decrease stock; PENDING returns reserve quota only
            if ($status === PurchaseReturn::COMPLETED) {
                $stockService->lockStockRows($prepared['pairs']);

                foreach ($prepared['items'] as $preparedItem) {
                    $stockService->decrease(
                        (int) $purchaseReturn->warehouse_id,
                        (int) $preparedItem['product_id'],
                        (float) $preparedItem['quantity'],
                        'purchase_return',
                        PurchaseReturn::class,
                        (int) $purchaseReturn->id,
                        isset($preparedItem['product_cost']) ? (float) $preparedItem['product_cost'] : null
                    );
                }
            }

            DB::commit();

            return $purchaseReturn->load('purchaseReturnItems');
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
    public function storePurchaseReturnItems($purchaseReturn, $input)
    {
        foreach ($input['purchase_return_items'] as $purchaseReturnItem) {
            $items = $this->calculationPurchaseReturnItems($purchaseReturnItem);
            $purchaseReturnItem = new PurchaseReturnItem($items);
            $purchaseReturn->purchaseReturnItems()->save($purchaseReturnItem);
        }

        $subTotalAmount = $purchaseReturn->purchaseReturnItems()->sum('sub_total');
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

        if ($input['payment_status'] == PurchaseReturn::PAID) {
            $input['received_amount'] = $input['grand_total'];
        }

        $input['reference_code'] = getSettingValue('purchase_return_code').'_111'.$purchaseReturn->id;
        $purchaseReturn->update($input);

        return $purchaseReturn;
    }

    /**
     * @return mixed
     */
    public function calculationPurchaseReturnItems($purchaseReturnItem)
    {
        $validator = Validator::make($purchaseReturnItem, PurchaseReturnItem::$rules);
        if ($validator->fails()) {
            throw new UnprocessableEntityHttpException($validator->errors()->first());
        }

        //discount calculation
        $perItemDiscountAmount = 0;
        $purchaseReturnItem['net_unit_cost'] = $purchaseReturnItem['product_cost'];
        $discountType = (int) ($purchaseReturnItem['discount_type'] ?? PurchaseReturn::FIXED);
        $discountValue = (float) ($purchaseReturnItem['discount_value'] ?? 0);
        $taxType = (int) ($purchaseReturnItem['tax_type'] ?? PurchaseReturn::EXCLUSIVE);
        $taxValue = (float) ($purchaseReturnItem['tax_value'] ?? 0);

        if ($discountType == PurchaseReturn::PERCENTAGE) {
            if ($discountValue <= 100 && $discountValue >= 0) {
                $purchaseReturnItem['discount_amount'] = ($discountValue * $purchaseReturnItem['product_cost'] / 100) * $purchaseReturnItem['quantity'];
                if ($purchaseReturnItem['quantity'] == 0) {
                    $perItemDiscountAmount = 0;
                } else {
                    $perItemDiscountAmount = $purchaseReturnItem['discount_amount'] / $purchaseReturnItem['quantity'];
                }
                $purchaseReturnItem['net_unit_cost'] -= $perItemDiscountAmount;
            } else {
                throw new UnprocessableEntityHttpException('Please enter discount value between 0 to 100.');
            }
        } elseif ($discountType == PurchaseReturn::FIXED) {
            if ($discountValue <= $purchaseReturnItem['product_cost'] && $discountValue >= 0) {
                $purchaseReturnItem['discount_amount'] = $discountValue * $purchaseReturnItem['quantity'];
                if ($purchaseReturnItem['quantity'] == 0) {
                    $perItemDiscountAmount = 0;
                } else {
                    $perItemDiscountAmount = $purchaseReturnItem['discount_amount'] / $purchaseReturnItem['quantity'];
                }
                $purchaseReturnItem['net_unit_cost'] -= $perItemDiscountAmount;
            } else {
                throw new UnprocessableEntityHttpException("Please enter  discount's value between product's price.");
            }
        }
        //tax calculation
        $perItemTaxAmount = 0;
        if ($taxValue <= 100 && $taxValue >= 0) {
            if ($taxType == PurchaseReturn::EXCLUSIVE) {
                $purchaseReturnItem['tax_amount'] = (($purchaseReturnItem['net_unit_cost'] * $taxValue) / 100) * $purchaseReturnItem['quantity'];
                if ($purchaseReturnItem['quantity'] == 0) {
                    $perItemTaxAmount = 0;
                } else {
                    $perItemTaxAmount = $purchaseReturnItem['tax_amount'] / $purchaseReturnItem['quantity'];
                }
            } elseif ($taxType == PurchaseReturn::INCLUSIVE) {
                $purchaseReturnItem['tax_amount'] = ($purchaseReturnItem['net_unit_cost'] * $taxValue) / (100 + $taxValue) * $purchaseReturnItem['quantity'];
                if ($purchaseReturnItem['quantity'] == 0) {
                    $perItemTaxAmount = 0;
                } else {
                    $perItemTaxAmount = $purchaseReturnItem['tax_amount'] / $purchaseReturnItem['quantity'];
                }
                $purchaseReturnItem['net_unit_cost'] -= $perItemTaxAmount;
            }
        } else {
            throw new UnprocessableEntityHttpException('Please enter tax value between 0 to 100 ');
        }
        $purchaseReturnItem['discount_type'] = $discountType;
        $purchaseReturnItem['discount_value'] = $discountValue;
        $purchaseReturnItem['tax_type'] = $taxType;
        $purchaseReturnItem['tax_value'] = $taxValue;
        $purchaseReturnItem['sub_total'] = ($purchaseReturnItem['net_unit_cost'] + $perItemTaxAmount) * $purchaseReturnItem['quantity'];

        return $purchaseReturnItem;
    }

    /**
     * @return mixed
     */
    public function updatePurchaseReturn($input, $id)
    {
        try {
            DB::beginTransaction();

            // S1 pre-read without lock so purchases are locked in ascending ID order FIRST
            $meta = PurchaseReturn::whereKey($id)->first(['id', 'purchase_id', 'supplier_id', 'warehouse_id']);
            if ($meta) {
                $preLockIds = Purchase::where('supplier_id', $meta->supplier_id)
                    ->where('warehouse_id', $meta->warehouse_id)
                    ->where('status', Purchase::RECEIVED)
                    ->pluck('id')
                    ->all();
                if ($meta->purchase_id) {
                    $preLockIds[] = (int) $meta->purchase_id;
                }
                $preLockIds = array_values(array_unique(array_map('intval', $preLockIds)));
                sort($preLockIds);
                if (! empty($preLockIds)) {
                    Purchase::whereIn('id', $preLockIds)->orderBy('id')->lockForUpdate()->get();
                }
            }

            $purchaseReturn = PurchaseReturn::with('purchaseReturnItems')->whereKey($id)->lockForUpdate()->firstOrFail();

            if (! is_null($input['purchase_id'] ?? null) && (int) $input['purchase_id'] !== (int) $purchaseReturn->purchase_id) {
                throw new UnprocessableEntityHttpException('Cannot reassign purchase_id on an existing purchase return.');
            }
            if (isset($input['supplier_id']) && (int) $input['supplier_id'] !== (int) $purchaseReturn->supplier_id) {
                throw new UnprocessableEntityHttpException('Purchase return supplier must match the original purchase.');
            }
            if (isset($input['warehouse_id']) && (int) $input['warehouse_id'] !== (int) $purchaseReturn->warehouse_id) {
                throw new UnprocessableEntityHttpException('Purchase return warehouse must match the original purchase.');
            }

            if ($purchaseReturn->purchase_id) {
                $input['purchase_id'] = $purchaseReturn->purchase_id;
            } else {
                unset($input['purchase_id']);
            }
            $input['warehouse_id'] = $purchaseReturn->warehouse_id;
            $input['supplier_id'] = $purchaseReturn->supplier_id;
            $input['notes'] = $input['notes'] ?? ($input['note'] ?? $purchaseReturn->notes);

            $wasCompleted = ((int) $purchaseReturn->status === PurchaseReturn::COMPLETED);
            $newStatus = isset($input['status']) ? (int) $input['status'] : (int) $purchaseReturn->status;
            if (! in_array($newStatus, [PurchaseReturn::COMPLETED, PurchaseReturn::PENDING], true)) {
                throw new UnprocessableEntityHttpException('Invalid purchase return status.');
            }
            $input['status'] = $newStatus;
            $willBeCompleted = ($newStatus === PurchaseReturn::COMPLETED);

            /** @var StockMovementService $stockService */
            $stockService = app(StockMovementService::class);
            $prepared = $this->prepareAndValidatePurchaseReturn($input, $purchaseReturn, $stockService);

            // Deterministically lock ALL affected stock rows BEFORE any mutation
            $allPairs = $prepared['pairs'];
            foreach ($purchaseReturn->purchaseReturnItems as $existingItem) {
                $allPairs[] = ['warehouse_id' => (int) $purchaseReturn->warehouse_id, 'product_id' => (int) $existingItem->product_id];
            }
            $stockService->lockStockRows($allPairs);

            // If transitioning COMPLETED -> PENDING, reverse all previously deducted items first
            if ($wasCompleted && ! $willBeCompleted) {
                foreach ($purchaseReturn->purchaseReturnItems as $existingItem) {
                    $stockService->reverse(
                        (int) $purchaseReturn->warehouse_id,
                        (int) $existingItem->product_id,
                        (float) $existingItem->quantity,
                        'purchase_return_status_reversal',
                        PurchaseReturn::class,
                        (int) $purchaseReturn->id,
                        isset($existingItem->product_cost) ? (float) $existingItem->product_cost : null,
                        'Purchase return reverted from COMPLETED (' . ($purchaseReturn->reference_code ?? ('#' . $purchaseReturn->id)) . ')'
                    );
                }
            }

            $mutateStockDuringItemEdits = ($wasCompleted && $willBeCompleted);

            $purchaseReturnItemIds = $purchaseReturn->purchaseReturnItems->pluck('id')->toArray();
            $purchaseReturnItemOldIds = [];
            foreach ($prepared['items'] as $key => $preparedItem) {
                $editItemId = $preparedItem['purchase_return_item_id'] ?? null;
                $purchaseReturnItemOldIds[$key] = $editItemId;

                $purchaseReturnItemArr = Arr::only($preparedItem, [
                    'purchase_return_item_id',
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

                if (! is_null($editItemId)) {
                    $this->updateItem($purchaseReturnItemArr, (int) $purchaseReturn->warehouse_id, $purchaseReturn, $mutateStockDuringItemEdits);
                } else {
                    unset($purchaseReturnItemArr['purchase_return_item_id']);
                    $purchaseReturn->purchaseReturnItems()->create($purchaseReturnItemArr);
                    if ($mutateStockDuringItemEdits) {
                        $stockService->decrease(
                            (int) $purchaseReturn->warehouse_id,
                            (int) $purchaseReturnItemArr['product_id'],
                            (float) $purchaseReturnItemArr['quantity'],
                            'purchase_return_update_add',
                            PurchaseReturn::class,
                            (int) $purchaseReturn->id,
                            isset($purchaseReturnItemArr['product_cost']) ? (float) $purchaseReturnItemArr['product_cost'] : null
                        );
                    }
                }
            }

            $removeItemIds = array_diff($purchaseReturnItemIds, array_filter($purchaseReturnItemOldIds, static fn ($v) => ! is_null($v)));
            if (! empty(array_values($removeItemIds))) {
                if ($mutateStockDuringItemEdits) {
                    foreach ($removeItemIds as $removeItemId) {
                        $oldProduct = $purchaseReturn->purchaseReturnItems()->whereKey($removeItemId)->first();
                        if (! $oldProduct) {
                            continue;
                        }
                        $stockService->reverse(
                            (int) $purchaseReturn->warehouse_id,
                            (int) $oldProduct->product_id,
                            (float) $oldProduct->quantity,
                            'purchase_return_update_reversal',
                            PurchaseReturn::class,
                            (int) $purchaseReturn->id,
                            isset($oldProduct->product_cost) ? (float) $oldProduct->product_cost : null,
                            'Purchase return item removed (' . ($purchaseReturn->reference_code ?? ('#' . $purchaseReturn->id)) . ')'
                        );
                    }
                }
                $purchaseReturn->purchaseReturnItems()->whereIn('id', array_values($removeItemIds))->delete();
            }

            $purchaseReturn = $this->applyServerComputedPurchaseReturnUpdate($purchaseReturn, $input, $prepared['order']);

            // If transitioning PENDING -> COMPLETED, deduct stock ONCE for all current items
            if (! $wasCompleted && $willBeCompleted) {
                $purchaseReturn->load('purchaseReturnItems');
                foreach ($purchaseReturn->purchaseReturnItems as $currentItem) {
                    $stockService->decrease(
                        (int) $purchaseReturn->warehouse_id,
                        (int) $currentItem->product_id,
                        (float) $currentItem->quantity,
                        'purchase_return',
                        PurchaseReturn::class,
                        (int) $purchaseReturn->id,
                        isset($currentItem->product_cost) ? (float) $currentItem->product_cost : null,
                        'Purchase return completed (' . ($purchaseReturn->reference_code ?? ('#' . $purchaseReturn->id)) . ')'
                    );
                }
            }

            DB::commit();

            return $purchaseReturn->load('purchaseReturnItems');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            DB::rollBack();
            throw $e;
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    public function updateItem($purchaseReturnItem, $warehouseId, ?PurchaseReturn $purchaseReturn = null, bool $mutateStock = true): bool
    {
        try {
            $oldItem = $purchaseReturn
                ? $purchaseReturn->purchaseReturnItems()->whereKey($purchaseReturnItem['purchase_return_item_id'])->first()
                : PurchaseReturnItem::whereKey($purchaseReturnItem['purchase_return_item_id'])->whereHas('purchaseReturn')->first();
            if (! $oldItem) {
                throw new NotFoundHttpException('Purchase return item not found.');
            }

            if ($mutateStock) {
                /** @var StockMovementService $stockService */
                $stockService = app(StockMovementService::class);
                $newQty = $stockService->assertPositiveQuantity($purchaseReturnItem['quantity'] ?? null, 'Return quantity must be greater than zero.');
                $oldQty = (float) $oldItem->quantity;
                if (abs($oldQty - $newQty) > 1e-9) {
                    $delta = $oldQty - $newQty; // positive restores stock; negative deducts more returned stock
                    $stockService->applyDelta(
                        (int) $warehouseId,
                        (int) $purchaseReturnItem['product_id'],
                        $delta,
                        $delta > 0 ? 'purchase_return_update_reversal' : 'purchase_return_update_sub',
                        PurchaseReturn::class,
                        (int) ($purchaseReturn?->id ?? $oldItem->purchase_return_id),
                        isset($purchaseReturnItem['product_cost']) ? (float) $purchaseReturnItem['product_cost'] : null,
                        null,
                        'Quantity must be less than Available quantity.'
                    );
                }
            }

            unset($purchaseReturnItem['purchase_return_item_id'], $purchaseReturnItem['purchase_return_id']);
            $oldItem->update($purchaseReturnItem);

            return true;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            throw $e;
        } catch (Exception $e) {
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    /**
     * Persist server-computed Purchase Return header fields while preserving valid partial
     * received_amount when payment_status != PAID (C2-B3-06).
     */
    private function applyServerComputedPurchaseReturnUpdate(PurchaseReturn $purchaseReturn, array $input, array $orderTotals): PurchaseReturn
    {
        $purchaseReturnInputArray = Arr::only($input, [
            'date',
            'paid_amount',
            'payment_type',
            'notes',
            'status',
            'payment_status',
        ]);
        $purchaseReturnInputArray['tax_rate'] = $orderTotals['tax_rate'];
        $purchaseReturnInputArray['tax_amount'] = $orderTotals['tax_amount'];
        $purchaseReturnInputArray['discount'] = $orderTotals['discount'];
        $purchaseReturnInputArray['shipping'] = $orderTotals['shipping'];
        $purchaseReturnInputArray['grand_total'] = $orderTotals['grand_total'];

        $effectivePaymentStatus = (int) ($input['payment_status'] ?? $purchaseReturn->payment_status);
        if ($effectivePaymentStatus === PurchaseReturn::PAID) {
            $purchaseReturnInputArray['received_amount'] = $orderTotals['grand_total'];
        } elseif (array_key_exists('received_amount', $input) && $input['received_amount'] !== null && $input['received_amount'] !== '') {
            $purchaseReturnInputArray['received_amount'] = (float) $input['received_amount'];
        } else {
            $purchaseReturnInputArray['received_amount'] = $purchaseReturn->received_amount;
        }

        if (! array_key_exists('paid_amount', $input) || $input['paid_amount'] === null || $input['paid_amount'] === '') {
            unset($purchaseReturnInputArray['paid_amount']);
        }

        $purchaseReturn->update($purchaseReturnInputArray);

        return $purchaseReturn;
    }

    /**
     * @return mixed
     */
    public function updatePurchaseReturnCalculation($input, $id)
    {
        $purchaseReturn = PurchaseReturn::findOrFail($id);
        $subTotalAmount = (float) $purchaseReturn->purchaseReturnItems()->sum('sub_total');
        $discount = (float) ($input['discount'] ?? 0);
        $taxRate = (float) ($input['tax_rate'] ?? 0);
        $shipping = (float) ($input['shipping'] ?? 0);

        if ($discount > $subTotalAmount || $discount < 0) {
            throw new UnprocessableEntityHttpException('Discount amount should not be greater than total.');
        }
        $grandTotal = $subTotalAmount - $discount;
        if ($taxRate > 100 || $taxRate < 0) {
            throw new UnprocessableEntityHttpException('Please enter tax value between 0 to 100.');
        }
        $taxAmount = $grandTotal * $taxRate / 100;
        $grandTotal += $taxAmount;

        if ($shipping > $grandTotal || $shipping < 0) {
            throw new UnprocessableEntityHttpException(__('messages.error.shipping_amount_not_be_greater'));
        }
        $grandTotal += $shipping;

        return $this->applyServerComputedPurchaseReturnUpdate($purchaseReturn, $input, [
            'discount' => $discount,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'shipping' => $shipping,
            'grand_total' => $grandTotal,
        ]);
    }
}
