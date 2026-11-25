<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Http\Requests\CreateTransferRequest;
use App\Http\Requests\UpdateTransferRequest;
use App\Http\Resources\TransferCollection;
use App\Http\Resources\TransferResource;
use App\Models\ManageStock;
use App\Models\Transfer;
use App\Models\TransferItem;
use App\Repositories\TransferRepository;
use App\Services\StockMovementService;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class TransferAPIController extends AppBaseController
{
    /** @var transferRepository */
    private $transferRepository;

    public function __construct(TransferRepository $transferRepository)
    {
        $this->transferRepository = $transferRepository;
    }

    public function index(Request $request): TransferCollection
    {
        $this->authorizeModuleAction('transfers', 'view');

        $perPage = getPageSize($request);
        $status = $request->get('status');
        $fromWarehouseId = $request->get('from_warehouse_id');
        $toWarehouseId = $request->get('to_warehouse_id');

        $this->transferRepository->resetScope();
        $this->transferRepository->scopeQuery(function ($query) use ($status, $fromWarehouseId, $toWarehouseId) {
            if ($status !== null && $status !== '' && $status !== 'null' && (int) $status > 0) {
                $query = $query->where('status', (int) $status);
            }
            if ($fromWarehouseId !== null && $fromWarehouseId !== '' && $fromWarehouseId !== 'null' && (int) $fromWarehouseId > 0) {
                $query = $query->where('from_warehouse_id', (int) $fromWarehouseId);
            }
            if ($toWarehouseId !== null && $toWarehouseId !== '' && $toWarehouseId !== 'null' && (int) $toWarehouseId > 0) {
                $query = $query->where('to_warehouse_id', (int) $toWarehouseId);
            }

            return $query;
        });

        try {
            $transfers = $this->transferRepository->paginate($perPage);
        } finally {
            $this->transferRepository->resetScope();
        }

        TransferResource::usingWithCollection();

        return new TransferCollection($transfers);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    public function store(CreateTransferRequest $request): TransferResource
    {
        $this->authorizeModuleAction('transfers', 'create');

        $input = $request->all();
        $transfer = $this->transferRepository->storeTransfer($input);

        return new TransferResource($transfer);
    }

    public function show(Transfer $transfer)
    {
        $this->authorizeModuleAction('transfers', 'view');

        $transfer = $transfer->load('transferItems.product');

        return new TransferResource($transfer);
    }

    public function edit(Transfer $transfer): TransferResource
    {
        $this->authorizeModuleAction('transfers', 'edit');

        $transfer = $transfer->load('transferItems.product.stocks', 'fromWarehouse', 'toWarehouse');

        return new TransferResource($transfer);
    }

    public function update(UpdateTransferRequest $request, $id): TransferResource
    {
        $this->authorizeModuleAction('transfers', 'edit');

        $input = $request->all();
        $transfer = $this->transferRepository->updateTransfer($input, $id);

        return new TransferResource($transfer);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Transfer  $transfer
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $this->authorizeModuleAction('transfers', 'delete');

        try {
            DB::beginTransaction();

            $transfer = Transfer::with('transferItems')
                ->where('id', $id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var StockMovementService $stockService */
            $stockService = app(StockMovementService::class);

            $pairs = [];
            foreach ($transfer->transferItems as $transferItem) {
                $pairs[] = ['warehouse_id' => $transfer->from_warehouse_id, 'product_id' => $transferItem->product_id];
                $pairs[] = ['warehouse_id' => $transfer->to_warehouse_id, 'product_id' => $transferItem->product_id];
            }
            $stockService->lockStockRows($pairs);

            $legacyStates = $this->transferRepository->inspectAndValidateLegacyState($transfer);

            foreach ($transfer->transferItems as $transferItem) {
                $state = $legacyStates[$transferItem->id];
                $productCost = \App\Models\Product::withoutGlobalScope('tenant')
                    ->whereKey($transferItem->product_id)
                    ->value('product_cost');

                $stockService->reconcileTransferItem(
                    (int) $transfer->from_warehouse_id,
                    (int) $transfer->to_warehouse_id,
                    (int) $transferItem->product_id,
                    $state['source_active'],
                    $state['dest_active'],
                    0.0,
                    0.0,
                    (int) $transfer->id,
                    $productCost !== null ? (float) $productCost : null,
                    $transfer->reference_code
                );
            }

            $this->transferRepository->delete($transfer->id);

            DB::commit();

            return $this->sendSuccess('Transfer delete successfully');
        } catch (ModelNotFoundException|HttpExceptionInterface $e) {
            DB::rollBack();
            throw $e;
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }
}
