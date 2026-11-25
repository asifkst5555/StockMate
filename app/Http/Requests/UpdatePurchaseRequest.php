<?php

namespace App\Http\Requests;

use App\Models\Purchase;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Class UpdatePurchaseRequest
 */
class UpdatePurchaseRequest extends FormRequest
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
            Purchase::$rules,
            ['supplier_id' => 'suppliers', 'warehouse_id' => 'warehouses'],
            []
        );

        $rules['status'] = 'required|integer|in:1,2,3';
        $rules['tax_rate'] = 'nullable|numeric|min:0|max:100';
        $rules['discount'] = 'nullable|numeric|min:0';
        $rules['shipping'] = 'nullable|numeric|min:0';
        $rules['received_amount'] = 'nullable|numeric|min:0';
        $rules['paid_amount'] = 'nullable|numeric|min:0';
        $rules['purchase_items'] = 'required|array|min:1';
        $rules['purchase_items.*.product_id'] = ['required', 'integer', 'distinct', new \App\Rules\TenantExists('products')];
        $rules['purchase_items.*.quantity'] = 'required|numeric|gt:0';
        $rules['purchase_items.*.product_cost'] = 'required|numeric|min:0';
        $rules['purchase_items.*.purchase_unit'] = ['nullable', 'integer', new \App\Rules\TenantExists('units')];

        return $rules;
    }

    public function messages(): array
    {
        return [
            'purchase_items.*.product_id.distinct' => 'Duplicate products are not allowed in purchase items.',
        ];
    }
}
