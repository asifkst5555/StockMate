<?php

namespace App\Services;

use App\Models\Role;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Tenant-isolated protected export storage service (P0-B / SEC-08).
 *
 * Stores all generated PDFs and Excel exports on the private `local_protected` disk
 * (outside public_html) under `{tenant_id}/exports/{category}/{prefix}-{random}.{ext}`
 * and returns authenticated, tenant-verified URLs served via SecureFileController.
 */
class ProtectedExportService
{
    public static function diskName(): string
    {
        return (string) config('filesystems.protected', 'local_protected');
    }

    public static function buildProtectedPath(string $category, string $prefix, string $extension): string
    {
        $user = Auth::user();
        $tenantId = $user?->tenant_id;

        if (! $tenantId) {
            $tenantId = ($user && $user->hasRole(Role::SUPER_ADMIN)) ? 'global' : 'unscoped';
        }

        $safeTenant = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) $tenantId);
        $safeCategory = preg_replace('/[^A-Za-z0-9_\-]/', '_', $category);
        $safePrefix = preg_replace('/[^A-Za-z0-9_\-]/', '_', $prefix);
        $safeExt = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $extension));
        $nonce = bin2hex(random_bytes(8));

        return "{$safeTenant}/exports/{$safeCategory}/{$safePrefix}-{$nonce}.{$safeExt}";
    }

    public static function storePdf(string $prefix, string $pdfContent): string
    {
        $path = self::buildProtectedPath('pdf', $prefix, 'pdf');
        Storage::disk(self::diskName())->put($path, $pdfContent);

        return route('private-files.download', ['path' => $path]);
    }

    public static function storeExcel(object $export, string $prefix): string
    {
        $path = self::buildProtectedPath('excel', $prefix, 'xlsx');
        Excel::store($export, $path, self::diskName());

        return route('private-files.download', ['path' => $path]);
    }
}
