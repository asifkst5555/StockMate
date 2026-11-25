<?php

namespace App\Services;

use App\Models\Role;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;

/**
 * Authoritative server-side SaaS PlanFeature resolution & enforcement (Phase C1.3).
 *
 * Resolves the owning Store Admin's active subscription for both Store Admins and sub-users,
 * while preserving Super Admin exemptions.
 */
class PlanFeatureService
{
    public const FEATURE_PERMISSION_MAP = [
        'pos_management' => ['manage_pos_screen'],
        'reports' => ['manage_reports'],
        'emails_support' => ['manage_email_templates'],
        'sms_support' => ['manage_sms_templates', 'manage_sms_apis'],
        'inventory_management' => [
            'manage_products',
            'view_products',
            'create_products',
            'edit_products',
            'delete_products',
            'manage_product_categories',
            'view_product_categories',
            'create_product_categories',
            'edit_product_categories',
            'delete_product_categories',
            'manage_brands',
            'view_brands',
            'create_brands',
            'edit_brands',
            'delete_brands',
            'manage_units',
            'view_units',
            'create_units',
            'edit_units',
            'delete_units',
            'manage_warehouses',
            'view_warehouses',
            'create_warehouses',
            'edit_warehouses',
            'delete_warehouses',
            'manage_variations',
            'view_variations',
            'create_variations',
            'edit_variations',
            'delete_variations',
            'manage_transfers',
            'view_transfers',
            'create_transfers',
            'edit_transfers',
            'delete_transfers',
        ],
        'adjustments' => [
            'manage_adjustments',
            'view_adjustments',
            'create_adjustments',
            'edit_adjustments',
            'delete_adjustments',
        ],
        'roles_permission' => ['manage_roles', 'view_roles', 'create_roles', 'edit_roles', 'delete_roles'],
    ];

    /**
     * Resolve the active, unexpired subscription governing a user (resolving owner for sub-users).
     */
    public function resolveActiveSubscription(User $user): ?Subscription
    {
        if ($user->hasRole(Role::SUPER_ADMIN)) {
            return null;
        }

        $ownerUserId = $this->resolveSubscriptionOwnerId($user);
        if (! $ownerUserId) {
            return null;
        }

        return Subscription::with('plan.planFeature')
            ->where('user_id', $ownerUserId)
            ->where('status', Subscription::ACTIVE)
            ->where('end_date', '>=', now())
            ->latest('id')
            ->first();
    }

    /**
     * Determine whether the user's governing subscription plan enables the requested feature flag.
     */
    public function hasFeature(User $user, string $feature): bool
    {
        if ($user->hasRole(Role::SUPER_ADMIN)) {
            return true;
        }

        $subscription = $this->resolveActiveSubscription($user);
        if (! $subscription || ! $subscription->plan) {
            return false;
        }

        $planFeature = $subscription->plan->planFeature;
        if (! $planFeature) {
            // Backward compatibility for plans created without an explicit plan_features row
            return true;
        }

        if (! array_key_exists($feature, self::FEATURE_PERMISSION_MAP)) {
            return false;
        }

        return (bool) $planFeature->{$feature};
    }

    /**
     * Return permission names restricted by the user's governing subscription plan features.
     *
     * @return string[]
     */
    public function getRestrictedPermissions(User $user): array
    {
        if ($user->hasRole(Role::SUPER_ADMIN)) {
            return [];
        }

        $subscription = $this->resolveActiveSubscription($user);
        if (! $subscription || ! $subscription->plan) {
            // Missing or expired subscription: restrict all paid-feature permissions in /api/config
            return array_values(array_unique(array_merge(...array_values(self::FEATURE_PERMISSION_MAP))));
        }

        $planFeature = $subscription->plan->planFeature;
        if (! $planFeature) {
            // Legacy/test plans without a plan_features row preserve backward-compatible access
            return [];
        }

        $restricted = [];

        foreach (self::FEATURE_PERMISSION_MAP as $featureFlag => $permissions) {
            if (! (bool) $planFeature->{$featureFlag}) {
                $restricted = array_merge($restricted, $permissions);
            }
        }

        return array_values(array_unique($restricted));
    }

    private function resolveSubscriptionOwnerId(User $user): ?int
    {
        if ($user->hasRole(Role::ADMIN) && $user->subscriptions()->exists()) {
            return (int) $user->id;
        }

        if (! empty($user->tenant_id)) {
            $store = Store::where('tenant_id', $user->tenant_id)->first();
            if ($store && $store->user_id) {
                return (int) $store->user_id;
            }
        }

        return (int) $user->id;
    }
}
