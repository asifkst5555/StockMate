<?php

namespace App\Http\Controllers\Sadmin;

use App\Http\Controllers\AppBaseController;
use App\Http\Resources\SubscriptionCollection;
use App\Http\Resources\SubscriptionResource;
use App\Http\Resources\TransactionCollection;
use App\Http\Resources\TransactionResource;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserStore;
use App\Repositories\SubscriptionRepository;
use App\Repositories\TransactionRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * Class SubscriptionAPIController
 */
class SubscriptionAPIController extends AppBaseController
{
    /** @var SubscriptionRepository */
    private $subscriptionRepository;

    public function __construct(SubscriptionRepository $subscriptionRepository)
    {
        $this->subscriptionRepository = $subscriptionRepository;
    }

    public function getActiveSubscriptions(Request $request): SubscriptionCollection
    {
        $perPage = getPageSize($request);
        $subscriptions = $this->subscriptionRepository->getActiveSubscriptions($perPage);

        SubscriptionResource::usingWithCollection();

        return new SubscriptionCollection($subscriptions);
    }

    public function getCashPaymentRequests(Request $request): SubscriptionCollection
    {
        $perPage = getPageSize($request);
        $subscriptions = $this->subscriptionRepository->getManualSubscriptions($perPage);
        SubscriptionResource::usingWithCollection();

        return new SubscriptionCollection($subscriptions);
    }

    public function show(Subscription $subscription): SubscriptionResource
    {
        return new SubscriptionResource($subscription);
    }

    public function updateStatus(Request $request, Subscription $subscription)
    {
        $validated = $request->validate([
            'status' => 'required|integer|in:' . Subscription::ACTIVE . ',' . Subscription::REJECTED,
        ]);

        if ($subscription->status != Subscription::PENDING) {
            return $this->sendError('Status can\'t be updated', 422);
        }

        $targetStatus = (int) $validated['status'];

        \Illuminate\Support\Facades\DB::transaction(function () use ($subscription, $targetStatus) {
            $lockedSub = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            if ($lockedSub->status != Subscription::PENDING) {
                throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException('Status can\'t be updated');
            }

            if ($targetStatus === Subscription::ACTIVE) {
                $storeUser = User::withoutGlobalScope('tenant')->find($lockedSub->user_id);
                $userStores = Store::where('user_id', $lockedSub->user_id)->get();

                if ($lockedSub->data) {
                    $decodedData = json_decode($lockedSub->data);
                    $dataActiveStore = null;
                    $activeStoreTenantId = $decodedData->active_store ?? null;
                    $dataStores = $decodedData->stores ?? [];
                    if ($activeStoreTenantId) {
                        $dataActiveStore = Store::where('tenant_id', $activeStoreTenantId)->first();
                    }

                    if ($dataStores) {
                        Store::where('user_id', $lockedSub->user_id)
                            ->whereNotIn('id', $dataStores)
                            ->update(['status' => 0]);

                        if ($dataActiveStore && $storeUser) {
                            $dataActiveStore->update(['status' => 1]);
                            $storeUser->update(['tenant_id' => $dataActiveStore->tenant_id]);
                        }

                        $disabledStores = Store::where('user_id', $lockedSub->user_id)->where('status', 0)->get();
                        foreach ($disabledStores as $store) {
                            UserStore::where('store_id', $store->id)->delete();
                            $disabledStoresUsers = User::withoutGlobalScope('tenant')->where('tenant_id', $store->tenant_id)->get();
                            foreach ($disabledStoresUsers as $disabledStoreUser) {
                                $firstActiveStore = UserStore::where('user_id', $disabledStoreUser->id)->first();
                                if ($firstActiveStore && $firstActiveStore->store && $firstActiveStore->store->tenant_id) {
                                    $disabledStoreUser->update(['tenant_id' => $firstActiveStore->store->tenant_id]);
                                }
                            }
                        }
                    }
                }

                $activeStores = Store::where('user_id', $lockedSub->user_id)->where('status', 1)->get();
                $planLimit = (int) ($lockedSub->plan->no_of_stores ?? 1);
                $override = ($storeUser && $storeUser->store_limit_override !== null)
                    ? (int) $storeUser->store_limit_override
                    : null;
                $effectiveLimit = $override !== null ? $override : $planLimit;

                // SEC-A3: Respect unlimited (-1) plans & fix == comparison bug
                if ($effectiveLimit !== \App\Services\StoreLimitService::UNLIMITED && $effectiveLimit >= 0 && $activeStores->count() > $effectiveLimit) {
                    $storeIdsToDisable = $activeStores->sortBy('id')->pluck('id')->slice($effectiveLimit)->values()->toArray();
                    $disableStores = Store::whereIn('id', $storeIdsToDisable)->get();
                    $fallbackActive = $activeStores->sortBy('id')->first();
                    foreach ($disableStores as $store) {
                        if ($storeUser && $storeUser->tenant_id == $store->tenant_id && $fallbackActive) {
                            $storeUser->update(['tenant_id' => $fallbackActive->tenant_id]);
                        }
                        $store->status = 0;
                        $store->save();
                    }
                }
            }

            $lockedSub->update([
                'status' => $targetStatus,
                'is_paid' => $targetStatus === Subscription::ACTIVE ? 1 : 0,
            ]);

            if ($lockedSub->status == Subscription::ACTIVE) {
                Subscription::where('user_id', $lockedSub->user_id)
                    ->whereNot('id', $lockedSub->id)
                    ->where('status', Subscription::ACTIVE)
                    ->update(['status' => Subscription::INACTIVE]);
            }
        });

        return $this->sendSuccess(__('messages.success.status_updated'));
    }

    public function endDateUpdate(Request $request, Subscription $subscription)
    {
        $validated = $request->validate([
            'end_date' => [
                'required',
                'date',
                'after:' . Carbon::now()->toDateTimeString(),
            ],
        ]);

        $subscription->update([
            'end_date' => $validated['end_date'],
        ]);

        return $this->sendSuccess('Subscription end date updated successfully');
    }

    public function getTransactions(Request $request): TransactionCollection
    {
        $perPage = getPageSize($request);
        $transactions = App::make(TransactionRepository::class)->getTransactions($perPage);
        TransactionResource::usingWithCollection();

        return new TransactionCollection($transactions);
    }
}
