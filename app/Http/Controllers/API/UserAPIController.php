<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Http\Requests\CreateUserRequest;
use App\Http\Requests\UpdateChangePasswordRequest;
use App\Http\Requests\UpdateChangeUserPasswordRequest;
use App\Http\Requests\UpdateUserProfileRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserCollection;
use App\Http\Resources\UserResource;
use App\Models\POSRegister;
use App\Models\Role;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserStore;
use App\Repositories\UserRepository;
use App\Services\UserAuthorizationService;
use Carbon\Carbon;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Class UserAPIController
 */
class UserAPIController extends AppBaseController
{
    /** @var UserRepository */
    private $userRepository;

    /** @var UserAuthorizationService */
    private $authService;

    public function __construct(UserRepository $userRepository, UserAuthorizationService $authService)
    {
        $this->userRepository = $userRepository;
        $this->authService = $authService;
    }

    public function index(Request $request): UserCollection
    {
        $perPage = getPageSize($request);
        $users = $this->userRepository->getUsers($perPage);
        UserResource::usingWithCollection();

        return new UserCollection($users);
    }

    public function store(CreateUserRequest $request)
    {
        /** @var User $actor */
        $actor = Auth::user();
        $input = $request->only([
            'first_name',
            'last_name',
            'email',
            'phone',
            'region',
            'password',
            'role_id',
            'stores',
            'image',
        ]);

        try {
            if (! isset($input['role_id'])) {
                return $this->sendError('A valid non-administrative role is required.', 422);
            }
            $this->authService->assertRoleAssignableByTenantUser($actor, (int) $input['role_id']);
            $authorizedStoreIds = $this->authService->assertStoresAuthorized($actor, $input['stores'] ?? []);
            $input['stores'] = $authorizedStoreIds;
        } catch (AuthorizationException $e) {
            return $this->sendError($e->getMessage(), 403);
        }

        $user = $this->userRepository->storeUser($input);

        return new UserResource($user);
    }

    public function show($id)
    {
        try {
            $user = $this->authService->findAuthorizedTenantUserOrFail(Auth::user(), (int) $id);
        } catch (AuthorizationException $e) {
            return $this->sendError($e->getMessage(), 403);
        }

        return new UserResource($user);
    }

    /**
     * @return UserResource|JsonResponse
     */
    public function update(UpdateUserRequest $request, $user)
    {
        /** @var User $actor */
        $actor = Auth::user();

        try {
            $target = $this->authService->findAuthorizedTenantUserOrFail($actor, (int) $user);
            $this->authService->assertCanMutateTenantUser($actor, $target);

            $input = $request->only([
                'first_name',
                'last_name',
                'email',
                'phone',
                'region',
                'role_id',
                'stores',
                'image',
            ]);

            if (isset($input['role_id'])) {
                $this->authService->assertRoleAssignableByTenantUser($actor, (int) $input['role_id']);
            }
            $authorizedStoreIds = $this->authService->assertStoresAuthorized($actor, $input['stores'] ?? []);
            $input['stores'] = $authorizedStoreIds;
        } catch (AuthorizationException $e) {
            return $this->sendError($e->getMessage(), 403);
        }

        $updatedUser = $this->userRepository->updateUser($input, $target->id);

        return new UserResource($updatedUser);
    }

    public function destroy($user): JsonResponse
    {
        /** @var User $actor */
        $actor = Auth::user();

        try {
            $target = $this->authService->findAuthorizedTenantUserOrFail($actor, (int) $user);
            $this->authService->assertCanMutateTenantUser($actor, $target);
        } catch (AuthorizationException $e) {
            return $this->sendError($e->getMessage(), 403);
        }

        $target->delete();

        return $this->sendSuccess('User deleted successfully');
    }

    public function editProfile(): UserResource
    {
        $user = Auth::user();

        return new UserResource($user);
    }

    public function updateProfile(UpdateUserProfileRequest $request): UserResource
    {
        // SEC-04: Explicit allowlist prevents mass assignment of tenant_id, status, password, email_verified_at, or role_id
        $input = $request->only(['first_name', 'last_name', 'email', 'phone', 'image']);
        $updateUser = $this->userRepository->updateUserProfile($input);

        return new UserResource($updateUser);
    }

    public function changePassword(UpdateChangePasswordRequest $request): JsonResponse
    {
        $input = $request->only(['current_password', 'new_password', 'confirm_password']);
        try {
            $this->userRepository->updatePassword($input);

            return $this->sendSuccess(__('messages.success.password_updated'));
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }

    public function changeUserPassword(UpdateChangeUserPasswordRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = Auth::user();
        $input = $request->only(['user_id', 'password', 'confirm_password']);

        try {
            $target = $this->authService->findAuthorizedTenantUserOrFail($actor, (int) $input['user_id']);
            if ($actor->hasRole(Role::SUPER_ADMIN)) {
                if ((int) $actor->id === (int) $target->id || $target->hasRole(Role::SUPER_ADMIN)) {
                    throw new AuthorizationException(__('messages.error.user_cant_updated'));
                }
            } else {
                $this->authService->assertCanMutateTenantUser($actor, $target);
            }

            $this->userRepository->updateUserPassword($input);

            return $this->sendSuccess(__('messages.success.password_updated'));
        } catch (AuthorizationException $e) {
            return $this->sendError($e->getMessage(), 403);
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }

    public function updateLanguage(Request $request): JsonResponse
    {
        $language = $request->get('language');
        $user = Auth::user();
        $user->update([
            'language' => $language,
        ]);

        return $this->sendResponse($user->language, 'Language Updated Successfully');
    }

    public function config(Request $request)
    {
        $user = Auth::user();

        $checkSubscription = false;
        $storeModal = false;
        $isSuperAdmin = true;
        $isSubUser = false;

        if (!$user->hasRole(Role::SUPER_ADMIN)) {
            $isSuperAdmin = false;
            if ($user->hasRole(Role::ADMIN) && $user->subscriptions->count() > 0) {
                $activeSubscription = Subscription::where('user_id', $user->id)->where('status', Subscription::ACTIVE)->first();
                if (!empty($activeSubscription)) {
                    $checkSubscription = $activeSubscription->end_date <= Carbon::now();
                }
            } else {
                $isSubUser = true;
                $store = Store::where('tenant_id', $user->tenant_id)->first();
                $activeSubscription = Subscription::where('user_id', $store->user_id)->where('status', Subscription::ACTIVE)->first();
                if (!empty($activeSubscription)) {
                    $checkSubscription = $activeSubscription->end_date <= Carbon::now();
                }
                $userActiveStore = Store::where('tenant_id', $user->tenant_id)->where('status', 1)->first();
                $userStoreAvailable = $userActiveStore ? UserStore::where('user_id', $user->id)->where('store_id', $userActiveStore->id)->first() : null;
                if (!empty($userStoreAvailable)) {
                    $storeModal = false;
                } else {
                    $userStores = UserStore::where('user_id', $user->id)->get();
                    if ($userStores->count() > 0) {
                        foreach ($userStores as $userStore) {
                            if ($userStore->store->status == 1) {
                                $storeModal = false;
                                $user->update([
                                    'tenant_id' => $userStore->store->tenant_id
                                ]);
                                break;
                            } else {
                                $userStore->delete();
                                $storeModal = true;
                            }
                        }
                    } else {
                        $storeModal = true;
                    }
                }
            }
        }

        $userPermissions = $user->getAllPermissions()->pluck('name')->toArray();

        $currentSubscription = Subscription::where('user_id', $user->id)->where('status', Subscription::ACTIVE)->first();
        if (!empty($currentSubscription) && isset($currentSubscription->plan->planFeature)) {
            $subscriptionFeature = $currentSubscription->plan->planFeature;
            if (!$subscriptionFeature->pos_management) {
                $userPermissions = array_values(array_diff($userPermissions, ["manage_pos_screen"]));
            }
            if (!$subscriptionFeature->reports) {
                $userPermissions = array_values(array_diff($userPermissions, ["manage_reports"]));
            }
            if (!$subscriptionFeature->emails_support) {
                $userPermissions = array_values(array_diff($userPermissions, ["manage_email_templates"]));
            }
            if (!$subscriptionFeature->sms_support) {
                $userPermissions = array_values(array_diff($userPermissions, ["manage_sms_templates", "manage_sms_apis"]));
            }
            if (!$subscriptionFeature->inventory_management) {
                $userPermissions = array_values(array_diff($userPermissions, []));
            }
            if (!$subscriptionFeature->adjustments) {
                $userPermissions = array_values(array_diff($userPermissions, ["manage_adjustments"]));
            }
            if (!$subscriptionFeature->roles_permission) {
                $userPermissions = array_values(array_diff($userPermissions, ["manage_roles"]));
            }
        }

        $composerFile = file_get_contents(base_path('composer.json'));
        $composerData = json_decode($composerFile, true);
        $currentVersion = isset($composerData['version']) ? $composerData['version'] : '';
        $dateFormat = getSettingValue('date_format');

        $openRegister = POSRegister::where('user_id', Auth::id())
            ->whereNull('closed_at')
            ->exists();

        $storeLimitSummary = $user->hasRole(Role::ADMIN)
            ? app(\App\Services\StoreLimitService::class)->getStoreLimitSummary($user)
            : null;

        return $this->sendResponse([
            'store_name' => getActiveStoreName(),
            'permissions' => $userPermissions,
            'version' => $currentVersion,
            'date_format' => $dateFormat,
            'store_modal' => $storeModal,
            'is_super_admin' => $isSuperAdmin,
            'is_sub_user' => $isSubUser,
            'is_expired' => $checkSubscription,
            'no_of_stores' => $storeLimitSummary ? $storeLimitSummary['effective_store_limit'] : ($currentSubscription->plan->no_of_stores ?? 0),
            'plan_store_limit' => $storeLimitSummary['plan_store_limit'] ?? 0,
            'store_limit_override' => $storeLimitSummary['store_limit_override'] ?? null,
            'is_unlimited_stores' => $storeLimitSummary['is_unlimited'] ?? false,
            'active_store_count' => $storeLimitSummary['active_store_count'] ?? 0,
            'total_store_count' => $storeLimitSummary['total_store_count'] ?? 0,
            'remaining_store_slots' => $storeLimitSummary['remaining_store_slots'] ?? 0,
            'is_version' => getSadminSettingValue('show_version_on_footer'),
            'is_currency_right' => getSettingValue('is_currency_right'),
            'open_register' => $openRegister ? false : true,
        ], 'Config retrieved successfully.');
    }

    public function statusUpdate($user_id): JsonResponse
    {
        /** @var User $actor */
        $actor = Auth::user();

        try {
            $user = $this->authService->findAuthorizedTenantUserOrFail($actor, (int) $user_id);
            $this->authService->assertCanMutateTenantUser($actor, $user);
        } catch (AuthorizationException $e) {
            return $this->sendError($e->getMessage(), 403);
        }

        $user->status = $user->status == 1 ? 0 : 1;
        $user->save();

        if ((int) $user->status === 0) {
            $this->authService->revokeUserSessionsAndTokens($user);
        }

        return $this->sendSuccess(__('messages.success.status_updated'));
    }
}
