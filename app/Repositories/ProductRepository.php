<?php

namespace App\Repositories;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Picqer\Barcode\BarcodeGeneratorPNG;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Class ProductCategoryRepository
 */
class ProductRepository extends BaseRepository
{
    /**
     * @var array
     */
    protected $fieldSearchable = [
        'name',
        'code',
        'product_cost',
        'product_price',
        'product_unit',
        'sale_unit',
        'purchase_unit',
        'stock_alert',
        'order_tax',
        'notes',
        'created_at',
    ];

    /**
     * @var string[]
     */
    protected $allowedFields = [
        'name',
        'code',
        'product_cost',
        'product_price',
        'product_unit',
        'sale_unit',
        'purchase_unit',
        'stock_alert',
        'order_tax',
        'notes',
    ];

    public function getAvailableRelations(): array
    {
        return array_values(Product::$availableRelations);
    }

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
        return Product::class;
    }

    /**
     * @return LengthAwarePaginator|Collection|mixed
     */
    public function storeProduct($input)
    {
        try {
            DB::beginTransaction();
            $product = $this->create($input);
            $reference_code = 'PR_' . $product->id;
            $this->generateBarcode($input, $reference_code);
            $product['barcode_image_url'] = app(\App\Services\BarcodeService::class)->resolveProductBarcodeUrl($product);

            // create purchase

        if (isset($input['purchase_quantity'])) {
            $purchaseStock = [
                'warehouse_id' => $input['purchase_warehouse_id'],
                'supplier_id' => $input['purchase_supplier_id'],
                'quantity' => $input['purchase_quantity'],
                'status' => $input['purchase_status'],
                'date' => $input['purchase_date'],
            ];

            //            $purchaseStock = $input['manage_purchase_stock'][0];

            if ($purchaseStock) {
                if (empty($purchaseStock['warehouse_id'])) {
                    throw new UnprocessableEntityHttpException('Please Select the warehouse.');
                } elseif (empty($purchaseStock['supplier_id'])) {
                    throw new UnprocessableEntityHttpException('Please Select the supplier.');
                } elseif ($purchaseStock['quantity'] <= 0) {
                    throw new UnprocessableEntityHttpException('Please Enter Attlist One stock Quantity.');
                } elseif (empty($purchaseStock['status'])) {
                    throw new UnprocessableEntityHttpException('Please Select the status.');
                }

                $status = (int) $purchaseStock['status'];
                if (! in_array($status, [Purchase::RECEIVED, Purchase::PENDING, Purchase::ORDERED], true)) {
                    throw new UnprocessableEntityHttpException('Invalid purchase status.');
                }

                if (! \App\Models\Warehouse::whereKey($purchaseStock['warehouse_id'])->exists()) {
                    throw new UnprocessableEntityHttpException('Selected warehouse does not belong to the active store.');
                }
                if (! \App\Models\Supplier::whereKey($purchaseStock['supplier_id'])->exists()) {
                    throw new UnprocessableEntityHttpException('Selected supplier does not belong to the active store.');
                }

                $purchaseStock['status'] = $status;
                $purchaseStock['tax_rate'] = 0;
                $purchaseStock['tax_amount'] = 0;
                $purchaseStock['discount'] = 0;
                $purchaseStock['shipping'] = 0;
                $purchaseStock['payment_type'] = null;

                $purchaseStock['date'] = $purchaseStock['date'] ?? date('Y/m/d');

                $purchaseInputArray = Arr::only($purchaseStock, [
                    'supplier_id', 'warehouse_id', 'date', 'status', 'discount', 'tax_rate', 'tax_amount', 'shipping', 'payment_type',
                ]);

                /** @var Purchase $purchase */
                $purchase = Purchase::create($purchaseInputArray);

                $perItemTaxAmount = 0;

                $purchaseStock['net_unit_cost'] = $product->product_cost;

                if ($product->order_tax <= 100 && $product->order_tax >= 0) {
                    if ($product->tax_type == Purchase::EXCLUSIVE) {
                        $purchaseStock['tax_amount'] = (($purchaseStock['net_unit_cost'] * $product->order_tax) / 100) * $purchaseStock['quantity'];
                        $perItemTaxAmount = $purchaseStock['tax_amount'] / $purchaseStock['quantity'];
                    } elseif ($product->tax_type == Purchase::INCLUSIVE) {
                        $purchaseStock['tax_amount'] = ($purchaseStock['net_unit_cost'] * $product->order_tax) / (100 + $product->order_tax) * $purchaseStock['quantity'];
                        $perItemTaxAmount = $purchaseStock['tax_amount'] / $purchaseStock['quantity'];
                        $purchaseStock['net_unit_cost'] -= $perItemTaxAmount;
                    }
                } else {
                    throw new UnprocessableEntityHttpException('Please enter tax value between 0 to 100 ');
                }
                $purchaseStock['sub_total'] = ($purchaseStock['net_unit_cost'] + $perItemTaxAmount) * $purchaseStock['quantity'];

                $purchaseItemArr = [
                    'purchase_id' => $purchase->id,
                    'product_id' => $product->id,
                    'product_cost' => $product->product_cost,
                    'net_unit_cost' => $purchaseStock['net_unit_cost'],
                    'tax_type' => $product->tax_type,
                    'tax_value' => $product->order_tax,
                    'tax_amount' => $purchaseStock['tax_amount'],
                    'discount_type' => Purchase::FIXED,
                    'discount_value' => 0,
                    'discount_amount' => 0,
                    'purchase_unit' => $product->purchase_unit,
                    'quantity' => $purchaseStock['quantity'],
                    'sub_total' => $purchaseStock['sub_total'],
                ];

                $purchaseItem = new PurchaseItem($purchaseItemArr);
                $purchase->purchaseItems()->save($purchaseItem);

                $purchase->update([
                    'reference_code' => getSettingValue('purchase_code') . '_111' . $purchase->id,
                    'grand_total' => $purchaseStock['sub_total'],
                ]);

                // C2-17: Only increment stock when purchase status is RECEIVED, via StockMovementService
                if ((int) $purchase->status === Purchase::RECEIVED) {
                    /** @var \App\Services\StockMovementService $stockService */
                    $stockService = app(\App\Services\StockMovementService::class);
                    if (! $stockService->hasRecordedMovement('purchase', Purchase::class, (int) $purchase->id, (int) $purchaseStock['warehouse_id'], (int) $product->id)) {
                        $stockService->increase(
                            (int) $purchaseStock['warehouse_id'],
                            (int) $product->id,
                            (float) $purchaseStock['quantity'],
                            'purchase',
                            Purchase::class,
                            (int) $purchase->id,
                            (float) $product->product_cost,
                            'Initial product purchase (' . $purchase->reference_code . ')'
                        );
                    }
                }
            }
        }
            DB::commit();

            return $product;
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    /**
     * @return LengthAwarePaginator|Collection|mixed
     */
    public function updateProduct($input, $id)
    {
        try {
            DB::beginTransaction();
            $input['expiry_date'] = $input['expiry_date'] ?? null;
            // Phase B (BAR-05): Never overwrite an existing product_code with SKU when omitted
            if (empty($input['product_code'])) {
                unset($input['product_code']);
            }
            $product = $this->update($input, $id);

            $product->clearMediaCollection(Product::PRODUCT_BARCODE_PATH);
            $reference_code = 'PR_' . $product->id;
            $this->generateBarcode([
                'code' => $input['code'] ?? $product->code,
                'barcode_symbol' => $input['barcode_symbol'] ?? $product->barcode_symbol ?? Product::CODE128,
            ], $reference_code);
            $product['barcode_image_url'] = app(\App\Services\BarcodeService::class)->resolveProductBarcodeUrl($product);

            DB::commit();

            return $product;
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    public function generateBarcode($input, $reference_code): bool
    {
        $code = (string) ($input['code'] ?? '');
        $symbol = (int) ($input['barcode_symbol'] ?? Product::CODE128);

        app(\App\Services\BarcodeService::class)->storeBarcodePng($code, $symbol, (string) $reference_code);

        return true;
    }

    public function generateBarcodeBase64($code, int $symbol = Product::CODE128): string
    {
        return app(\App\Services\BarcodeService::class)->generateBase64DataUri((string) $code, $symbol);
    }
}
