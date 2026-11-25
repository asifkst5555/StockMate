<?php

namespace App\Repositories;

use App\Mail\MailSender;
use App\Models\Customer;
use App\Models\MailTemplate;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\SmsSetting;
use App\Models\SmsTemplate;
use App\Services\StockMovementService;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Throwable;

/**
 * Class SaleRepository
 */
class SaleReturnRepository extends BaseRepository
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
        'paid_amount',
        'payment_type',
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
        return SaleReturn::class;
    }

    public function storeSaleReturn($input): SaleReturn
    {
        try {
            DB::beginTransaction();

            $saleID = $input['sale_id'] ?? null;

            // S1: Lock parent Sale first inside transaction before reading/creating returns
            $sale = Sale::whereId($saleID)->lockForUpdate()->first();

            if (empty($sale)) {
                throw new UnprocessableEntityHttpException('Sale Does Not exist');
            }

            if ((int) $sale->warehouse_id !== (int) ($input['warehouse_id'] ?? 0) || (int) $sale->customer_id !== (int) ($input['customer_id'] ?? 0)) {
                throw new UnprocessableEntityHttpException('Sale return warehouse and customer must match the original sale.');
            }

            $input['note'] = $input['note'] ?? ($input['notes'] ?? null);
            $input['date'] = $input['date'] ?? date('Y/m/d');
            $status = (int) ($input['status'] ?? SaleReturn::RECEIVED);
            if (! in_array($status, [SaleReturn::RECEIVED, SaleReturn::PENDING], true)) {
                throw new UnprocessableEntityHttpException('Invalid sale return status.');
            }
            $input['status'] = $status;

            /** @var StockMovementService $stockService */
            $stockService = app(StockMovementService::class);
            $prepared = $this->prepareAndValidateSaleReturn($sale, $input, null, $stockService);

            $saleReturnInputArray = Arr::only($input, [
                'customer_id',
                'warehouse_id',
                'tax_rate',
                'discount',
                'shipping',
                'paid_amount',
                'payment_type',
                'payment_status',
                'note',
                'date',
                'status',
                'sale_id',
            ]);
            $saleReturnInputArray['tax_rate'] = $prepared['order']['tax_rate'];
            $saleReturnInputArray['tax_amount'] = $prepared['order']['tax_amount'];
            $saleReturnInputArray['discount'] = $prepared['order']['discount'];
            $saleReturnInputArray['shipping'] = $prepared['order']['shipping'];
            $saleReturnInputArray['grand_total'] = $prepared['order']['grand_total'];

            // C2-B3-3A Microfix 2: Enforce server-side PAID paid_amount default and reject overpayment (> grand_total) while preserving partial payments
            $hasSubmittedPaidAmount = array_key_exists('paid_amount', $input)
                && $input['paid_amount'] !== null
                && $input['paid_amount'] !== '';
            if ($hasSubmittedPaidAmount) {
                $saleReturnInputArray['paid_amount'] = $this->validateSubmittedPaidAmount(
                    $input['paid_amount'],
                    (float) $prepared['order']['grand_total']
                );
            } elseif ((int) ($saleReturnInputArray['payment_status'] ?? 0) === SaleReturn::PAID) {
                $saleReturnInputArray['paid_amount'] = $prepared['order']['grand_total'];
            }

            /** @var SaleReturn $saleReturn */
            $saleReturn = SaleReturn::create($saleReturnInputArray);
            $sale->update(['is_return' => 1]);

            foreach ($prepared['items'] as $preparedItem) {
                $itemRow = Arr::only($preparedItem, [
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
                    'sold_quantity',
                    'sub_total',
                ]);
                $saleReturn->saleReturnItems()->create($itemRow);
            }

            $saleReturn->update([
                'reference_code' => getSettingValue('sale_return_code') . '_111' . $saleReturn->id,
            ]);

            // D1: Only RECEIVED returns mutate stock; PENDING returns reserve quota without touching ManageStock
            if ($status === SaleReturn::RECEIVED) {
                $stockService->lockStockRows($prepared['pairs']);

                foreach ($prepared['items'] as $preparedItem) {
                    $stockService->increase(
                        (int) $saleReturn->warehouse_id,
                        (int) $preparedItem['product_id'],
                        (float) $preparedItem['quantity'],
                        'sale_return',
                        SaleReturn::class,
                        (int) $saleReturn->id
                    );
                }
            }

            DB::commit();

            // C2-B3-04: Dispatch notifications only AFTER commit; failures remain non-fatal
            $this->dispatchSaleReturnNotificationsAfterCommit($saleReturn);

            return $saleReturn->load('saleReturnItems');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            DB::rollBack();
            throw $e;
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    /**
     * Dispatch email/SMS notifications outside the database transaction.
     */
    private function dispatchSaleReturnNotificationsAfterCommit(SaleReturn $saleReturn): void
    {
        try {
            $mailTemplate = MailTemplate::where('type', MailTemplate::MAIL_TYPE_SALE_RETURN)->first();
            $smsTemplate = SmsTemplate::where('type', SmsTemplate::SMS_TYPE_SALE_RETURN)->first();
            $customer = Customer::whereId($saleReturn->customer_id)->first();

            if (! $customer) {
                return;
            }

            $search = [
                '{customer_name}',
                '{sales_return_id}',
                '{sales_return_date}',
                '{sales_return_amount}',
                '{app_name}',
            ];

            $replace = [
                $customer->name,
                $saleReturn->reference_code,
                $saleReturn->date,
                number_format((float) $saleReturn->grand_total, 2),
                getActiveStoreName(),
            ];

            if (! empty($mailTemplate) && $mailTemplate->status == MailTemplate::ACTIVE && ! empty($customer->email)) {
                $subject = 'Customer sale return';
                $data['data'] = str_replace($search, $replace, $mailTemplate->content);

                Mail::to($customer->email)
                    ->send(new MailSender('emails.mail-sender', $subject, $data));
            }

            if (! empty($smsTemplate) && $smsTemplate->status == SmsTemplate::ACTIVE && ! empty($customer->phone)) {
                $message = str_replace($search, $replace, $smsTemplate->content);
                $client = new \GuzzleHttp\Client([
                    'timeout' => 5,
                    'connect_timeout' => 5,
                ]);

                $url = SmsSetting::where('key', 'url')->value('value');
                $payloadRaw = SmsSetting::where('key', 'payload')->value('value');
                $payloadClean = $payloadRaw ? preg_replace('/\s/', '', $payloadRaw) : null;
                $payload = $payloadClean ? json_decode($payloadClean, true) : null;

                $toKey = SmsSetting::where('key', 'mobile_key')->value('value');
                $messageKey = SmsSetting::where('key', 'message_key')->value('value');

                if ($url && is_array($payload) && $toKey && $messageKey) {
                    $payload = replaceArrayValue($payload, $toKey, $customer->phone);
                    $payload = replaceArrayValue($payload, $messageKey, $message);

                    $client->post($url, [
                        'timeout' => 5,
                        'connect_timeout' => 5,
                        'headers' => [
                            'Content-Type' => 'application/json',
                            'Accept' => 'application/json',
                        ],
                        'form_params' => [$payload],
                    ]);
                }
            }
        } catch (Throwable $notifyException) {
            Log::warning('Sale return notification failed after commit: ' . $notifyException->getMessage());
        }
    }

    /**
     * Validate and prepare all Sale Return items, unit parity, pricing bounds,
     * cumulative quantity & financial limits, and deterministic stock lock pairs.
     *
     * @return array{items: array<int, array<string, mixed>>, pairs: array<int, array{warehouse_id: int, product_id: int}>, order: array<string, float>}
     */
    private function prepareAndValidateSaleReturn(
        Sale $sale,
        array $input,
        ?SaleReturn $existingReturn,
        StockMovementService $stockService
    ): array {
        $items = $input['sale_return_items'] ?? [];
        if (empty($items) || ! is_array($items)) {
            throw new UnprocessableEntityHttpException('At least one return item is required.');
        }

        // Lock existing SaleReturn rows for this sale to serialize quota checks (S1)
        $otherReturnsQuery = SaleReturn::where('sale_id', $sale->id);
        if ($existingReturn) {
            $otherReturnsQuery->where('id', '!=', $existingReturn->id);
        }
        $otherReturns = $otherReturnsQuery->orderBy('id')->lockForUpdate()->get();
        $otherReturnIds = $otherReturns->pluck('id')->all();

        $saleItems = SaleItem::where('sale_id', $sale->id)->get();
        if ($saleItems->isEmpty()) {
            throw new UnprocessableEntityHttpException('Original sale has no items available to return.');
        }

        $saleMetaByProduct = [];
        $origItemsTotalSubTotal = 0.0;
        foreach ($saleItems as $origItem) {
            $pid = (int) $origItem->product_id;
            $qty = (float) ($origItem->quantity ?? 0);
            $price = (float) ($origItem->product_price ?? 0);
            $sub = (float) ($origItem->sub_total ?? 0);
            $rawUnit = $origItem->getRawOriginal('sale_unit');

            if (! isset($saleMetaByProduct[$pid])) {
                $saleMetaByProduct[$pid] = [
                    'sold_qty' => 0.0,
                    'max_unit_price' => 0.0,
                    'orig_sub_total' => 0.0,
                    'raw_units' => [],
                ];
            }
            $saleMetaByProduct[$pid]['sold_qty'] += $qty;
            $saleMetaByProduct[$pid]['max_unit_price'] = max($saleMetaByProduct[$pid]['max_unit_price'], $price);
            $saleMetaByProduct[$pid]['orig_sub_total'] += $sub;
            if (! empty($rawUnit) && is_numeric($rawUnit)) {
                $saleMetaByProduct[$pid]['raw_units'][] = (int) $rawUnit;
            }
            $origItemsTotalSubTotal += $sub;
        }

        foreach ($saleMetaByProduct as $pid => &$meta) {
            $meta['raw_units'] = array_values(array_unique($meta['raw_units']));
        }
        unset($meta);

        $existingReturnItemsById = $existingReturn
            ? $existingReturn->saleReturnItems->keyBy('id')
            : collect();

        $seenEditItemIds = [];
        $preparedItems = [];
        $requestedQtyByProduct = [];
        $requestedSubTotalByProduct = [];
        $pairs = [];

        foreach ($items as $rawItem) {
            $productId = (int) ($rawItem['product_id'] ?? 0);
            if ($productId <= 0) {
                throw new UnprocessableEntityHttpException('Invalid product specified for sale return.');
            }

            // Tenant-scoped Product verification (S4)
            $product = Product::whereKey($productId)->first();
            if (! $product) {
                throw new UnprocessableEntityHttpException('Selected product does not belong to the active store.');
            }

            if (! isset($saleMetaByProduct[$productId]) || $saleMetaByProduct[$productId]['sold_qty'] <= 0.0) {
                throw new UnprocessableEntityHttpException('You can not return given product as there is no sales for it.');
            }

            $meta = $saleMetaByProduct[$productId];

            if (! is_null($rawItem['sale_return_item_id'] ?? null)) {
                $editItemId = (int) $rawItem['sale_return_item_id'];
                if (! $existingReturn || ! $existingReturnItemsById->has($editItemId)) {
                    throw new NotFoundHttpException('Sale return item not found.');
                }
                if (isset($seenEditItemIds[$editItemId])) {
                    throw new UnprocessableEntityHttpException('Duplicate sale_return_item_id in update payload.');
                }
                $seenEditItemIds[$editItemId] = true;

                $existingItem = $existingReturnItemsById->get($editItemId);
                if ((int) $existingItem->product_id !== $productId) {
                    throw new UnprocessableEntityHttpException('Cannot change product_id on an existing sale return item.');
                }
            }

            $qty = $stockService->assertPositiveQuantity($rawItem['quantity'] ?? null, 'Return quantity must be greater than zero.');

            // Unit ownership & parity + original SaleItem unit validation (E)
            $submittedUnit = is_array($rawItem['sale_unit'] ?? null)
                ? ($rawItem['sale_unit']['id'] ?? $rawItem['sale_unit']['value'] ?? null)
                : ($rawItem['sale_unit'] ?? null);
            $stockService->assertSupportedUnitParity($product, $submittedUnit, 'sale');

            if (! empty($submittedUnit) && is_numeric($submittedUnit) && ! empty($meta['raw_units'])) {
                if (! in_array((int) $submittedUnit, $meta['raw_units'], true)) {
                    throw new UnprocessableEntityHttpException('Return unit must match the original sale item unit.');
                }
            }

            $resolvedUnit = (! empty($submittedUnit) && is_numeric($submittedUnit))
                ? (int) $submittedUnit
                : ($meta['raw_units'][0] ?? (is_numeric($product->sale_unit) ? (int) $product->sale_unit : 0));

            // Pricing bound D3/H: non-negative and never exceed original SaleItem gross unit price
            $defaultUnitPrice = $meta['sold_qty'] > 0 ? round($meta['max_unit_price'], 4) : 0.0;
            $hasSubmittedPrice = isset($rawItem['product_price']) && $rawItem['product_price'] !== '' && $rawItem['product_price'] !== null;
            $submittedPrice = $hasSubmittedPrice ? (float) $rawItem['product_price'] : $defaultUnitPrice;

            if ($submittedPrice < 0.0 || ! is_finite($submittedPrice)) {
                throw new UnprocessableEntityHttpException('Return product price cannot be negative.');
            }
            if ($submittedPrice > ($meta['max_unit_price'] + 1e-6)) {
                throw new UnprocessableEntityHttpException('Return unit price cannot exceed the original sale item unit price.');
            }

            $normalizedItem = $rawItem;
            $normalizedItem['product_id'] = $productId;
            $normalizedItem['product_price'] = $submittedPrice;
            $normalizedItem['quantity'] = $qty;
            $normalizedItem['sold_quantity'] = $meta['sold_qty'];
            $normalizedItem['sale_unit'] = $resolvedUnit;
            $normalizedItem['discount_type'] = $rawItem['discount_type'] ?? SaleReturn::FIXED;
            $normalizedItem['discount_value'] = $rawItem['discount_value'] ?? 0;
            $normalizedItem['tax_type'] = $rawItem['tax_type'] ?? SaleReturn::EXCLUSIVE;
            $normalizedItem['tax_value'] = $rawItem['tax_value'] ?? 0;

            $calculated = $this->calculationSaleReturnItems($normalizedItem);
            $preparedItems[] = $calculated;

            $requestedQtyByProduct[$productId] = ($requestedQtyByProduct[$productId] ?? 0.0) + $qty;
            $requestedSubTotalByProduct[$productId] = ($requestedSubTotalByProduct[$productId] ?? 0.0) + (float) $calculated['sub_total'];
            $pairs[] = ['warehouse_id' => (int) $sale->warehouse_id, 'product_id' => $productId];
        }

        // Cumulative quantity & line refundable cap checks
        $returnedOrigMerchandiseShare = 0.0;
        foreach ($requestedQtyByProduct as $productId => $requestedQty) {
            $meta = $saleMetaByProduct[$productId];
            $priorReturnedQty = 0.0;
            $priorReturnedSubTotal = 0.0;
            if (! empty($otherReturnIds)) {
                $priorReturnedQty = (float) SaleReturnItem::whereIn('sale_return_id', $otherReturnIds)
                    ->where('product_id', $productId)
                    ->sum('quantity');
                $priorReturnedSubTotal = (float) SaleReturnItem::whereIn('sale_return_id', $otherReturnIds)
                    ->where('product_id', $productId)
                    ->sum('sub_total');
            }

            $remainingQty = max(0.0, round($meta['sold_qty'] - $priorReturnedQty, 6));
            if ($requestedQty > ($remainingQty + 1e-9)) {
                throw new UnprocessableEntityHttpException(
                    'Remaining sales quantity to return is ' . $remainingQty . ' and you are trying to return ' . $requestedQty
                );
            }

            $origEffectiveUnitSubTotal = $meta['sold_qty'] > 0.0
                ? ($meta['orig_sub_total'] / $meta['sold_qty'])
                : 0.0;
            $requestedLineSubTotal = $requestedSubTotalByProduct[$productId];
            $maxCurrentLineRefundable = $origEffectiveUnitSubTotal * $requestedQty;

            if ($requestedLineSubTotal > ($maxCurrentLineRefundable + 0.02)) {
                throw new UnprocessableEntityHttpException(
                    'Sale return line amount exceeds the original sale line refundable amount after line discount and tax.'
                );
            }

            if (($priorReturnedSubTotal + $requestedLineSubTotal) > ($meta['orig_sub_total'] + 0.02)) {
                throw new UnprocessableEntityHttpException(
                    'Cumulative sale return line amount exceeds the original sale line total.'
                );
            }

            $returnedOrigMerchandiseShare += $maxCurrentLineRefundable;
        }

        // Server-side order totals calculation (S2: never trust client subtotal/tax/grand_total)
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

        // S2: Pro-rata order-level discount/tax cap and cumulative grand_total cap
        $priorReturnsGrandTotal = (float) $otherReturns->sum('grand_total');
        $origOrderGrandTotal = max(0.0, (float) ($sale->grand_total ?? 0));

        if ($origItemsTotalSubTotal > 0.0) {
            $origNetMerchandiseWithTax = max(
                0.0,
                ($origItemsTotalSubTotal - max(0.0, (float) ($sale->discount ?? 0))) + max(0.0, (float) ($sale->tax_amount ?? 0))
            );
            $maxProRataNetMerchandiseWithTax = $origNetMerchandiseWithTax * ($returnedOrigMerchandiseShare / $origItemsTotalSubTotal);

            if ($preShippingTotal > ($maxProRataNetMerchandiseWithTax + 0.02)) {
                throw new UnprocessableEntityHttpException(
                    'Sale return refundable amount exceeds original transaction net refundable total (accounting for original discount and tax).'
                );
            }
        }

        if (($priorReturnsGrandTotal + $grandTotal) > ($origOrderGrandTotal + 0.02)) {
            throw new UnprocessableEntityHttpException(
                'Cumulative sale return total cannot exceed original sale grand total.'
            );
        }

        return [
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
    public function storeSaleReturnItems($saleReturn, $input)
    {
        foreach ($input['sale_return_items'] as $saleReturnItem) {
            $item = $this->calculationSaleReturnItems($saleReturnItem);
            $saleReturnItem = new SaleReturnItem($item);
            $saleReturn->saleReturnItems()->save($saleReturnItem);
        }

        $subTotalAmount = $saleReturn->saleReturnItems()->sum('sub_total');

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
        $input['grand_total'] += $input['tax_amount'];
        if ($input['shipping'] <= $input['grand_total'] && $input['shipping'] >= 0) {
            $input['grand_total'] += $input['shipping'];
        } else {
            throw new UnprocessableEntityHttpException(__('messages.error.shipping_amount_not_be_greater'));
        }

        $input['reference_code'] = getSettingValue('sale_return_code') . '_111' . $saleReturn->id;
        $saleReturn->update($input);

        return $saleReturn;
    }

    /**
     * @return mixed
     */
    public function calculationSaleReturnItems($saleReturnItem)
    {
        $validator = Validator::make($saleReturnItem, SaleReturnItem::$rules);
        if ($validator->fails()) {
            throw new UnprocessableEntityHttpException($validator->errors()->first());
        }

        //discount calculation
        $perItemDiscountAmount = 0;
        $saleReturnItem['net_unit_price'] = $saleReturnItem['product_price'];
        $discountType = (int) ($saleReturnItem['discount_type'] ?? SaleReturn::FIXED);
        $discountValue = (float) ($saleReturnItem['discount_value'] ?? 0);
        $taxType = (int) ($saleReturnItem['tax_type'] ?? SaleReturn::EXCLUSIVE);
        $taxValue = (float) ($saleReturnItem['tax_value'] ?? 0);

        if ($discountType == SaleReturn::PERCENTAGE) {
            if ($discountValue <= 100 && $discountValue >= 0) {
                $saleReturnItem['discount_amount'] = ($discountValue * $saleReturnItem['product_price'] / 100) * $saleReturnItem['quantity'];
                if ($saleReturnItem['quantity'] == 0) {
                    $perItemDiscountAmount = 0;
                } else {
                    $perItemDiscountAmount = $saleReturnItem['discount_amount'] / $saleReturnItem['quantity'];
                }
                $saleReturnItem['net_unit_price'] -= $perItemDiscountAmount;
            } else {
                throw new UnprocessableEntityHttpException('Please enter discount value between 0 to 100.');
            }
        } elseif ($discountType == SaleReturn::FIXED) {
            if ($discountValue <= $saleReturnItem['product_price'] && $discountValue >= 0) {
                $saleReturnItem['discount_amount'] = $discountValue * $saleReturnItem['quantity'];
                if ($saleReturnItem['quantity'] == 0) {
                    $perItemDiscountAmount = 0;
                } else {
                    $perItemDiscountAmount = $saleReturnItem['discount_amount'] / $saleReturnItem['quantity'];
                }
                $saleReturnItem['net_unit_price'] -= $perItemDiscountAmount;
            } else {
                throw new UnprocessableEntityHttpException("Please enter  discount's value between product's price.");
            }
        }

        //tax calculation
        $perItemTaxAmount = 0;
        if ($taxValue <= 100 && $taxValue >= 0) {
            if ($taxType == SaleReturn::EXCLUSIVE) {
                $saleReturnItem['tax_amount'] = (($saleReturnItem['net_unit_price'] * $taxValue) / 100) * $saleReturnItem['quantity'];
                if ($saleReturnItem['quantity'] == 0) {
                    $perItemTaxAmount = 0;
                } else {
                    $perItemTaxAmount = $saleReturnItem['tax_amount'] / $saleReturnItem['quantity'];
                }
            } elseif ($taxType == SaleReturn::INCLUSIVE) {
                $saleReturnItem['tax_amount'] = ($saleReturnItem['net_unit_price'] * $taxValue) / (100 + $taxValue) * $saleReturnItem['quantity'];
                if ($saleReturnItem['quantity'] == 0) {
                    $perItemTaxAmount = 0;
                } else {
                    $perItemTaxAmount = $saleReturnItem['tax_amount'] / $saleReturnItem['quantity'];
                }

                $saleReturnItem['net_unit_price'] -= $perItemTaxAmount;
            }
        } else {
            throw new UnprocessableEntityHttpException('Please enter tax value between 0 to 100 ');
        }
        $saleReturnItem['discount_type'] = $discountType;
        $saleReturnItem['discount_value'] = $discountValue;
        $saleReturnItem['tax_type'] = $taxType;
        $saleReturnItem['tax_value'] = $taxValue;
        $saleReturnItem['sub_total'] = ($saleReturnItem['net_unit_price'] + $perItemTaxAmount) * $saleReturnItem['quantity'];

        return $saleReturnItem;
    }

    /**
     * @return mixed
     */
    public function updateSaleReturn($input, $id)
    {
        try {
            DB::beginTransaction();

            // S1 lock order: Parent Sale -> SaleReturn -> ManageStock
            $parentSaleId = SaleReturn::whereKey($id)->value('sale_id');
            $sale = null;
            if ($parentSaleId) {
                $sale = Sale::whereId($parentSaleId)->lockForUpdate()->first();
            }

            $saleReturn = SaleReturn::with('saleReturnItems')->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($saleReturn->sale_id && (! $sale || (int) $sale->id !== (int) $saleReturn->sale_id)) {
                $sale = Sale::whereId($saleReturn->sale_id)->lockForUpdate()->first();
            }
            if (! $sale) {
                throw new UnprocessableEntityHttpException('Sale Does Not exist');
            }

            if (isset($input['sale_id']) && (int) $input['sale_id'] !== (int) $saleReturn->sale_id) {
                throw new UnprocessableEntityHttpException('Cannot reassign sale_id on an existing sale return.');
            }
            if (isset($input['customer_id']) && (int) $input['customer_id'] !== (int) $saleReturn->customer_id) {
                throw new UnprocessableEntityHttpException('Sale return customer must match the original sale.');
            }
            if (isset($input['warehouse_id']) && (int) $input['warehouse_id'] !== (int) $saleReturn->warehouse_id) {
                throw new UnprocessableEntityHttpException('Sale return warehouse must match the original sale.');
            }

            $input['sale_id'] = $saleReturn->sale_id;
            $input['customer_id'] = $saleReturn->customer_id;
            $input['warehouse_id'] = $saleReturn->warehouse_id;
            $input['note'] = $input['note'] ?? ($input['notes'] ?? $saleReturn->note);

            $wasReceived = ((int) $saleReturn->status === SaleReturn::RECEIVED);
            $newStatus = isset($input['status']) ? (int) $input['status'] : (int) $saleReturn->status;
            if (! in_array($newStatus, [SaleReturn::RECEIVED, SaleReturn::PENDING], true)) {
                throw new UnprocessableEntityHttpException('Invalid sale return status.');
            }
            $input['status'] = $newStatus;
            $willBeReceived = ($newStatus === SaleReturn::RECEIVED);

            /** @var StockMovementService $stockService */
            $stockService = app(StockMovementService::class);
            $prepared = $this->prepareAndValidateSaleReturn($sale, $input, $saleReturn, $stockService);

            // Deterministically lock ALL affected (warehouse_id, product_id) rows BEFORE any mutation
            $allPairs = $prepared['pairs'];
            foreach ($saleReturn->saleReturnItems as $existingItem) {
                $allPairs[] = ['warehouse_id' => (int) $saleReturn->warehouse_id, 'product_id' => (int) $existingItem->product_id];
            }
            $stockService->lockStockRows($allPairs);

            // If transitioning RECEIVED -> PENDING, reverse all previously received items first
            if ($wasReceived && ! $willBeReceived) {
                foreach ($saleReturn->saleReturnItems as $existingItem) {
                    $stockService->reverse(
                        (int) $saleReturn->warehouse_id,
                        (int) $existingItem->product_id,
                        -(float) $existingItem->quantity,
                        'sale_return_status_reversal',
                        SaleReturn::class,
                        (int) $saleReturn->id,
                        null,
                        'Sale return reverted from RECEIVED (' . ($saleReturn->reference_code ?? ('#' . $saleReturn->id)) . ')',
                        'Cannot revert sale return to pending: insufficient stock available.'
                    );
                }
            }

            $mutateStockDuringItemEdits = ($wasReceived && $willBeReceived);

            $saleReturnItemIds = $saleReturn->saleReturnItems->pluck('id')->toArray();
            $saleReturnItemOldIds = [];
            foreach ($prepared['items'] as $key => $preparedItem) {
                $editItemId = $preparedItem['sale_return_item_id'] ?? null;
                $saleReturnItemOldIds[$key] = $editItemId;

                $saleReturnItemArray = Arr::only($preparedItem, [
                    'sale_return_item_id',
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
                    'sold_quantity',
                    'sub_total',
                ]);

                if (! is_null($editItemId)) {
                    $this->updateItem($saleReturnItemArray, (int) $saleReturn->warehouse_id, $saleReturn, $mutateStockDuringItemEdits);
                } else {
                    unset($saleReturnItemArray['sale_return_item_id']);
                    $saleReturn->saleReturnItems()->create($saleReturnItemArray);
                    if ($mutateStockDuringItemEdits) {
                        $stockService->increase(
                            (int) $saleReturn->warehouse_id,
                            (int) $saleReturnItemArray['product_id'],
                            (float) $saleReturnItemArray['quantity'],
                            'sale_return_update_add',
                            SaleReturn::class,
                            (int) $saleReturn->id
                        );
                    }
                }
            }

            $removeItemIds = array_diff($saleReturnItemIds, array_filter($saleReturnItemOldIds, static fn ($v) => ! is_null($v)));
            if (! empty(array_values($removeItemIds))) {
                if ($mutateStockDuringItemEdits) {
                    foreach ($removeItemIds as $removeItemId) {
                        $oldProduct = $saleReturn->saleReturnItems()->whereKey($removeItemId)->first();
                        if (! $oldProduct) {
                            continue;
                        }
                        $stockService->reverse(
                            (int) $saleReturn->warehouse_id,
                            (int) $oldProduct->product_id,
                            -(float) $oldProduct->quantity,
                            'sale_return_update_reversal',
                            SaleReturn::class,
                            (int) $saleReturn->id,
                            null,
                            'Sale return item removed (' . ($saleReturn->reference_code ?? ('#' . $saleReturn->id)) . ')',
                            'Quantity must be less than Available quantity.'
                        );
                    }
                }
                $saleReturn->saleReturnItems()->whereIn('id', array_values($removeItemIds))->delete();
            }

            $saleReturn = $this->applyServerComputedSaleReturnUpdate($saleReturn, $input, $prepared['order']);

            // If transitioning PENDING -> RECEIVED, apply stock ONCE for all current items
            if (! $wasReceived && $willBeReceived) {
                $saleReturn->load('saleReturnItems');
                foreach ($saleReturn->saleReturnItems as $currentItem) {
                    $stockService->increase(
                        (int) $saleReturn->warehouse_id,
                        (int) $currentItem->product_id,
                        (float) $currentItem->quantity,
                        'sale_return',
                        SaleReturn::class,
                        (int) $saleReturn->id,
                        null,
                        'Sale return received (' . ($saleReturn->reference_code ?? ('#' . $saleReturn->id)) . ')'
                    );
                }
            }

            DB::commit();

            return $saleReturn->fresh('saleReturnItems');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            DB::rollBack();
            throw $e;
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    public function updateItem($saleReturnItem, $warehouseId, ?SaleReturn $saleReturn = null, bool $mutateStock = true): bool
    {
        try {
            $oldItem = $saleReturn
                ? $saleReturn->saleReturnItems()->whereKey($saleReturnItem['sale_return_item_id'])->first()
                : SaleReturnItem::whereKey($saleReturnItem['sale_return_item_id'])->whereHas('saleReturn')->first();
            if (! $oldItem) {
                throw new NotFoundHttpException('Sale return item not found.');
            }

            if ($mutateStock) {
                /** @var StockMovementService $stockService */
                $stockService = app(StockMovementService::class);
                $newQty = $stockService->assertPositiveQuantity($saleReturnItem['quantity'] ?? null, 'Return quantity must be greater than zero.');
                $oldQty = (float) $oldItem->quantity;
                if (abs($oldQty - $newQty) > 1e-9) {
                    $delta = $newQty - $oldQty; // positive adds more returned stock; negative reverses returned stock
                    $stockService->applyDelta(
                        (int) $warehouseId,
                        (int) $saleReturnItem['product_id'],
                        $delta,
                        $delta > 0 ? 'sale_return_update_add' : 'sale_return_update_reversal',
                        SaleReturn::class,
                        (int) ($saleReturn?->id ?? $oldItem->sale_return_id),
                        null,
                        null,
                        'Quantity must be less than Available quantity.'
                    );
                }
            }

            unset($saleReturnItem['sale_return_item_id'], $saleReturnItem['sale_return_id']);
            $oldItem->update($saleReturnItem);

            return true;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            throw $e;
        } catch (Exception $e) {
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    /**
     * Validate an explicitly submitted Sale Return paid_amount against server-computed grand_total.
     */
    private function validateSubmittedPaidAmount(mixed $rawPaidAmount, float $serverGrandTotal): float
    {
        $submittedPaidAmount = (float) $rawPaidAmount;
        if ($submittedPaidAmount < 0.0 || ! is_finite($submittedPaidAmount)) {
            throw new UnprocessableEntityHttpException('Paid amount cannot be negative.');
        }
        if (round($submittedPaidAmount, 6) > round($serverGrandTotal, 6)) {
            throw new UnprocessableEntityHttpException('Paid amount cannot exceed sale return grand total.');
        }

        return $submittedPaidAmount;
    }

    /**
     * Persist server-computed Sale Return header fields while preserving existing payment state
     * unless explicitly updated.
     */
    private function applyServerComputedSaleReturnUpdate(SaleReturn $saleReturn, array $input, array $orderTotals): SaleReturn
    {
        $saleReturnInputArray = Arr::only($input, [
            'payment_type',
            'note',
            'date',
            'status',
        ]);
        $saleReturnInputArray['tax_rate'] = $orderTotals['tax_rate'];
        $saleReturnInputArray['tax_amount'] = $orderTotals['tax_amount'];
        $saleReturnInputArray['discount'] = $orderTotals['discount'];
        $saleReturnInputArray['shipping'] = $orderTotals['shipping'];
        $saleReturnInputArray['grand_total'] = $orderTotals['grand_total'];

        // Validate explicit paid_amount first so invalid overpayments are always rejected 422 rather than silently capped
        $hasSubmittedPaidAmount = array_key_exists('paid_amount', $input)
            && $input['paid_amount'] !== null
            && $input['paid_amount'] !== '';
        $submittedPaidAmount = $hasSubmittedPaidAmount
            ? $this->validateSubmittedPaidAmount($input['paid_amount'], (float) $orderTotals['grand_total'])
            : null;

        // D4: Do not overwrite partial/existing payment_status or paid_amount during unrelated updates
        if (array_key_exists('payment_status', $input) && $input['payment_status'] !== null && $input['payment_status'] !== '') {
            $saleReturnInputArray['payment_status'] = (int) $input['payment_status'];
        }
        $effectivePaymentStatus = $saleReturnInputArray['payment_status'] ?? $saleReturn->payment_status;

        if ((int) $effectivePaymentStatus === SaleReturn::PAID) {
            $saleReturnInputArray['paid_amount'] = ($hasSubmittedPaidAmount && ($submittedPaidAmount > 0.0 || empty($saleReturn->paid_amount)))
                ? $submittedPaidAmount
                : $orderTotals['grand_total'];
        } elseif ($hasSubmittedPaidAmount) {
            if ($submittedPaidAmount > 0.0 || empty($saleReturn->paid_amount)) {
                $saleReturnInputArray['paid_amount'] = $submittedPaidAmount;
            }
        }

        $saleReturn->update($saleReturnInputArray);

        return $saleReturn;
    }

    /**
     * @return mixed
     */
    public function updateSaleReturnCalculation($input, $id)
    {
        $saleReturn = SaleReturn::findOrFail($id);
        $subTotalAmount = (float) $saleReturn->saleReturnItems()->sum('sub_total');
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

        return $this->applyServerComputedSaleReturnUpdate($saleReturn, $input, [
            'discount' => $discount,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'shipping' => $shipping,
            'grand_total' => $grandTotal,
        ]);
    }
}
