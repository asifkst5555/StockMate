<?php

namespace App\Services;

use App\Models\Adjustment;
use App\Models\AdjustmentItem;
use App\Models\ManageStock;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Transfer;
use App\Models\Unit;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Centralized atomic stock & movement ledger service (Phase C2 Batch 1).
 *
 * Guarantees:
 * - Row-level locking (`lockForUpdate()`) before every stock read/mutation.
 * - Deterministic `(warehouse_id, product_id)` lock ordering for multi-row operations.
 * - Strict negative-stock rejection (never silently clamps `< 0` to `0`).
 * - Consistent `quantity_before + quantity_change === quantity_after` ledger invariant.
 * - Safe tenant resolution and 1:1 unit parity enforcement.
 */
class StockMovementService
{
    private const FLOAT_EPSILON = 1e-9;

    /**
     * Deterministically lock all referenced `(warehouse_id, product_id)` rows in ManageStock
     * to prevent deadlocks on multi-item or opposing warehouse operations.
     *
     * @param  array<int, array{warehouse_id: int, product_id: int}>  $pairs
     */
    public function lockStockRows(array $pairs): void
    {
        $unique = [];
        foreach ($pairs as $pair) {
            $whId = (int) ($pair['warehouse_id'] ?? 0);
            $prodId = (int) ($pair['product_id'] ?? 0);
            if ($whId > 0 && $prodId > 0) {
                $unique["{$whId}:{$prodId}"] = ['warehouse_id' => $whId, 'product_id' => $prodId];
            }
        }

        usort($unique, static function (array $a, array $b): int {
            return [$a['warehouse_id'], $a['product_id']] <=> [$b['warehouse_id'], $b['product_id']];
        });

        foreach ($unique as $pair) {
            ManageStock::whereWarehouseId($pair['warehouse_id'])
                ->whereProductId($pair['product_id'])
                ->lockForUpdate()
                ->first();
        }
    }

    /**
     * Assert that a quantity is strictly positive (> 0).
     */
    public function assertPositiveQuantity($quantity, string $message = 'Quantity must be greater than zero.'): float
    {
        if (! is_numeric($quantity)) {
            throw new UnprocessableEntityHttpException($message);
        }

        $qty = (float) $quantity;
        if ($qty <= 0.0 || ! is_finite($qty)) {
            throw new UnprocessableEntityHttpException($message);
        }

        return $qty;
    }

    /**
     * Atomically increase stock and record a stock movement.
     */
    public function increase(
        int $warehouseId,
        int $productId,
        float $quantity,
        string $type,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?float $unitCost = null,
        ?string $note = null
    ): ManageStock {
        $qty = $this->assertPositiveQuantity($quantity);

        $stock = ManageStock::whereWarehouseId($warehouseId)
            ->whereProductId($productId)
            ->lockForUpdate()
            ->first();

        $resolvedTenantId = $this->resolveTenantId($warehouseId, $productId);

        if ($stock) {
            $beforeQty = (float) $stock->quantity;
            $afterQty = round($beforeQty + $qty, 6);
            $stock->update(['quantity' => $afterQty]);
        } else {
            $beforeQty = 0.0;
            $afterQty = round($qty, 6);
            $stock = ManageStock::create([
                'tenant_id' => $resolvedTenantId,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'quantity' => $afterQty,
            ]);
        }

        $resolvedCost = $this->resolveUnitCost($productId, $unitCost);
        $this->recordMovement(
            $warehouseId,
            $productId,
            $type,
            round($afterQty - $beforeQty, 6),
            $beforeQty,
            $afterQty,
            $referenceType,
            $referenceId,
            $resolvedCost,
            $note,
            $stock->tenant_id ?: $resolvedTenantId
        );

        return $stock;
    }

    /**
     * Atomically decrease stock under row lock, rejecting negative stock (never clamping).
     */
    public function decrease(
        int $warehouseId,
        int $productId,
        float $quantity,
        string $type,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?float $unitCost = null,
        ?string $note = null,
        string $insufficientMessage = 'Quantity must be less than Available quantity.'
    ): ManageStock {
        $qty = $this->assertPositiveQuantity($quantity);

        $stock = ManageStock::whereWarehouseId($warehouseId)
            ->whereProductId($productId)
            ->lockForUpdate()
            ->first();

        if (! $stock) {
            throw new UnprocessableEntityHttpException($insufficientMessage);
        }

        $beforeQty = (float) $stock->quantity;
        if (($beforeQty + self::FLOAT_EPSILON) < $qty) {
            throw new UnprocessableEntityHttpException($insufficientMessage);
        }

        $rawAfter = $beforeQty - $qty;
        $afterQty = ($rawAfter < 0.0 && $rawAfter >= -self::FLOAT_EPSILON)
            ? 0.0
            : round($rawAfter, 6);

        if ($afterQty < 0.0) {
            throw new UnprocessableEntityHttpException($insufficientMessage);
        }

        $stock->update(['quantity' => $afterQty]);

        $resolvedCost = $this->resolveUnitCost($productId, $unitCost);
        $this->recordMovement(
            $warehouseId,
            $productId,
            $type,
            round($afterQty - $beforeQty, 6),
            $beforeQty,
            $afterQty,
            $referenceType,
            $referenceId,
            $resolvedCost,
            $note,
            $stock->tenant_id
        );

        return $stock;
    }

    /**
     * Apply a signed stock delta (+ for increase, - for decrease, 0 is a no-op).
     */
    public function applyDelta(
        int $warehouseId,
        int $productId,
        float $delta,
        string $type,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?float $unitCost = null,
        ?string $note = null,
        string $insufficientMessage = 'Quantity must be less than Available quantity.'
    ): ?ManageStock {
        if (abs($delta) <= self::FLOAT_EPSILON) {
            return null;
        }

        if ($delta > 0.0) {
            return $this->increase(
                $warehouseId,
                $productId,
                $delta,
                $type,
                $referenceType,
                $referenceId,
                $unitCost,
                $note
            );
        }

        return $this->decrease(
            $warehouseId,
            $productId,
            abs($delta),
            $type,
            $referenceType,
            $referenceId,
            $unitCost,
            $note,
            $insufficientMessage
        );
    }

    /**
     * Apply a stock adjustment item (Addition or Subtraction).
     */
    public function adjust(
        int $warehouseId,
        int $productId,
        float $quantity,
        int $methodType,
        ?int $adjustmentId = null,
        ?string $note = null
    ): ManageStock {
        $qty = $this->assertPositiveQuantity($quantity, 'Adjustment quantity must be greater than zero.');

        if ($methodType === AdjustmentItem::METHOD_ADDITION) {
            return $this->increase(
                $warehouseId,
                $productId,
                $qty,
                'adjustment_add',
                Adjustment::class,
                $adjustmentId,
                null,
                $note
            );
        }

        if ($methodType === AdjustmentItem::METHOD_SUBTRACTION) {
            return $this->decrease(
                $warehouseId,
                $productId,
                $qty,
                'adjustment_sub',
                Adjustment::class,
                $adjustmentId,
                null,
                $note,
                'Quantity exceeds quantity available in stock.'
            );
        }

        throw new UnprocessableEntityHttpException('Invalid adjustment method type.');
    }

    /**
     * Atomically transfer stock from source warehouse to destination warehouse
     * using deterministic lock ordering.
     */
    public function transfer(
        int $fromWarehouseId,
        int $toWarehouseId,
        int $productId,
        float $quantity,
        ?int $transferId = null,
        ?float $unitCost = null,
        ?string $note = null
    ): void {
        if ($fromWarehouseId === $toWarehouseId) {
            throw new UnprocessableEntityHttpException('Source and destination warehouses cannot be the same.');
        }

        $qty = $this->assertPositiveQuantity($quantity, 'Transfer quantity must be greater than zero.');

        $this->lockStockRows([
            ['warehouse_id' => $fromWarehouseId, 'product_id' => $productId],
            ['warehouse_id' => $toWarehouseId, 'product_id' => $productId],
        ]);

        $this->decrease(
            $fromWarehouseId,
            $productId,
            $qty,
            'transfer_out',
            Transfer::class,
            $transferId,
            $unitCost,
            $note ?? ('Transfer out to warehouse #' . $toWarehouseId),
            'Quantity should not be greater than available quantity.'
        );

        $this->increase(
            $toWarehouseId,
            $productId,
            $qty,
            'transfer_in',
            Transfer::class,
            $transferId,
            $unitCost,
            $note ?? ('Transfer in from warehouse #' . $fromWarehouseId)
        );
    }

    /**
     * Atomically reverse a transfer: validates & deducts destination stock FIRST
     * (failing with 422 if destination stock was already consumed) before crediting source.
     */
    public function reverseTransfer(
        int $fromWarehouseId,
        int $toWarehouseId,
        int $productId,
        float $quantity,
        ?int $transferId = null,
        ?float $unitCost = null,
        ?string $note = null
    ): void {
        $qty = $this->assertPositiveQuantity($quantity, 'Transfer reversal quantity must be greater than zero.');

        $this->lockStockRows([
            ['warehouse_id' => $fromWarehouseId, 'product_id' => $productId],
            ['warehouse_id' => $toWarehouseId, 'product_id' => $productId],
        ]);

        // MUST deduct destination FIRST — if destination lacks stock, throws 422 and never credits source!
        $this->decrease(
            $toWarehouseId,
            $productId,
            $qty,
            'transfer_reversal_out',
            Transfer::class,
            $transferId,
            $unitCost,
            $note ?? ('Transfer reversal out from warehouse #' . $toWarehouseId),
            'Cannot reverse transfer: destination warehouse does not have sufficient available quantity.'
        );

        $this->increase(
            $fromWarehouseId,
            $productId,
            $qty,
            'transfer_reversal_in',
            Transfer::class,
            $transferId,
            $unitCost,
            $note ?? ('Transfer reversal restored to warehouse #' . $fromWarehouseId)
        );
    }

    /**
     * Reconcile a single transfer item's active source/destination movements toward target quantities.
     *
     * Guarantees safe order:
     * 1) Destination reductions execute BEFORE source restorations (blocks unsafe reversal on consumed stock).
     * 2) Source deductions execute BEFORE destination credits (prevents phantom destination stock).
     */
    public function reconcileTransferItem(
        int $fromWarehouseId,
        int $toWarehouseId,
        int $productId,
        float $activeSourceQty,
        float $activeDestQty,
        float $targetSourceQty,
        float $targetDestQty,
        int $transferId,
        ?float $unitCost = null,
        ?string $referenceCode = null
    ): void {
        if ($fromWarehouseId === $toWarehouseId) {
            throw new UnprocessableEntityHttpException('Source and destination warehouses cannot be the same.');
        }

        $refLabel = $referenceCode ?: ('#' . $transferId);

        $this->lockStockRows([
            ['warehouse_id' => $fromWarehouseId, 'product_id' => $productId],
            ['warehouse_id' => $toWarehouseId, 'product_id' => $productId],
        ]);

        // 1. Reduce destination credit FIRST (fail 422 before crediting source)
        if (($activeDestQty - $targetDestQty) > 1e-6) {
            $destReverseQty = round($activeDestQty - $targetDestQty, 6);
            $this->decrease(
                $toWarehouseId,
                $productId,
                $destReverseQty,
                'transfer_reversal_out',
                Transfer::class,
                $transferId,
                $unitCost,
                'Transfer destination reversal (' . $refLabel . ')',
                'Cannot reverse transfer: destination warehouse does not have sufficient available quantity.'
            );
        }

        // 2. Deduct additional source quantity BEFORE crediting destination
        if (($targetSourceQty - $activeSourceQty) > 1e-6) {
            $sourceDeductQty = round($targetSourceQty - $activeSourceQty, 6);
            $this->decrease(
                $fromWarehouseId,
                $productId,
                $sourceDeductQty,
                'transfer_out',
                Transfer::class,
                $transferId,
                $unitCost,
                'Transfer out to warehouse #' . $toWarehouseId . ' (' . $refLabel . ')',
                'Quantity should not be greater than available quantity.'
            );
        }

        // 3. Restore reduced source quantity
        if (($activeSourceQty - $targetSourceQty) > 1e-6) {
            $sourceRestoreQty = round($activeSourceQty - $targetSourceQty, 6);
            $this->increase(
                $fromWarehouseId,
                $productId,
                $sourceRestoreQty,
                'transfer_reversal_in',
                Transfer::class,
                $transferId,
                $unitCost,
                'Transfer source reversal (' . $refLabel . ')'
            );
        }

        // 4. Credit additional destination quantity
        if (($targetDestQty - $activeDestQty) > 1e-6) {
            $destCreditQty = round($targetDestQty - $activeDestQty, 6);
            $this->increase(
                $toWarehouseId,
                $productId,
                $destCreditQty,
                'transfer_in',
                Transfer::class,
                $transferId,
                $unitCost,
                'Transfer in from warehouse #' . $fromWarehouseId . ' (' . $refLabel . ')'
            );
        }
    }

    /**
     * Reverse a prior transaction stock effect safely.
     */
    public function reverse(
        int $warehouseId,
        int $productId,
        float $delta,
        string $type,
        string $referenceType,
        int $referenceId,
        ?float $unitCost = null,
        ?string $note = null,
        string $insufficientMessage = 'Insufficient stock available to reverse transaction.'
    ): ?ManageStock {
        return $this->applyDelta(
            $warehouseId,
            $productId,
            $delta,
            $type,
            $referenceType,
            $referenceId,
            $unitCost,
            $note,
            $insufficientMessage
        );
    }

    /**
     * Check whether a specific movement has already been recorded for a reference.
     */
    public function hasRecordedMovement(
        string $type,
        string $referenceType,
        int $referenceId,
        ?int $warehouseId = null,
        ?int $productId = null
    ): bool {
        $query = StockMovement::where('type', $type)
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId);

        if ($warehouseId !== null) {
            $query->where('warehouse_id', $warehouseId);
        }
        if ($productId !== null) {
            $query->where('product_id', $productId);
        }

        return $query->lockForUpdate()->exists();
    }

    /**
     * Record a StockMovement row directly.
     */
    public function recordMovement(
        int $warehouseId,
        int $productId,
        string $type,
        float $quantityChange,
        float $quantityBefore,
        float $quantityAfter,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?float $unitCost = null,
        ?string $note = null,
        ?string $tenantId = null
    ): StockMovement {
        $resolvedTenantId = $this->resolveTenantId($warehouseId, $productId, $tenantId);

        return StockMovement::create([
            'tenant_id' => $resolvedTenantId,
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'type' => $type,
            'quantity_change' => $quantityChange,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $quantityAfter,
            'unit_cost' => $unitCost,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'note' => $note,
            'user_id' => Auth::id(),
        ]);
    }

    /**
     * E8 — Unit Conversion Safety:
     * Enforce 1:1 unit parity and active-tenant unit ownership.
     * Rejects cross-tenant unit references and unsupported mixed-unit transactions
     * until explicit conversion factors are implemented in a future schema-approved phase.
     */
    public function assertSupportedUnitParity(Product $product, $submittedUnitId, string $context = 'sale'): void
    {
        // Block products configured with mismatched purchase_unit != sale_unit
        if (
            ! empty($product->purchase_unit)
            && ! empty($product->sale_unit)
            && is_numeric($product->purchase_unit)
            && is_numeric($product->sale_unit)
            && (int) $product->purchase_unit !== (int) $product->sale_unit
        ) {
            throw new UnprocessableEntityHttpException(
                'Unsupported unit conversion: product purchase unit and sale unit differ without a configured conversion factor.'
            );
        }

        if ($submittedUnitId === null || $submittedUnitId === '') {
            return;
        }

        if (is_array($submittedUnitId)) {
            $submittedUnitId = $submittedUnitId['id'] ?? $submittedUnitId['value'] ?? null;
            if ($submittedUnitId === null || $submittedUnitId === '') {
                return;
            }
        }

        if (! is_numeric($submittedUnitId) || (int) $submittedUnitId <= 0) {
            throw new UnprocessableEntityHttpException('Invalid unit selected for product.');
        }

        $unitId = (int) $submittedUnitId;

        $expectedUnitId = $context === 'purchase'
            ? ($product->purchase_unit ?: $product->sale_unit)
            : ($product->sale_unit ?: $product->purchase_unit);

        // Enforce 1:1 unit parity against the product's configured unit
        if (! empty($expectedUnitId) && is_numeric($expectedUnitId) && (int) $expectedUnitId !== $unitId) {
            // Check whether submitted unit is cross-tenant vs mixed-unit for a clear error message
            if (! Unit::whereKey($unitId)->exists()) {
                throw new UnprocessableEntityHttpException('Selected unit does not belong to the active store.');
            }

            throw new UnprocessableEntityHttpException(
                'Unsupported unit conversion: transaction unit must match the configured product unit (1:1 unit parity required).'
            );
        }

        // Also block products configured with mismatched purchase_unit != sale_unit
        if (
            ! empty($product->purchase_unit)
            && ! empty($product->sale_unit)
            && is_numeric($product->purchase_unit)
            && is_numeric($product->sale_unit)
            && (int) $product->purchase_unit !== (int) $product->sale_unit
        ) {
            throw new UnprocessableEntityHttpException(
                'Unsupported unit conversion: product purchase unit and sale unit differ without a configured conversion factor.'
            );
        }

        // Tenant-scoped unit lookup (fail-closed if unit is not in active tenant AND not a legacy pre-bound 1:1 unit)
        $tenantUnit = Unit::whereKey($unitId)->first();
        if (! $tenantUnit) {
            $matchesPreboundUnit = ! empty($expectedUnitId)
                && is_numeric($expectedUnitId)
                && (int) $expectedUnitId === $unitId
                && Unit::withoutGlobalScope('tenant')->whereKey($unitId)->exists();

            if (! $matchesPreboundUnit) {
                throw new UnprocessableEntityHttpException('Selected unit does not belong to the active store.');
            }
        }
    }

    private function resolveTenantId(int $warehouseId, int $productId, ?string $tenantId = null): ?string
    {
        return $tenantId
            ?? Warehouse::withoutGlobalScope('tenant')->whereKey($warehouseId)->value('tenant_id')
            ?? Product::withoutGlobalScope('tenant')->whereKey($productId)->value('tenant_id')
            ?? Auth::user()?->tenant_id;
    }

    /**
     * Resolve unit cost: prefer explicit cost, fallback to product_cost.
     */
    private function resolveUnitCost(int $productId, ?float $unitCost): ?float
    {
        if ($unitCost !== null) {
            return $unitCost;
        }

        $cost = Product::withoutGlobalScope('tenant')->whereKey($productId)->value('product_cost');

        return $cost !== null ? (float) $cost : null;
    }
}
