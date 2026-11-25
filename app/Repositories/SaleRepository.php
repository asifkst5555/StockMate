<?php

namespace App\Repositories;

use App\Mail\MailSender;
use App\Models\Customer;
use App\Models\MailTemplate;
use App\Models\ManageStock;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesPayment;
use App\Models\SmsSetting;
use App\Models\SmsTemplate;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Picqer\Barcode\BarcodeGeneratorPNG;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Class SaleRepository
 */
class SaleRepository extends BaseRepository
{
    /**
     * @var array
     */
    protected $fieldSearchable = [
        'date',
        'grand_total',
        'paid_amount',
        'created_at',
        'reference_code'
    ];

    /**
     * @var string[]
     */
    protected $allowedFields = [
        'date',
        'grand_total',
        'paid_amount',
        'created_at',
        'reference_code'
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
        return Sale::class;
    }

    public function storeSale($input): Sale
    {
        try {
            DB::beginTransaction();

            $input['date'] = $input['date'] ?? date('Y/m/d');
            $input['is_sale_created'] = $input['is_sale_created'] ?? false;
            $QuotationId = $input['quotation_id'] ?? false;
            $saleInputArray = Arr::only($input, [
                'customer_id',
                'tax_rate',
                'tax_amount',
                'discount',
                'discount_type',
                'discount_value',
                'shipping',
                'grand_total',
                'received_amount',
                'paid_amount',
                'payment_type',
                'note',
                'date',
                'status',
                'payment_status',
                'warehouse_id'
            ]);
            $saleInputArray['user_id'] = Auth::id();

            /** @var Sale $sale */
            $sale = Sale::create($saleInputArray);

            if ($input['is_sale_created'] && $QuotationId) {
                $quotation = Quotation::find($QuotationId);
                if ($quotation) {
                    $quotation->update([
                        'is_sale_created' => true,
                    ]);
                }
            }

            $sale = $this->storeSaleItems($sale, $input);
            $reference_code = getSettingValue('sale_code') . '_111' . $sale->id;
            $this->generateBarcode($reference_code);
            $sale['barcode_image_url'] = Storage::url('sales/barcode-' . $reference_code . '.png');

            /** @var \App\Services\StockMovementService $stockService */
            $stockService = app(\App\Services\StockMovementService::class);

            $pairs = [];
            foreach ($input['sale_items'] as $saleItem) {
                $stockService->assertPositiveQuantity($saleItem['quantity'] ?? null, 'Quantity must be greater than zero.');
                $warehouseId = (int) ($saleItem['warehouse_id'] ?? $saleInputArray['warehouse_id']);
                $pairs[] = ['warehouse_id' => $warehouseId, 'product_id' => (int) $saleItem['product_id']];
            }
            $stockService->lockStockRows($pairs);

            foreach ($input['sale_items'] as $saleItem) {
                $qty = $stockService->assertPositiveQuantity($saleItem['quantity'] ?? null, 'Quantity must be greater than zero.');
                $warehouseId = (int) ($saleItem['warehouse_id'] ?? $saleInputArray['warehouse_id']);
                $productModel = Product::whereKey($saleItem['product_id'])->first();
                if ($productModel) {
                    $stockService->assertSupportedUnitParity($productModel, $saleItem['sale_unit'] ?? null, 'sale');
                }

                $stockService->decrease(
                    $warehouseId,
                    (int) $saleItem['product_id'],
                    $qty,
                    'sale',
                    Sale::class,
                    (int) $sale->id,
                    $productModel ? (float) $productModel->product_cost : null
                );
            }

            DB::commit();

            // Dispatch non-critical email/SMS notifications outside the DB transaction
            try {
                $mailTemplate = MailTemplate::where('type', MailTemplate::MAIL_TYPE_SALE)->first();
                $smsTemplate = SmsTemplate::where('type', SmsTemplate::SMS_TYPE_SALE)->first();
                $customer = Customer::whereId($sale->customer_id)->first();

                if ($customer && ((! empty($mailTemplate) && $mailTemplate->status == MailTemplate::ACTIVE) || (! empty($smsTemplate) && $smsTemplate->status == SmsTemplate::ACTIVE))) {
                    $subject = 'Customer sale';
                    $search = [
                        '{customer_name}',
                        '{sales_id}',
                        '{sales_date}',
                        '{sales_amount}',
                        '{paid_amount}',
                        '{due_amount}',
                        '{app_name}',
                    ];

                    $totalPayAmount = SalesPayment::whereSaleId($sale->id)->sum('amount');
                    $dueAmount = $sale->grand_total - $totalPayAmount;
                    $payAmount = $totalPayAmount;

                    if (($dueAmount < 0) || ($sale->payment_status == Sale::PAID)) {
                        $dueAmount = 0;
                        $payAmount = $sale->grand_total;
                    }

                    $replace = [
                        $customer->name,
                        $sale->reference_code,
                        $sale->date,
                        number_format($sale->grand_total, 2),
                        number_format($payAmount, 2),
                        number_format($dueAmount, 2),
                        getActiveStoreName(),
                    ];

                    if (! empty($mailTemplate) && $mailTemplate->status == MailTemplate::ACTIVE && ! empty($customer->email)) {
                        $data['data'] = str_replace($search, $replace, $mailTemplate->content);
                        Mail::to($customer->email)
                            ->send(new MailSender('emails.mail-sender', $subject, $data));
                    }

                    if (! empty($smsTemplate) && $smsTemplate->status == SmsTemplate::ACTIVE && ! empty($customer->phone)) {
                        $message = str_replace($search, $replace, $smsTemplate->content);
                        $client = new \GuzzleHttp\Client();
                        $url = SmsSetting::where('key', 'url')->value('value');
                        $data = SmsSetting::where('key', 'payload')->value('value');
                        $data = preg_replace('/\s/', '', $data);
                        $data = json_decode($data, true);
                        $toKey = SmsSetting::where('key', 'mobile_key')->value('value');
                        $messageKey = SmsSetting::where('key', 'message_key')->value('value');

                        if ($url && is_array($data)) {
                            $data = replaceArrayValue($data, $toKey, $customer->phone);
                            $data = replaceArrayValue($data, $messageKey, $message);
                            $client->post($url, [
                                'headers' => [
                                    'Content-Type' => 'application/json',
                                    'Accept' => 'application/json',
                                ],
                                'form_params' => [$data],
                            ]);
                        }
                    }
                }
            } catch (Exception $notifyException) {
                Log::warning('Sale notification failed after commit: ' . $notifyException->getMessage());
            }

            return $sale;
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    /**
     * Determine whether the authenticated user holds explicit permission to override catalog product prices (C1.5).
     *
     * Store Admins hold `manage_products` through their role permissions; sub-users without `manage_products`
     * are denied price overrides. Super Admin remains exempt.
     */
    private function canOverrideProductPrice(): bool
    {
        $user = Auth::user();
        if (! $user) {
            return false;
        }

        if ($user->hasRole(\App\Models\Role::SUPER_ADMIN)) {
            return true;
        }

        return $user->can('manage_products');
    }

    /**
     * Resolve a product strictly within the active tenant scope (fail-closed if tenant context is missing).
     */
    private function resolveTenantScopedProduct(int $productId, ?Product $product = null): Product
    {
        $user = Auth::user();
        $isSuperAdmin = $user && $user->hasRole(\App\Models\Role::SUPER_ADMIN);
        $activeTenantId = $user?->tenant_id;

        if (! $isSuperAdmin && empty($activeTenantId)) {
            throw new UnprocessableEntityHttpException('Active tenant context is required to resolve product pricing.');
        }

        if ($product) {
            if ((int) $product->id !== $productId) {
                $product = null;
            } elseif (! $isSuperAdmin && (string) $product->tenant_id !== (string) $activeTenantId) {
                throw new UnprocessableEntityHttpException('Product not found or does not belong to the active store.');
            }
        }

        if (! $product) {
            $query = Product::whereKey($productId);
            if (! $isSuperAdmin) {
                $query->where('tenant_id', $activeTenantId);
            }
            $product = $query->first();
        }

        if (! $product) {
            throw new UnprocessableEntityHttpException('Product not found or does not belong to the active store.');
        }

        return $product;
    }

    /**
     * @return mixed
     */
    public function calculationSaleItems($saleItem, ?Product $product = null)
    {
        $validator = Validator::make($saleItem, SaleItem::$rules);
        if ($validator->fails()) {
            throw new UnprocessableEntityHttpException($validator->errors()->first());
        }

        // C1.5: Enforce explicit tenant-scoped product ownership & server-side product_price validation
        $product = $this->resolveTenantScopedProduct((int) $saleItem['product_id'], $product);

        $catalogPrice = (float) $product->product_price;
        $hasSubmittedPrice = isset($saleItem['product_price']) && $saleItem['product_price'] !== '' && $saleItem['product_price'] !== null;
        $submittedPrice = $hasSubmittedPrice ? (float) $saleItem['product_price'] : $catalogPrice;

        if ($submittedPrice < 0) {
            throw new UnprocessableEntityHttpException('Product price cannot be negative.');
        }

        if (abs($submittedPrice - $catalogPrice) > 0.0001 && ! $this->canOverrideProductPrice()) {
            throw new UnprocessableEntityHttpException('Unauthorized product price override.');
        }

        $saleItem['product_price'] = $submittedPrice;

        //discount calculation
        $perItemDiscountAmount = 0;
        $saleItem['net_unit_price'] = $saleItem['product_price'];
        if ($saleItem['discount_type'] == Sale::PERCENTAGE) {
            if ($saleItem['discount_value'] <= 100 && $saleItem['discount_value'] >= 0) {
                $saleItem['discount_amount'] = ($saleItem['discount_value'] * $saleItem['product_price'] / 100) * $saleItem['quantity'];
                $perItemDiscountAmount = $saleItem['discount_amount'] / $saleItem['quantity'];
                $saleItem['net_unit_price'] -= $perItemDiscountAmount;
            } else {
                throw new UnprocessableEntityHttpException('Please enter discount value between 0 to 100.');
            }
        } elseif ($saleItem['discount_type'] == Sale::FIXED) {
            if ($saleItem['discount_value'] <= $saleItem['product_price'] && $saleItem['discount_value'] >= 0) {
                $saleItem['discount_amount'] = $saleItem['discount_value'] * $saleItem['quantity'];
                $perItemDiscountAmount = $saleItem['discount_amount'] / $saleItem['quantity'];
                $saleItem['net_unit_price'] -= $perItemDiscountAmount;
            } else {
                throw new UnprocessableEntityHttpException("Please enter  discount's value between product's price.");
            }
        }

        //tax calculation
        $perItemTaxAmount = 0;
        if ($saleItem['tax_value'] <= 100 && $saleItem['tax_value'] >= 0) {
            if ($saleItem['tax_type'] == Sale::EXCLUSIVE) {
                $saleItem['tax_amount'] = (($saleItem['net_unit_price'] * $saleItem['tax_value']) / 100) * $saleItem['quantity'];
                $perItemTaxAmount = $saleItem['tax_amount'] / $saleItem['quantity'];
            } elseif ($saleItem['tax_type'] == Sale::INCLUSIVE) {
                $saleItem['tax_amount'] = ($saleItem['net_unit_price'] * $saleItem['tax_value']) / (100 + $saleItem['tax_value']) * $saleItem['quantity'];
                $perItemTaxAmount = $saleItem['tax_amount'] / $saleItem['quantity'];
                $saleItem['net_unit_price'] -= $perItemTaxAmount;
            }
        } else {
            throw new UnprocessableEntityHttpException('Please enter tax value between 0 to 100 ');
        }
        $saleItem['sub_total'] = ($saleItem['net_unit_price'] + $perItemTaxAmount) * $saleItem['quantity'];

        return $saleItem;
    }

    /**
     * @return mixed
     */
    public function storeSaleItems($sale, $input)
    {
        foreach ($input['sale_items'] as $saleItem) {
            $product = $this->resolveTenantScopedProduct((int) $saleItem['product_id']);

            if (isset($product->quantity_limit) && $saleItem['quantity'] > $product->quantity_limit) {
                throw new UnprocessableEntityHttpException('Please enter less than ' . $product->quantity_limit . ' quantity of ' . $product->name . ' product.');
            }
            $item = $this->calculationSaleItems($saleItem, $product);
            $saleItem = new SaleItem($item);
            $sale->saleItems()->save($saleItem);
        }

        $subTotalAmount = $sale->saleItems()->sum('sub_total');

        $orderDiscount = (float) ($input['discount'] ?? 0);
        if ($orderDiscount < 0) {
            throw new UnprocessableEntityHttpException('Discount amount cannot be negative.');
        }
        if ($orderDiscount <= $subTotalAmount) {
            $input['grand_total'] = $subTotalAmount - $orderDiscount;
        } else {
            throw new UnprocessableEntityHttpException('Discount amount should not be greater than total.');
        }
        if ($input['tax_rate'] <= 100 && $input['tax_rate'] >= 0) {
            $input['tax_amount'] = $input['grand_total'] * $input['tax_rate'] / 100;
        } else {
            throw new UnprocessableEntityHttpException('Please enter tax value between 0 to 100.');
        }
        $input['grand_total'] += $input['tax_amount'];
        if ($input['shipping'] <= $input['grand_total'] && $input['shipping'] >= 0) {
            $input['grand_total'] += $input['shipping'];
        } else {
            throw new UnprocessableEntityHttpException(__('messages.error.shipping_amount_not_be_greater'));
        }

        if ($input['payment_status'] == Sale::UNPAID) {
            $input['paid_amount'] = null;
            $input['payment_type'] = null;
            SalesPayment::whereSaleId($sale->id)->delete();
        } else {
            $paymentDetails = $input['payment_details'] ?? [];
            $totalAmount = collect($paymentDetails)->sum(function ($detail) {
                return floatval($detail['amount'] ?? 0);
            });
            if (!empty($paymentDetails) && is_array($paymentDetails)) {
                foreach ($paymentDetails as $paymentDetail) {
                    if ($paymentDetail['amount'] > 0 && PaymentMethod::whereId($paymentDetail['payment_type']['value'])->exists()) {
                        SalesPayment::create([
                            'sale_id' => $sale->id,
                            'reference' => $paymentDetail['reference'] ?? null,
                            'payment_date' => $paymentDetail['date'] ?? Carbon::now(),
                            'amount' => (float) $paymentDetail['amount'] ?? 0,
                            'received_amount' => (float) $input['grand_total'] ?? 0,
                            'payment_type' => (int) $paymentDetail['payment_type']['value'] ?? null,
                        ]);
                    }
                }
            }
            $input['paid_amount'] = $totalAmount;
            $input['payment_type'] = SalesPayment::whereSaleId($sale->id)->latest()->first()->payment_type ?? null;
            if (round($totalAmount, 2) >= round($input['grand_total'], 2)) {
                $input['payment_status'] = Sale::PAID;
            } else {
                $input['payment_status'] = Sale::PARTIAL_PAID;
            }
        }

        $input['reference_code'] = getSettingValue('sale_code') . '_111' . $sale->id;
        $sale->update($input);

        return $sale;
    }

    /**
     * @return mixed
     */
    public function updateSale($input, $id)
    {
        try {
            DB::beginTransaction();
            $sale = Sale::whereKey($id)->lockForUpdate()->firstOrFail();
            /** @var \App\Services\StockMovementService $stockService */
            $stockService = app(\App\Services\StockMovementService::class);
            $saleItemIds = $sale->saleItems()->pluck('id')->toArray();
            $saleItmOldIds = [];
            foreach ($input['sale_items'] as $key => $saleItem) {
                $stockService->assertPositiveQuantity($saleItem['quantity'] ?? null, 'Quantity must be greater than zero.');
                $product = Product::whereId($saleItem['product_id'])->first();
                if ($product) {
                    $stockService->assertSupportedUnitParity($product, $saleItem['sale_unit'] ?? null, 'sale');
                }

                if (! empty($product) && isset($product->quantity_limit) && $saleItem['quantity'] > $product->quantity_limit) {
                    throw new UnprocessableEntityHttpException('Please enter less than ' . $product->quantity_limit . ' quantity of ' . $product->name . ' product.');
                }

                //get different ids & update
                $saleItmOldIds[$key] = $saleItem['sale_item_id'] ?? null;
                $saleItemArray = Arr::only($saleItem, [
                    'sale_item_id',
                    'product_id',
                    'product_price',
                    'net_unit_price',
                    'tax_type',
                    'tax_value',
                    'tax_amount',
                    'discount_type',
                    'discount_value',
                    'discount_amount',
                    'sale_unit',
                    'quantity',
                    'sub_total',
                ]);
                if (! is_null($saleItemArray['sale_item_id'] ?? null)) {
                    $this->updateItem($saleItemArray, $input['warehouse_id'], $sale);
                }
                //create new product items
                if (is_null($saleItemArray['sale_item_id'] ?? null)) {
                    $saleItem = $this->calculationSaleItems($saleItem);
                    $saleItemArray = Arr::only($saleItem, [
                        'product_id',
                        'product_price',
                        'net_unit_price',
                        'tax_type',
                        'tax_value',
                        'tax_amount',
                        'discount_type',
                        'discount_value',
                        'discount_amount',
                        'sale_unit',
                        'quantity',
                        'sub_total',
                    ]);
                    $sale->saleItems()->create($saleItemArray);
                    $stockService->decrease(
                        (int) $input['warehouse_id'],
                        (int) $saleItem['product_id'],
                        (float) $saleItem['quantity'],
                        'sale_update_add',
                        Sale::class,
                        (int) $sale->id,
                        $product ? (float) $product->product_cost : null
                    );
                }
            }
            $removeItemIds = array_diff($saleItemIds, $saleItmOldIds);
            //delete remove product
            if (! empty(array_values($removeItemIds))) {
                foreach ($removeItemIds as $removeItemId) {
                    // remove quantity manage storage
                    $oldProduct = $sale->saleItems()->whereKey($removeItemId)->first();
                    if (! $oldProduct) {
                        continue;
                    }
                    $stockService->reverse(
                        (int) $input['warehouse_id'],
                        (int) $oldProduct->product_id,
                        (float) $oldProduct->quantity,
                        'sale_update_reversal',
                        Sale::class,
                        (int) $sale->id,
                        null,
                        'Sale item removed (' . ($sale->reference_code ?? ('#' . $sale->id)) . ')'
                    );
                }
                $sale->saleItems()->whereIn('id', array_values($removeItemIds))->delete();
            }
            $this->generateBarcode($sale->reference_code);
            $sale['barcode_image_url'] = Storage::url('sales/barcode-' . $sale->reference_code . '.png');
            $sale = $this->updateSaleCalculation($input, $id);
            DB::commit();

            return $sale;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            DB::rollBack();
            throw $e;
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    public function updateItem($saleItem, $warehouseId, ?Sale $sale = null): bool
    {
        try {
            $saleItem = $this->calculationSaleItems($saleItem);
            // SEC-A2: Resolve SaleItem strictly through the authorized parent Sale
            $oldItem = $sale
                ? $sale->saleItems()->whereKey($saleItem['sale_item_id'])->first()
                : SaleItem::whereKey($saleItem['sale_item_id'])->whereHas('sale')->first();
            if (! $oldItem) {
                throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Sale item not found.');
            }
            /** @var \App\Services\StockMovementService $stockService */
            $stockService = app(\App\Services\StockMovementService::class);
            $newQty = $stockService->assertPositiveQuantity($saleItem['quantity'] ?? null, 'Quantity must be greater than zero.');
            $oldQty = (float) $oldItem->quantity;
            if ($oldQty != $newQty) {
                $delta = $oldQty - $newQty; // positive restores stock, negative deducts additional stock
                $stockService->applyDelta(
                    (int) $warehouseId,
                    (int) $saleItem['product_id'],
                    $delta,
                    $delta > 0 ? 'sale_update_reversal' : 'sale_update_sub',
                    Sale::class,
                    (int) ($sale?->id ?? $oldItem->sale_id)
                );
            }
            unset($saleItem['sale_item_id'], $saleItem['sale_id']);
            $oldItem->update($saleItem);

            return true;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            throw $e;
        } catch (Exception $e) {
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    /**
     * @return mixed
     */
    public function updateSaleCalculation($input, $id)
    {
        $sale = Sale::findOrFail($id);
        $subTotalAmount = $sale->saleItems()->sum('sub_total');

        $orderDiscount = (float) ($input['discount'] ?? 0);
        if ($orderDiscount < 0) {
            throw new UnprocessableEntityHttpException('Discount amount cannot be negative.');
        }
        if ($orderDiscount > $subTotalAmount) {
            throw new UnprocessableEntityHttpException('Discount amount should not be greater than total.');
        }
        $input['grand_total'] = $subTotalAmount - $orderDiscount;
        if ($input['tax_rate'] > 100 || $input['tax_rate'] < 0) {
            throw new UnprocessableEntityHttpException('Please enter tax value between 0 to 100.');
        }
        $input['tax_amount'] = $input['grand_total'] * $input['tax_rate'] / 100;

        $input['grand_total'] += $input['tax_amount'];

        if ($input['shipping'] > $input['grand_total'] || $input['shipping'] < 0) {
            throw new UnprocessableEntityHttpException(__('messages.error.shipping_amount_not_be_greater'));
        }

        $input['grand_total'] += $input['shipping'];

        $sale->first();
        $saleExistGrandTotal = $sale->grand_total;

        if ($input['payment_status'] == Sale::UNPAID) {
            $input['paid_amount'] = null;
            $input['payment_type'] = null;
            SalesPayment::whereSaleId($sale->id)->delete();
        } else {
            $paymentDetails = $input['payment_details'] ?? [];
            $totalAmount = collect($paymentDetails)->sum(function ($detail) {
                return $detail['amount'] ?? 0;
            });
            if (!empty($paymentDetails) && is_array($paymentDetails)) {
                $newPaymentIds = [];
                foreach ($paymentDetails as $paymentDetail) {
                    if (isset($paymentDetail['id']) && !empty($paymentDetail['id'])) {
                        // SEC-A2: Resolve SalesPayment strictly through parent Sale (never reassign foreign payments)
                        $existingPayment = $sale->payments()->whereKey($paymentDetail['id'])->first();
                        if (! $existingPayment) {
                            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Sales payment not found.');
                        }
                        $existingPayment->update([
                            'reference' => $paymentDetail['reference'] ?? null,
                            'payment_date' => $paymentDetail['date'] ?? Carbon::now(),
                            'amount' => $paymentDetail['amount'] ?? 0,
                            'received_amount' => $input['grand_total'] ?? 0,
                            'payment_type' => $paymentDetail['payment_type']['value'] ?? null,
                        ]);
                        $newPaymentIds[] = $existingPayment->id;
                    } else {
                        $payment = SalesPayment::create([
                            'sale_id' => $sale->id,
                            'reference' => $paymentDetail['reference'] ?? null,
                            'payment_date' => $paymentDetail['date'] ?? Carbon::now(),
                            'amount' => $paymentDetail['amount'] ?? 0,
                            'received_amount' => $input['grand_total'] ?? 0,
                            'payment_type' => $paymentDetail['payment_type']['value'] ?? null,
                        ]);
                        $newPaymentIds[] = $payment->id;
                    }
                }
                SalesPayment::whereSaleId($sale->id)->whereNotIn('id', $newPaymentIds)->delete();
            }
            $input['paid_amount'] = $totalAmount;
            $input['payment_type'] = SalesPayment::whereSaleId($sale->id)->latest()->first()->payment_type ?? null;
            if (round($totalAmount, 2) >= round($input['grand_total'], 2)) {
                $input['payment_status'] = Sale::PAID;
            } else {
                $input['payment_status'] = Sale::PARTIAL_PAID;
            }
        }

        $saleInputArray = Arr::only($input, [
            'customer_id',
            'warehouse_id',
            'tax_rate',
            'tax_amount',
            'discount',
            'discount_type',
            'discount_value',
            'shipping',
            'grand_total',
            'received_amount',
            'paid_amount',
            'payment_type',
            'note',
            'date',
            'status',
            'payment_status',
        ]);
        $sale->update($saleInputArray);

        return $sale;
    }

    /**
     * @param $input
     */
    public function generateBarcode($code): bool
    {
        $generator = new BarcodeGeneratorPNG();
        $barcodeType = $generator::TYPE_CODE_128;

        Storage::disk(config('app.media_disc'))->put(
            'sales/barcode-' . $code . '.png',
            $generator->getBarcode($code, $barcodeType, 4, 70)
        );

        return true;
    }
}
