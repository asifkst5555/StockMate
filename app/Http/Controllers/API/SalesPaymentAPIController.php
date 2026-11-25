<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Http\Requests\CreateSalePaymentRequest;
use App\Http\Resources\SalesPaymentResource;
use App\Models\Sale;
use App\Models\SalesPayment;
use App\Repositories\SalesPaymentRepository;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class SalesPaymentAPIController extends AppBaseController
{
    /** @var SalesPaymentRepository */
    private $salesPaymentRepository;

    /**
     * SalesPaymentAPIController constructor.
     */
    public function __construct(SalesPaymentRepository $salesPaymentRepository)
    {
        $this->salesPaymentRepository = $salesPaymentRepository;
    }

    /**
     * @return array
     */
    public function getAllPayments(Sale $sale)
    {
        $data = [
            'sale_id' => $sale->id,
            'data' => $sale->payments,
        ];

        return $data;
    }

    public function createSalePayment(Sale $sale, CreateSalePaymentRequest $request): SalesPaymentResource
    {
        $input = $request->all();

        $salePayment = $this->salesPaymentRepository->storeSalePayment($input, $sale);

        return new SalesPaymentResource($salePayment);
    }

    public function updateSalePayment(SalesPayment $salesPayment, Request $request)
    {
        // SEC-A2: Verify parent Sale belongs to current tenant (Sale uses Multitenantable)
        $sale = Sale::whereKey($salesPayment->sale_id)->first();
        if (! $sale) {
            return $this->sendError('Sales payment not found.', 404);
        }

        // Prevent reassigning a payment to another sale_id via request payload
        $input = Arr::except($request->all(), ['sale_id']);

        $salePayment = $this->salesPaymentRepository->updateSalePayment($input, $salesPayment);

        return new SalesPaymentResource($salePayment);
    }

    public function deletePayment($id)
    {
        try {
            DB::beginTransaction();

            // SEC-A2: Resolve payment only if its parent Sale belongs to current tenant
            $salePayment = SalesPayment::whereKey($id)->whereHas('sale')->first();
            if (! $salePayment) {
                DB::rollBack();

                return $this->sendError('Sales payment not found.', 404);
            }

            $sale = Sale::whereKey($salePayment->sale_id)->firstOrFail();
            $saleID = $sale->id;

            $existAmount = $sale->payments()->sum('amount') - $salePayment->amount;

            $status = $existAmount <= 0 ? Sale::UNPAID : Sale::PARTIAL_PAID;

            $sale->update([
                'payment_status' => $status,
                'paid_amount' => $existAmount,
            ]);

            $salePayment->delete();

            $latestPayment = $sale->payments()->latest()->first();

            $sale->update([
                'payment_type' => ! empty($latestPayment) ? $latestPayment->payment_type : null,
            ]);

            DB::commit();

            return $this->sendSuccess('Payment deleted successfully');
        } catch (ModelNotFoundException|HttpExceptionInterface $e) {
            DB::rollBack();
            throw $e;
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }
}
