<?php

namespace App\Http\Middleware;

use App\Models\Role;
use App\Models\Store;
use App\Models\Subscription;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CheckSubscription
{
    /**
     * Explicit, minimal GET allowlist for expired/missing subscriptions so the SPA
     * can render account context, language, and subscription renewal without redirect loops.
     * All business data GET and all write endpoints require an active unexpired subscription.
     */
    protected array $expiredAllowedGetRoutes = [
        'api/config',
        'api/permissions',
        'api/front-setting',
        'api/edit-profile',
        'api/languages',
        'api/languages/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user) {
            return response()->json([
                'error' => true,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($user->hasRole(Role::SUPER_ADMIN)) {
            return $next($request);
        }

        if ($request->isMethod('GET') && $this->matchesAllowedExpiredRoute($request)) {
            return $next($request);
        }

        if ($user->hasRole(Role::ADMIN) && $user->subscriptions->count() > 0) {
            if ($this->hasActiveSubscription($user->id)) {
                return $next($request);
            }
        } else {
            $store = Store::where('tenant_id', $user->tenant_id)->first();
            if ($store && $this->hasActiveSubscription($store->user_id)) {
                return $next($request);
            }
        }

        return response()->json([
            'error' => true,
            'message' => 'You must have an active subscription to access this resource.',
        ], 403);
    }

    private function hasActiveSubscription($userId): bool
    {
        $activeSubscription = Subscription::where('user_id', $userId)
            ->where('status', Subscription::ACTIVE)
            ->where('end_date', '>=', now())
            ->first();

        return ! empty($activeSubscription);
    }

    private function matchesAllowedExpiredRoute(Request $request): bool
    {
        foreach ($this->expiredAllowedGetRoutes as $pattern) {
            if (Str::is($pattern, $request->path())) {
                return true;
            }
        }

        return false;
    }
}
