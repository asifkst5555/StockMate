<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SecureFileController extends AppBaseController
{
    private const ALLOWED_EXTENSIONS = [
        'pdf',
        'xlsx',
        'xls',
        'csv',
        'png',
        'jpg',
        'jpeg',
        'webp',
        'txt',
    ];

    /**
     * Stream a tenant-isolated private document from storage/app/private (outside public_html).
     */
    public function download(Request $request, string $path): StreamedResponse|JsonResponse
    {
        // Reject raw null bytes or encoded traversal before and after iterative URL decoding
        $decoded = $path;
        for ($i = 0; $i < 3; $i++) {
            if (
                str_contains($decoded, "\0")
                || str_contains($decoded, '..')
                || str_contains($decoded, '\\')
            ) {
                return response()->json(['success' => false, 'message' => 'Invalid file path.'], 403);
            }
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }

        $normalizedPath = ltrim($decoded, '/');
        if (
            $normalizedPath === ''
            || str_contains($normalizedPath, "\0")
            || str_contains($normalizedPath, '..')
            || str_contains($normalizedPath, '\\')
            || ! preg_match('#^[A-Za-z0-9/_\.\-]+$#', $normalizedPath)
        ) {
            return response()->json(['success' => false, 'message' => 'Invalid file path.'], 403);
        }

        $tenantId = Auth::user()?->tenant_id;
        if (! $tenantId || ! str_starts_with($normalizedPath, $tenantId . '/')) {
            return response()->json(['success' => false, 'message' => 'Unauthorized file access.'], 403);
        }

        $extension = strtolower(pathinfo($normalizedPath, PATHINFO_EXTENSION));
        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return response()->json(['success' => false, 'message' => 'File type is not permitted for download.'], 403);
        }

        $disk = Storage::disk(config('filesystems.protected', 'local_protected'));
        if (! $disk->exists($normalizedPath)) {
            return response()->json(['success' => false, 'message' => 'File not found.'], 404);
        }

        // Verify resolved realpath stays strictly inside the private storage root
        $rootReal = realpath($disk->path(''));
        $fileReal = realpath($disk->path($normalizedPath));
        if ($rootReal === false || $fileReal === false || ! str_starts_with($fileReal, $rootReal . DIRECTORY_SEPARATOR)) {
            return response()->json(['success' => false, 'message' => 'Invalid file location.'], 403);
        }

        return $disk->download($normalizedPath, basename($normalizedPath), [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}
