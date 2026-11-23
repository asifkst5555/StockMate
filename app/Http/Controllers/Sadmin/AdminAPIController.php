<?php

namespace App\Http\Controllers\Sadmin;

use App\Http\Controllers\AppBaseController;
use App\Http\Requests\CreateAdminRequest;
use App\Http\Requests\UpdateAdminRequest;
use App\Http\Resources\UserCollection;
use App\Http\Resources\UserResource;
use App\Models\MultiTenant;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Repositories\AdminRepository;
use App\Services\UserAuthorizationService;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Class AdminAPIController
 */
class AdminAPIController extends AppBaseController
{
    /** @var AdminRepository */
    private $adminRepository;

    /** @var UserAuthorizationService */
    private $authService;

    public function __construct(AdminRepository $adminRepository, UserAuthorizationService $authService)
    {
        $this->adminRepository = $adminRepository;
        $this->authService = $authService;
    }

    public function index(Request $request): UserCollection
    {
        $perPage = getPageSize($request);
        $admins = $this->adminRepository->getUsers($perPage);
        UserResource::usingWithCollection();

        return new UserCollection($admins);
    }

    public function superAdmins(Request $request): UserCollection
    {
        $perPage = getPageSize($request);
        $superAdmins = $this->adminRepository->getSuperAdmins($perPage);
        UserResource::usingWithCollection();

        return new UserCollection($superAdmins);
    }

    public function store(CreateAdminRequest $request): UserResource
    {
        $input = $request->only([
            'first_name',
            'last_name',
            'email',
            'phone',
            'region',
            'password',
            'plan_id',
            'image',
            'store_name',
            'store_code',
            'store_email',
            'store_phone',
            'store_address',
            'country',
            'currency',
        ]);
        $admin = $this->adminRepository->storeAdmin($input);

        return new UserResource($admin);
    }

    public function storeSuperAdmin(CreateAdminRequest $request): UserResource
    {
        $input = $request->only([
            'first_name',
            'last_name',
            'email',
            'phone',
            'region',
            'password',
            'image',
        ]);
        $superAdmin = $this->adminRepository->storeSuperAdmin($input);

        return new UserResource($superAdmin);
    }

    public function show($adminUser): UserResource
    {
        $user = User::withoutGlobalScope('tenant')->findOrFail($adminUser);
        return new UserResource($user);
    }

    /**
     * @return UserResource|JsonResponse
     */
    public function update(UpdateAdminRequest $request, $adminUser)
    {
        $user = User::withoutGlobalScope('tenant')->findOrFail($adminUser);
        $input = $request->only([
            'first_name',
            'last_name',
            'email',
            'phone',
            'region',
            'plan_id',
            'stores',
            'image',
        ]);
        $admin = $this->adminRepository->updateAdmin($input, $user);

        return new UserResource($admin);
    }

    public function destroy($adminUser): JsonResponse
    {
        try {
            $user = User::withoutGlobalScope('tenant')->findOrFail($adminUser);

            // SEC-09: Block self-deletion and deletion of the last active Super Admin
            $this->authService->assertSuperAdminCanMutateTarget(Auth::user(), $user, true);

            DB::beginTransaction();

            $stores = Store::where('user_id', $user->id)->get();
            foreach ($stores as $store) {
                $tenant = MultiTenant::where('id', $store->tenant_id)
                    ->where('store_id', $store->id)
                    ->first();
                if ($tenant) {
                    $tenant->deleteDatabaseIfExists();

                    $tenant->unsetEventDispatcher();
                    $tenant->forceDelete();
                }
                $store->delete();
            }

            $user->delete();

            DB::commit();
            return $this->sendSuccess('User deleted successfully');
        } catch (AuthorizationException $e) {
            return $this->sendError($e->getMessage(), 403);
        } catch (Exception $exception) {
            DB::rollBack();
            return $this->sendError($exception->getMessage());
        }
    }

    public function emailVerify($adminUser): JsonResponse
    {
        $user = User::withoutGlobalScope('tenant')->findOrFail($adminUser);
        $user->email_verified_at = now();
        $user->save();

        return $this->sendSuccess('Email verified successfully');
    }

    public function statusUpdate($adminUser): JsonResponse
    {
        $user = User::withoutGlobalScope('tenant')->findOrFail($adminUser);
        $isDeactivating = ((int) $user->status === 1);

        try {
            // SEC-09: Block self-deactivation and deactivation of the last active Super Admin
            $this->authService->assertSuperAdminCanMutateTarget(Auth::user(), $user, $isDeactivating);
        } catch (AuthorizationException $e) {
            return $this->sendError($e->getMessage(), 403);
        }

        $user->status = $isDeactivating ? 0 : 1;
        $user->save();

        if ($isDeactivating) {
            $this->authService->revokeUserSessionsAndTokens($user);
        }

        return $this->sendSuccess(__('messages.success.status_updated'));
    }

    /**
     * Super Admin only: Set, update, or remove (null) a per-Store-Admin store limit override.
     * Allowed values: null (revert to plan limit), -1 (unlimited), or integer >= 1.
     */
    public function updateStoreLimit(Request $request, $adminUser): JsonResponse
    {
        $validated = $request->validate([
            'store_limit_override' => 'nullable|integer|min:-1|max:10000|not_in:0',
        ]);

        $user = User::withoutGlobalScope('tenant')->findOrFail($adminUser);
        if (! $user->hasRole(Role::ADMIN)) {
            return $this->sendError('Store limit overrides apply only to Store Admin accounts.', 422);
        }

        $override = array_key_exists('store_limit_override', $validated) && $validated['store_limit_override'] !== null
            ? (int) $validated['store_limit_override']
            : null;

        $user->forceFill(['store_limit_override' => $override])->save();

        $summary = app(\App\Services\StoreLimitService::class)->getStoreLimitSummary($user->fresh());

        return $this->sendResponse($summary, 'Store creation limit updated successfully.');
    }
}
