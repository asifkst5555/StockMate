<?php

namespace App\Repositories;

use App\Models\Plan;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserStore;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Class SubscriptionRepository
 */
class SubscriptionRepository extends BaseRepository
{
    protected $fieldSearchable = [
        'transaction_id',
        'plan_amount',
        'payable_amount',
        'plan_frequency',
        'status',
    ];

    /**
     * {@inheritDoc}
     */
    public function getFieldsSearchable()
    {
        return $this->fieldSearchable;
    }

    /**
     * {@inheritDoc}
     */
    public function model()
    {
        return Subscription::class;
    }

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator|\Illuminate\Support\Collection|mixed
     */
    public function getActiveSubscriptions($perPage)
    {
        return $this->where('status', Subscription::ACTIVE)->with('plan.currency')->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator|\Illuminate\Support\Collection|mixed
     */
    public function getManualSubscriptions($perPage)
    {
        return $this->where('payment_type', Subscription::TYPE_MANUAL)->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator|\Illuminate\Support\Collection|mixed
     */
    public function getUserSubscriptions($perPage)
    {
        return $this->where('user_id', Auth::id())->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Authoritative server-side pricing & proration calculation from DB records.
     * Never trusts client-submitted amounts or discounts.
     *
     * @return array{plan_price: float, remaining_balance: float, payable_amount: float, currency_code: string}
     */
    public static function calculatePayableAmount(Plan $plan, int $userId): array
    {
        $planPrice = round((float) $plan->price, 2);
        if ($planPrice < 0) {
            throw new UnprocessableEntityHttpException('Invalid plan price.');
        }

        $remainingBalance = 0.0;
        $currentSubscription = Subscription::where('user_id', $userId)
            ->where('status', Subscription::ACTIVE)
            ->first();

        if (! empty($currentSubscription) && ! $currentSubscription->isExpired()) {
            $start = Carbon::parse($currentSubscription->start_date);
            $end = Carbon::parse($currentSubscription->end_date);
            $totalDays = max(1, (int) $start->diffInDays($end));
            $usedDays = max(0, (int) round(abs(Carbon::now()->diffInDays($start))));
            $perDayPrice = round(((float) $currentSubscription->plan_amount) / $totalDays, 2);
            $remainingBalance = max(0.0, round(((float) $currentSubscription->plan_amount) - ($perDayPrice * $usedDays), 2));
        }

        $payableAmount = max(0.0, round($planPrice - $remainingBalance, 2));
        $currencyCode = strtoupper($plan->currency->code ?? 'USD');

        return [
            'plan_price' => $planPrice,
            'remaining_balance' => $remainingBalance,
            'payable_amount' => $payableAmount,
            'currency_code' => $currencyCode,
        ];
    }

    public static function createSubscription($data)
    {
        try {
            DB::beginTransaction();

            $plan = Plan::find($data['plan_id'] ?? null);
            if (! $plan) {
                throw new NotFoundHttpException('Plan not found.');
            }

            $user_id = isset($data['user_id']) ? (int) $data['user_id'] : (int) Auth::id();
            if ($user_id <= 0) {
                throw new UnprocessableEntityHttpException('Valid user is required.');
            }

            $notes = $data['notes'] ?? null;
            $attachment = $data['attachment'] ?? null;
            $paymentType = isset($data['payment_type']) ? (int) $data['payment_type'] : null;
            $transactionId = $data['transaction_id'] ?? null;
            $storeIds = $data['stores'] ?? null;
            $isTrustedGateway = (bool) ($data['verified_gateway_payment'] ?? false);
            $isAdminAssigned = (bool) ($data['admin_assigned'] ?? false);

            $gatewayTypes = [
                Subscription::TYPE_STRIPE,
                Subscription::TYPE_PAYPAL,
                Subscription::TYPE_RAZORPAY,
                Subscription::TYPE_PAYSTACK,
            ];

            // Lock existing active subscriptions for this user to prevent concurrent duplicate activation
            $currentSubscription = Subscription::where('user_id', $user_id)
                ->where('status', Subscription::ACTIVE)
                ->lockForUpdate()
                ->first();

            // SEC-A3: Free plan eligibility & duplicate Free activation handling
            if ($paymentType === Subscription::TYPE_FREE && ! $isAdminAssigned) {
                if ((float) $plan->price > 0) {
                    throw new UnprocessableEntityHttpException('Paid plans cannot be activated as free subscriptions.');
                }
                if ($currentSubscription
                    && (int) $currentSubscription->plan_id === (int) $plan->id
                    && ! $currentSubscription->isExpired()) {
                    DB::commit();

                    return $currentSubscription;
                }
            }

            // SEC-A3: Gateway subscriptions require server-verified payment & unused PAID transaction owned by user
            if (in_array($paymentType, $gatewayTypes, true)) {
                if (! $isTrustedGateway || empty($transactionId)) {
                    throw new UnprocessableEntityHttpException('Verified gateway transaction is required.');
                }
                $tx = Transaction::whereKey($transactionId)
                    ->where('user_id', $user_id)
                    ->where('status', Transaction::PAID)
                    ->lockForUpdate()
                    ->first();
                if (! $tx) {
                    throw new UnprocessableEntityHttpException('Invalid or unpaid transaction.');
                }
                $alreadyUsed = Subscription::where('transaction_id', $tx->id)->exists();
                if ($alreadyUsed) {
                    throw new UnprocessableEntityHttpException('Transaction has already been used for a subscription.');
                }
            }

            $end_date = Carbon::now()->addDays(7);
            if ($plan->trial_days > 0) {
                $end_date = Carbon::now()->addDays($plan->trial_days);
            } else {
                if ($plan->frequency == Plan::WEEKLY) {
                    $end_date = Carbon::now()->addDays(7)->endOfDay();
                } elseif ($plan->frequency == Plan::MONTHLY) {
                    $end_date = Carbon::now()->addMonths(1)->endOfDay();
                } elseif ($plan->frequency == Plan::YEARLY) {
                    $end_date = Carbon::now()->addYears(1)->endOfDay();
                } elseif ($plan->frequency == Plan::UNLIMITED) {
                    $end_date = Carbon::now()->addYears(1)->endOfDay();
                }
            }

            if ($paymentType === Subscription::TYPE_MANUAL && $storeIds) {
                $owner = User::withoutGlobalScope('tenant')->find($user_id);
                $storeData = json_encode([
                    'active_store' => $owner?->tenant_id,
                    'stores' => $storeIds,
                ]);
            }

            $pricing = self::calculatePayableAmount($plan, $user_id);
            $payableAmount = $pricing['payable_amount'];

            // SEC-A3: Server-controlled status & payment state machine (never trust client status/is_paid)
            $status = Subscription::PENDING;
            $isPaid = 0;

            if ($isAdminAssigned && isset($data['status'])) {
                $status = (int) $data['status'];
                $isPaid = $status === Subscription::ACTIVE ? 1 : 0;
            } elseif (in_array($paymentType, $gatewayTypes, true) && $isTrustedGateway) {
                $status = Subscription::ACTIVE;
                $isPaid = 1;
            } elseif ($paymentType === Subscription::TYPE_FREE) {
                $payableAmount = 0;
                $status = Subscription::ACTIVE;
                $isPaid = 0;
            } elseif ($paymentType === Subscription::TYPE_MANUAL) {
                // Manual requests ALWAYS start PENDING until Super Admin approval (never self-activated)
                $status = Subscription::PENDING;
                $isPaid = 0;
            }

            $subscriptionData = [
                'user_id' => $user_id,
                'plan_id' => $plan->id,
                'transaction_id' => $transactionId,
                'payment_type' => $paymentType,
                'plan_amount' => $pricing['plan_price'],
                'payable_amount' => $payableAmount,
                'plan_frequency' => $plan->frequency,
                'start_date' => Carbon::now(),
                'end_date' => $end_date,
                'trial_ends_at' => $plan->trial_days > 0 ? $end_date : null,
                'status' => $status,
                'is_paid' => $isPaid,
                'notes' => $notes,
                'data' => $storeData ?? null,
            ];

            $subscription = Subscription::create($subscriptionData);

            // Deactivate previous active subscriptions only when new subscription is ACTIVE
            if ($subscription->status == Subscription::ACTIVE) {
                Subscription::where('user_id', $subscription->user_id)
                    ->whereNot('id', $subscription->id)
                    ->where('status', Subscription::ACTIVE)
                    ->update(['status' => Subscription::INACTIVE]);
                if ($storeIds) {
                    $userAllStores = Store::where('user_id', $subscription->user_id)->get();
                    foreach ($userAllStores as $store) {
                        if (! in_array($store->id, $storeIds)) {
                            $store->update(['status' => 0]);
                        }
                    }
                    $disabledStores = Store::where('user_id', $subscription->user_id)->where('status', 0)->get();
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

            // Add Attachment
            if ($attachment != null && ! empty($attachment)) {
                $subscription->addMedia($attachment)->toMediaCollection(Subscription::ATTACHMENT);
            }

            DB::commit();

            return $subscription;
        } catch (ModelNotFoundException|HttpExceptionInterface $e) {
            DB::rollBack();
            throw $e;
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }
}
