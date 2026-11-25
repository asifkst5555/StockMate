<?php

namespace App\Http\Middleware;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Minimal paths a Store Admin may still call when their active store is disabled
     * (so they can switch to an active store or renew their subscription).
     */
    private const DISABLED_STORE_ALLOWED_PATHS = [
        'api/stores',
        'api/change-store/*',
        'api/config',
        'api/permissions',
        'api/subscriptions',
        'api/plans',
        'api/current-plan',
        'api/logout',
    ];

    /**
     * Reject authenticated requests for deactivated accounts (status != 1),
     * sub-users whose owning Store Admin is suspended (P0-E / SEC-07),
     * or users operating on a disabled store, and revoke active tokens on suspension.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // 1. Direct user deactivation
        if (! $user->status) {
            return $this->rejectAndRevoke($request, $user);
        }

        // 2. Super Admins are never blocked by tenant store/owner checks
        if ($user->hasRole(Role::SUPER_ADMIN)) {
            return $next($request);
        }

        // 3. P0-E (SEC-07): Check owning Store Admin status & active store status
        if (! empty($user->tenant_id)) {
            $activeStore = Store::where('tenant_id', $user->tenant_id)->first();
            if ($activeStore) {
                $owner = ((int) $activeStore->user_id === (int) $user->id)
                    ? $user
                    : User::withoutGlobalScopes()->find($activeStore->user_id);

                // If the owning Store Admin is suspended, block ALL sub-users and revoke their token
                if ($owner && ! $owner->status) {
                    return $this->rejectAndRevoke($request, $user);
                }

                // If the active store is disabled, block non-owner sub-users and non-allowlisted requests
                if (! $activeStore->status && ! $this->isAllowedDisabledStorePath($request)) {
                    return response()->json([
                        'success' => false,
                        'message' => __('messages.error.status_inactive'),
                    ], 403);
                }
            }
        }

        return $next($request);
    }

    private function rejectAndRevoke(Request $request, $user): Response
    {
        $token = $user->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'success' => false,
            'message' => __('messages.error.status_inactive'),
        ], 401);
    }

    private function isAllowedDisabledStorePath(Request $request): bool
    {
        foreach (self::DISABLED_STORE_ALLOWED_PATHS as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }
}
