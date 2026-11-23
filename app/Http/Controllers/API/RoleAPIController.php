<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Http\Requests\CreateRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Http\Resources\RoleCollection;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStore;
use App\Repositories\RoleRepository;
use App\Services\UserAuthorizationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RoleAPIController extends AppBaseController
{
    /**
     * @var RoleRepository
     */
    private $roleRepository;

    /**
     * @var UserAuthorizationService
     */
    private $authService;

    public function __construct(RoleRepository $roleRepository, UserAuthorizationService $authService)
    {
        $this->roleRepository = $roleRepository;
        $this->authService = $authService;
    }

    public function index(Request $request): RoleCollection
    {
        $perPage = getPageSize($request);
        /** @var User $user */
        $user = Auth::user();

        // SEC-11: Do not expose global Super Admin or global Admin roles in tenant assignable role lists
        $allowedTenants = $this->authService->authorizedTenantIds($user);
        $roles = $this->roleRepository->withoutGlobalScope('tenant')
            ->whereIn('tenant_id', $allowedTenants)
            ->whereNotIn('name', [Role::SUPER_ADMIN, Role::ADMIN])
            ->paginate($perPage);

        RoleResource::usingWithCollection();

        return new RoleCollection($roles);
    }

    public function store(CreateRoleRequest $request)
    {
        $input = $request->only(['name', 'permissions']);
        try {
            $this->authService->assertCanManageRolePermissions(
                Auth::user(),
                (array) ($input['permissions'] ?? []),
                null,
                $input['name'] ?? null
            );
        } catch (AuthorizationException $e) {
            return $this->sendError($e->getMessage(), 403);
        }

        $role = $this->roleRepository->storeRole($input);

        return new RoleResource($role);
    }

    public function show($role)
    {
        $roleModel = Role::withoutGlobalScope('tenant')->findOrFail($role);
        try {
            $this->authService->assertRoleAccessibleByActor(Auth::user(), $roleModel, false);
        } catch (AuthorizationException $e) {
            return $this->sendError($e->getMessage(), 403);
        }

        return new RoleResource($roleModel);
    }

    /**
     * @return RoleResource|JsonResponse
     */
    public function update(UpdateRoleRequest $request, $role)
    {
        $roleModel = Role::withoutGlobalScope('tenant')->findOrFail($role);
        $input = $request->only(['name', 'permissions']);

        try {
            $this->authService->assertRoleAccessibleByActor(Auth::user(), $roleModel, false);
            $this->authService->assertCanManageRolePermissions(
                Auth::user(),
                (array) ($input['permissions'] ?? []),
                $roleModel,
                $input['name'] ?? null
            );
        } catch (AuthorizationException $e) {
            return $this->sendError($e->getMessage(), 403);
        }

        $updatedRole = $this->roleRepository->updateRole($input, $roleModel->id);

        return new RoleResource($updatedRole);
    }

    public function destroy($id): JsonResponse
    {
        /** @var Role $role */
        $role = Role::withoutGlobalScope('tenant')->findOrFail($id);

        try {
            $this->authService->assertRoleAccessibleByActor(Auth::user(), $role, false);
        } catch (AuthorizationException $e) {
            return $this->sendError($e->getMessage(), 403);
        }

        $usersCount = User::withoutGlobalScope('tenant')->role($role->name)->count();
        if ($role->users->count() > 0 || $usersCount > 0) {
            return $this->sendError(__('messages.error.role_cant_delete', ['role' => $role->display_name]));
        }
        $role->delete();

        return $this->sendSuccess('Role deleted successfully');
    }
}
