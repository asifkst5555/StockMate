<?php

namespace App\Services;

use App\Models\Role;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;

class StoreLimitService
{
    public const UNLIMITED = -1;

    /**
     * Compute the authoritative server-side store limit summary for a Store Admin user.
     *
     * Effective limit precedence:
     * 1. Customer-specific override (`users.store_limit_override`), when non-null (-1 = unlimited, >=1 = custom).
     * 2. Active subscription plan limit (`plans.no_of_stores`, -1 = unlimited, >=1 = plan limit).
     *
     * @return array<string, mixed>
     */
    public function getStoreLimitSummary(User $user): array
    {
        $activeSubscription = Subscription::with('plan')
            ->where('user_id', $user->id)
            ->where('status', Subscription::ACTIVE)
            ->latest('id')
            ->first();

        $plan = $activeSubscription?->plan;
        $planLimit = (int) ($plan?->no_of_stores ?? 0);
        $override = $user->store_limit_override !== null ? (int) $user->store_limit_override : null;

        $effectiveLimit = $override !== null ? $override : $planLimit;
        $isUnlimited = ($effectiveLimit === self::UNLIMITED);

        // Strictly count stores owned by this Store Admin (never global/other-tenant stores)
        $totalStores = Store::where('user_id', $user->id)->count();
        $activeStores = Store::where('user_id', $user->id)->where('status', 1)->count();

        $remainingSlots = $isUnlimited ? null : max(0, $effectiveLimit - $totalStores);
        $canCreateStore = $user->hasRole(Role::ADMIN) && ($isUnlimited || ($effectiveLimit > 0 && $totalStores < $effectiveLimit));

        return [
            'plan_name' => $plan?->name,
            'plan_store_limit' => $planLimit,
            'store_limit_override' => $override,
            'effective_store_limit' => $effectiveLimit,
            'is_unlimited' => $isUnlimited,
            'active_store_count' => $activeStores,
            'total_store_count' => $totalStores,
            'remaining_store_slots' => $remainingSlots,
            'can_create_store' => $canCreateStore,
        ];
    }

    /**
     * Check whether a Store Admin can activate another disabled store under their effective limit.
     */
    public function canActivateAdditionalStore(User $user): bool
    {
        $summary = $this->getStoreLimitSummary($user);
        if ($summary['is_unlimited']) {
            return true;
        }

        return $summary['effective_store_limit'] > 0
            && $summary['active_store_count'] < $summary['effective_store_limit'];
    }
}
