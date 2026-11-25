<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Utils\ResponseUtil;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Response;

/**
 * @SWG\Swagger(
 *   basePath="/api/v1",
 *
 *   @SWG\Info(
 *     title="Laravel Generator APIs",
 *     version="1.0.0",
 *   )
 * )
 * This class should be parent class for other API controllers
 * Class AppBaseController
 */
class AppBaseController extends Controller
{
    public function sendResponse($result, $message): JsonResponse
    {
        return Response::json(ResponseUtil::makeResponse($message, $result));
    }

    public function sendError($error, $code = 422): JsonResponse
    {
        return Response::json(ResponseUtil::makeError($error), $code);
    }

    public function sendSuccess($message): JsonResponse
    {
        return Response::json([
            'success' => true,
            'message' => $message,
        ], 200);
    }

    /**
     * Enforce granular module permissions (`view_*`, `create_*`, `edit_*`, `delete_*`)
     * for non-admin sub-users while preserving Store Admin / Super Admin and legacy
     * read-only `manage_*` compatibility when no granular permissions exist.
     *
     * @throws AuthorizationException
     */
    protected function authorizeModuleAction(string $module, string $action): void
    {
        $user = auth()->user();
        if (! $user) {
            throw new AuthorizationException('Unauthenticated.');
        }

        if ($user->hasRole([Role::SUPER_ADMIN, Role::ADMIN])) {
            return;
        }

        if ($user->can("{$action}_{$module}")) {
            return;
        }

        if ($action === 'view' && $user->can("manage_{$module}")) {
            $hasAnyGranular = $user->hasAnyPermission([
                "view_{$module}",
                "create_{$module}",
                "edit_{$module}",
                "delete_{$module}",
            ]);
            if (! $hasAnyGranular) {
                return;
            }
        }

        throw new AuthorizationException('User does not have the right permission.');
    }
}
