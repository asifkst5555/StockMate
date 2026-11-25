<?php

namespace App\Rules;

use App\Models\Role;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Tenant-scoped foreign-key `exists` validation rule (P0-C / SEC-05).
 *
 * Verifies that the submitted ID exists in `$table` AND belongs to the authenticated
 * user's active `tenant_id`. Preserves legitimate Super Admin operations.
 */
class TenantExists implements ValidationRule
{
    private string $table;
    private string $column;

    public function __construct(string $table, string $column = 'id')
    {
        $this->table = $table;
        $this->column = $column;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $user = Auth::user();
        $query = DB::table($this->table)->where($this->column, $value);

        if ($user && ! $user->hasRole(Role::SUPER_ADMIN)) {
            $tenantId = $user->tenant_id;
            if (empty($tenantId)) {
                $fail("The selected {$attribute} is invalid.");

                return;
            }
            $query->where('tenant_id', $tenantId);
        }

        if (! $query->exists()) {
            $fail("The selected {$attribute} is invalid.");
        }
    }

    /**
     * Helper to replace unscoped `exists:table,id` entries in Model::$rules with TenantExists.
     *
     * @param  array<string, mixed>  $baseRules
     * @param  array<string, string>  $requiredFields  field => table
     * @param  array<string, string>  $nullableFields  field => table (e.g. 'sale_items.*.product_id' => 'products')
     * @return array<string, mixed>
     */
    public static function scopeRules(array $baseRules, array $requiredFields, array $nullableFields = []): array
    {
        foreach ($requiredFields as $field => $table) {
            $baseRules[$field] = ['required', 'integer', new self($table)];
        }

        foreach ($nullableFields as $field => $table) {
            $baseRules[$field] = ['nullable', 'integer', new self($table)];
        }

        return $baseRules;
    }
}
