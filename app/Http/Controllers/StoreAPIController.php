<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateStoreRequest;
use App\Http\Requests\UpdateStoreRequest;
use App\Http\Resources\StoreCollection;
use App\Http\Resources\StoreResource;
use App\Models\MultiTenant;
use App\Models\Role;
use Illuminate\Http\Request;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserStore;
use App\Repositories\StoreRepository;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

class StoreAPIController extends AppBaseController
{
    /** @var StoreRepository */
    private $storeRepository;

    public function __construct(StoreRepository $storeRepository)
    {
        $this->storeRepository = $storeRepository;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): StoreCollection
    {
        $perPage = getPageSize($request);
        $userStores = UserStore::where('user_id', auth()->id())->pluck('store_id')->toArray();
        if (!empty($userStores)) {
            $stores = $this->storeRepository->whereIn('id', $userStores)->paginate($perPage);
        } else {
            $stores = $this->storeRepository->where('user_id', auth()->id())->paginate($perPage);
        }        
         
        StoreResource::usingWithCollection();

        return new StoreCollection($stores);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateStoreRequest $request)
    {
        /** @var User $user */
        $user = Auth::user();

        // Only Store Admin owners may create branch stores under their own tenant account
        if (! $user || ! $user->hasRole(Role::ADMIN)) {
            return $this->sendError('You are not authorized to create stores.', 403);
        }

        $limitSummary = app(\App\Services\StoreLimitService::class)->getStoreLimitSummary($user);
        if (! $limitSummary['can_create_store']) {
            return $this->sendError('Store limit reached for your current subscription plan.', 403);
        }

        $input = $request->only(['name']);

        $store = $this->storeRepository->store($input);

        return new StoreResource($store);
    }

    /**
     * Verify the authenticated user owns or is assigned to the given store.
     */
    private function authorizeStoreAccess(Store $store, bool $requireOwner = false): ?\Illuminate\Http\JsonResponse
    {
        $userId = auth()->id();

        if ((int) $store->user_id === (int) $userId) {
            return null;
        }

        if (! $requireOwner) {
            $isAssigned = UserStore::where('user_id', $userId)
                ->where('store_id', $store->id)
                ->exists();
            if ($isAssigned) {
                return null;
            }
        }

        return $this->sendError('Unauthorized access to this store.', 403);
    }

    /**
     * Display the specified resource.
     */
    public function show(Store $store)
    {
        if ($error = $this->authorizeStoreAccess($store)) {
            return $error;
        }

        Session::put('active_store', $store->id);

        return new StoreResource($store);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateStoreRequest $request, Store $store)
    {
        if ($error = $this->authorizeStoreAccess($store, true)) {
            return $error;
        }

        $input = $request->all();

        $store = $this->storeRepository->updateStore($input, $store->id);

        return new StoreResource($store);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Store $store)
    {
        if ($error = $this->authorizeStoreAccess($store, true)) {
            return $error;
        }

        try {
            $this->storeRepository->deleteStore($store->id);

            return $this->sendSuccess(__('messages.success.store_deleted'));
        } catch (Exception $exception) {
            return $this->sendError($exception->getMessage());
        }
    }

    public function changeStore(Store $store)
    {
        if ($error = $this->authorizeStoreAccess($store)) {
            return $error;
        }

        try {
            User::find(auth()->id())->update(['tenant_id' => $store->tenant_id]);

            return $this->sendSuccess(__('messages.success.active_store_changed'));
        } catch (Exception $exception) {
            return $this->sendError($exception->getMessage());
        }
    }

    public function changeStatus(Store $store)
    {
        if ($error = $this->authorizeStoreAccess($store, true)) {
            return $error;
        }

        try {
            if ($store->user == null) {
                return $this->sendError(__('messages.error.cannot_change_store_status'));
            }

            if ($store->tenant_id == $store->user->tenant_id) {
                return $this->sendError(__('messages.error.cannot_deactivate_active_store'));
            }

            if ($store->status) {
                $userStore = UserStore::where('store_id', $store->id)->exists();
                if ($userStore) {
                    return $this->sendError(__('messages.error.cannot_disable_already_assigned_store'));
                }
            } else {
                $storeLimitService = app(\App\Services\StoreLimitService::class);
                if (! $storeLimitService->canActivateAdditionalStore($store->user)) {
                    return $this->sendError(__('messages.error.cannot_activate_store_plan_limit'));
                }
            }

            $store->status = !$store->status;
            $store->save();

            return $this->sendSuccess(__('messages.success.store_status_changed'));
        } catch (Exception $exception) {
            return $this->sendError($exception->getMessage());
        }
    }
}
