<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserAuthorizationService
{
    /**
     * Resolve all store IDs the authenticated actor is authorized to manage/assign.
     *
     * @return array<int, int>
     */
    public function authorizedStoreIds(User $actor): array
    {
        if ($actor->hasRole(Role::SUPER_ADMIN)) {
            return Store::pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        if ($actor->hasRole(Role::ADMIN)) {
            $owned = Store::where('user_id', $actor->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
            if (! empty($owned)) {
                return $owned;
            }
        }

        $assigned = UserStore::where('user_id', $actor->id)->pluck('store_id')->map(fn ($id) => (int) $id)->all();
        if (! empty($assigned)) {
            return $assigned;
        }

        if (! empty($actor->tenant_id)) {
            return Store::where('tenant_id', $actor->tenant_id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return [];
    }

    /**
     * Resolve all tenant IDs belonging to the actor's authorized stores.
     *
     * @return array<int, string>
     */
    public function authorizedTenantIds(User $actor): array
    {
        $storeIds = $this->authorizedStoreIds($actor);
        $tenants = [];

        if (! empty($storeIds)) {
            $tenants = Store::whereIn('id', $storeIds)
                ->pluck('tenant_id')
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        if (empty($tenants) && ! empty($actor->tenant_id)) {
            $tenants = [$actor->tenant_id];
        }

        return $tenants;
    }

    /**
     * Normalize store IDs from comma-separated string or array into positive ints.
     *
     * @param  mixed  $stores
     * @return array<int, int>
     */
    public function parseStoreIds($stores): array
    {
        if (is_string($stores)) {
            $stores = explode(',', $stores);
        }

        if (! is_array($stores)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(fn ($id) => is_numeric(trim((string) $id)) ? (int) trim((string) $id) : 0, $stores),
            fn ($id) => $id > 0
        )));
    }

    /**
     * Validate that every submitted store ID belongs to the actor's authorized stores.
     *
     * @param  mixed  $stores
     * @return array<int, int>
     *
     * @throws AuthorizationException
     */
    public function assertStoresAuthorized(User $actor, $stores): array
    {
        $storeIds = $this->parseStoreIds($stores);
        if (empty($storeIds)) {
            throw new AuthorizationException('At least one authorized store must be assigned.');
        }

        $allowedStoreIds = $this->authorizedStoreIds($actor);
        $unauthorized = array_diff($storeIds, $allowedStoreIds);

        if (! empty($unauthorized)) {
            throw new AuthorizationException('You are not authorized to assign one or more selected stores.');
        }

        return $storeIds;
    }

    /**
     * Ensure a tenant actor can only assign non-admin roles belonging to their authorized tenants
     * and never escalate beyond their own permissions.
     *
     * @throws AuthorizationException
     */
    public function assertRoleAssignableByTenantUser(User $actor, int $roleId): Role
    {
        $role = Role::withoutGlobalScope('tenant')->with('permissions')->find($roleId);

        if (! $role) {
            throw new AuthorizationException('The selected role is invalid or unauthorized.');
        }

        if (in_array($role->name, [Role::SUPER_ADMIN, Role::ADMIN], true)) {
            throw new AuthorizationException('You are not authorized to assign administrative roles.');
        }

        if (! $actor->hasRole(Role::SUPER_ADMIN)) {
            $allowedTenants = $this->authorizedTenantIds($actor);
            if (empty($role->tenant_id) || ! in_array($role->tenant_id, $allowedTenants, true)) {
                throw new AuthorizationException('You are not authorized to assign roles from another tenant.');
            }

            if (! $actor->hasRole(Role::ADMIN)) {
                $actorPermissions = $actor->getAllPermissions()->pluck('name')->all();
                $rolePermissions = $role->permissions->pluck('name')->all();
                $extraPermissions = array_diff($rolePermissions, $actorPermissions);
                if (! empty($extraPermissions)) {
                    throw new AuthorizationException('You cannot assign a role with permissions you do not possess.');
                }
            }
        }

        return $role;
    }

    /**
     * Fetch a target user strictly within the actor's authorized tenant scope.
     *
     * @throws AuthorizationException
     */
    public function findAuthorizedTenantUserOrFail(User $actor, int $targetUserId): User
    {
        $target = User::withoutGlobalScope('tenant')->find($targetUserId);

        if (! $target) {
            abort(404, 'User not found.');
        }

        if ($actor->hasRole(Role::SUPER_ADMIN)) {
            return $target;
        }

        if ($target->hasRole(Role::SUPER_ADMIN)) {
            throw new AuthorizationException('You are not authorized to access Super Admin accounts.');
        }

        $allowedTenants = $this->authorizedTenantIds($actor);
        if (empty($target->tenant_id) || ! in_array($target->tenant_id, $allowedTenants, true)) {
            throw new AuthorizationException('You are not authorized to access users outside your store.');
        }

        return $target;
    }

    /**
     * Ensure the actor can edit, delete, toggle status, or reset password of the target tenant user.
     *
     * @throws AuthorizationException
     */
    public function assertCanMutateTenantUser(User $actor, User $target): void
    {
        if ((int) $actor->id === (int) $target->id) {
            throw new AuthorizationException(__('messages.error.user_cant_updated'));
        }

        if ($target->hasRole(Role::SUPER_ADMIN)) {
            throw new AuthorizationException('You are not authorized to modify Super Admin accounts.');
        }

        if (! $actor->hasRole(Role::SUPER_ADMIN) && $target->hasRole(Role::ADMIN)) {
            throw new AuthorizationException('You are not authorized to modify Store Admin accounts.');
        }
    }

    /**
     * Ensure a role belongs to the actor's authorized tenants (and is not a global protected role).
     *
     * @throws AuthorizationException
     */
    public function assertRoleAccessibleByActor(User $actor, Role $role, bool $allowGlobalRead = false): void
    {
        if ($actor->hasRole(Role::SUPER_ADMIN)) {
            return;
        }

        if ($role->name === Role::SUPER_ADMIN) {
            throw new AuthorizationException('You are not authorized to access the Super Admin role.');
        }

        if ($role->name === Role::ADMIN) {
            if ($allowGlobalRead) {
                return;
            }
            throw new AuthorizationException('Admin role cannot be modified or deleted.');
        }

        $allowedTenants = $this->authorizedTenantIds($actor);
        if (empty($role->tenant_id) || ! in_array($role->tenant_id, $allowedTenants, true)) {
            throw new AuthorizationException('You are not authorized to access roles belonging to another tenant.');
        }
    }

    /**
     * Protect Super Admin accounts against self-lockout and removal/deactivation of the last active Super Admin.
     *
     * @throws AuthorizationException
     */
    public function assertSuperAdminCanMutateTarget(User $actor, User $target, bool $isDeactivatingOrDeleting = true): void
    {
        if ((int) $actor->id === (int) $target->id && $isDeactivatingOrDeleting) {
            throw new AuthorizationException('You cannot delete or deactivate your own Super Admin account.');
        }

        if ($isDeactivatingOrDeleting && $target->hasRole(Role::SUPER_ADMIN) && (int) $target->status === 1) {
            $activeSuperAdmins = User::withoutGlobalScope('tenant')
                ->role(Role::SUPER_ADMIN)
                ->where('status', 1)
                ->count();

            if ($activeSuperAdmins <= 1) {
                throw new AuthorizationException('Cannot delete or deactivate the last active Super Admin account.');
            }
        }
    }

    /**
     * POST-01: Revoke all Sanctum personal access tokens and active DB sessions for a deactivated user.
     */
    public function revokeUserSessionsAndTokens(User $user): void
    {
        $user->tokens()->delete();

        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }
    }

    /**
     * POST-02: Prevent role self-escalation, reserved system role name collision,
     * and permission delegation above the actor's own permission ceiling.
     *
     * @param  array<int, int|string>  $permissions
     *
     * @throws AuthorizationException
     */
    public function assertCanManageRolePermissions(User $actor, array $permissions, ?Role $targetRole = null, ?string $newName = null): void
    {
        if ($newName !== null && in_array(strtolower(trim($newName)), [Role::SUPER_ADMIN, Role::ADMIN], true)) {
            throw new AuthorizationException('Reserved system role names cannot be used.');
        }

        if ($actor->hasRole(Role::SUPER_ADMIN)) {
            return;
        }

        // Prevent any non-Super Admin from editing their own assigned role
        if ($targetRole !== null && $actor->roles()->where('roles.id', $targetRole->id)->exists()) {
            throw new AuthorizationException('You cannot modify your own assigned role.');
        }

        $numericIds = [];
        $permNames = [];
        foreach ($permissions as $perm) {
            if (is_numeric($perm)) {
                $numericIds[] = (int) $perm;
            } elseif (is_string($perm) && trim($perm) !== '') {
                $permNames[] = trim($perm);
            }
        }

        $resolvedPermissions = Permission::query()
            ->when(! empty($numericIds), fn ($q) => $q->whereIn('id', $numericIds))
            ->when(! empty($permNames), fn ($q) => empty($numericIds) ? $q->whereIn('name', $permNames) : $q->orWhereIn('name', $permNames))
            ->get(['id', 'name']);

        // Reject unknown/manipulated permission IDs or names
        $resolvedIds = $resolvedPermissions->pluck('id')->map(fn ($id) => (int) $id)->all();
        $resolvedNames = $resolvedPermissions->pluck('name')->all();
        if (! empty(array_diff($numericIds, $resolvedIds)) || ! empty(array_diff($permNames, $resolvedNames))) {
            throw new AuthorizationException('One or more requested permissions are invalid.');
        }

        // Enforce permission delegation ceiling: actor cannot grant permissions they do not possess
        $actorPermissions = $actor->getAllPermissions()->pluck('name')->all();
        $unauthorizedPermissions = array_diff($resolvedNames, $actorPermissions);
        if (! empty($unauthorizedPermissions)) {
            throw new AuthorizationException('You cannot grant permissions that you do not possess.');
        }
    }
}
