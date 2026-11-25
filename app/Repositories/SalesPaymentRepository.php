<?php

namespace App\Repositories;

use App\Models\Sale;
use App\Models\SalesPayment;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Class SalesPaymentRepository
 */
class SalesPaymentRepository extends BaseRepository
{
    /**
     * @var string[]
     */
    protected $fieldSearchable = [
        'payment_date',
        'payment_type',
        'amount',
    ];

    /**
     * @var string[]
     */
    protected $allowedFields = [
        'sale_id',
        'payment_date',
        'payment_type',
        'amount',
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
        return SalesPayment::class;
    }

    /**
     * @return mixed
     */
    public function storeSalePayment($input, $sale)
    {
        try {
            DB::beginTransaction();

            $existAnySalePayment = SalesPayment::whereSaleId($sale->id)->exists();

            $existAmount = 0;

            if ($existAnySalePayment) {
                $existAmount = SalesPayment::whereSaleId($sale->id)->sum('amount');
            }

            $saleAmount = $sale->grand_total;
            $payAmount = $input['amount'];
            $paidAmount = $existAmount + $payAmount;

            $paymentStatus = Sale::PARTIAL_PAID;

            if (($payAmount > 0) && ($paidAmount >= $saleAmount)) {
                $paymentStatus = Sale::PAID;
            }

            $sale->update([
                'payment_type' => SalesPayment::whereSaleId($sale->id)->latest()->first()->payment_type ?? null,
                'payment_status' => $paymentStatus,
                'paid_amount' => $paidAmount,
                'payment_type' => $input['payment_type'],
            ]);

            $input['sale_id'] = $sale->id;
            $salePayment = SalesPayment::create($input);

            DB::commit();

            return $salePayment;
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    /**
     * @return mixed
     */
    public function updateSalePayment($input, $salesPayment)
    {
        try {
            DB::beginTransaction();

            $sale = Sale::whereKey($salesPayment->sale_id)->firstOrFail();
            $existAmount = $sale->payments()->sum('amount');
            $saleAmount = $sale->grand_total;
            $payAmount = $input['amount'];
            $paidAmount = ($existAmount - $salesPayment->amount) + $payAmount;

            $paymentStatus = Sale::PARTIAL_PAID;

            if (($payAmount > 0) && ($paidAmount >= $saleAmount)) {
                $paymentStatus = Sale::PAID;
            }

            $salesPayment->update(Arr::except($input, ['sale_id']));

            $sale->update([
                'payment_type' => $sale->payments()->latest()->first()->payment_type ?? null,
                'payment_status' => $paymentStatus,
                'paid_amount' => $paidAmount,
            ]);

            DB::commit();

            return $salesPayment;
        } catch (ModelNotFoundException|HttpExceptionInterface $e) {
            DB::rollBack();
            throw $e;
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }
}
