<?php

namespace App\Http\Requests;

use App\Models\Adjustment;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAdjustmentRequest extends FormRequest
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
            Adjustment::$rules,
            ['warehouse_id' => 'warehouses'],
            []
        );

        $rules['adjustment_items'] = 'required|array|min:1';
        $rules['adjustment_items.*.adjustment_item_id'] = 'nullable|integer|distinct';
        $rules['adjustment_items.*.product_id'] = ['required', 'integer', 'distinct', new \App\Rules\TenantExists('products')];
        $rules['adjustment_items.*.quantity'] = 'required|numeric|gt:0';
        $rules['adjustment_items.*.method_type'] = 'required|integer|in:1,2';

        return $rules;
    }
}
