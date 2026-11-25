<?php

namespace App\Http\Middleware;

use App\Models\Role;
use App\Services\PlanFeatureService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces subscription PlanFeature flags server-side (Phase C1.3).
 */
class CheckPlanFeature
{
    public function __construct(private PlanFeatureService $planFeatureService)
    {
    }

    public function handle(Request $request, Closure $next, string $feature): Response
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

        if (! $this->planFeatureService->hasFeature($user, $feature)) {
            return response()->json([
                'error' => true,
                'message' => 'Your current subscription plan does not include access to this feature.',
            ], 403);
        }

        return $next($request);
    }
}
