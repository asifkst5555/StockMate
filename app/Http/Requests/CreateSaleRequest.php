<?php

namespace App\Http\Requests;

use App\Models\Sale;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Class CreateSaleRequest
 */
class CreateSaleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $rules = \App\Rules\TenantExists::scopeRules(
            Sale::$rules,
            ['customer_id' => 'customers', 'warehouse_id' => 'warehouses'],
            ['sale_items.*.product_id' => 'products']
        );

        $rules['discount'] = 'nullable|numeric|min:0';

        return $rules;
    }
}
