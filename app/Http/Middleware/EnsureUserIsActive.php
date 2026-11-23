<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Reject authenticated requests for deactivated accounts (status != 1)
     * and revoke any active Sanctum personal access token.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->status) {
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

        return $next($request);
    }
}
