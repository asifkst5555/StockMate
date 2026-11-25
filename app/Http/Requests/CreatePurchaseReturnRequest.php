<?php

namespace App\Http\Requests;

use App\Models\PurchaseReturn;
use Illuminate\Foundation\Http\FormRequest;

class CreatePurchaseReturnRequest extends FormRequest
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
            PurchaseReturn::$rules,
            ['supplier_id' => 'suppliers', 'warehouse_id' => 'warehouses'],
            ['purchase_id' => 'purchases']
        );

        $rules['status'] = 'required|integer|in:1,2';
        $rules['payment_status'] = 'required|integer|in:1,2';
        $rules['payment_type'] = 'nullable|integer';
        $rules['tax_rate'] = 'nullable|numeric|min:0|max:100';
        $rules['tax_amount'] = 'nullable|numeric|min:0';
        $rules['discount'] = 'nullable|numeric|min:0';
        $rules['shipping'] = 'nullable|numeric|min:0';
        $rules['grand_total'] = 'nullable|numeric|min:0';
        $rules['received_amount'] = 'nullable|numeric|min:0';
        $rules['paid_amount'] = 'nullable|numeric|min:0';
        $rules['notes'] = 'nullable|string';
        $rules['note'] = 'nullable|string';
        $rules['purchase_return_items'] = 'required|array|min:1';
        $rules['purchase_return_items.*.purchase_return_item_id'] = 'nullable|integer';
        $rules['purchase_return_items.*.product_id'] = ['required', 'integer', new \App\Rules\TenantExists('products')];
        $rules['purchase_return_items.*.quantity'] = 'required|numeric|gt:0';
        $rules['purchase_return_items.*.product_cost'] = 'required|numeric|min:0';
        $rules['purchase_return_items.*.discount_value'] = 'nullable|numeric|min:0';
        $rules['purchase_return_items.*.tax_value'] = 'nullable|numeric|min:0|max:100';
        $rules['purchase_return_items.*.discount_type'] = 'nullable|integer|in:1,2';
        $rules['purchase_return_items.*.tax_type'] = 'nullable|integer|in:1,2';
        $rules['purchase_return_items.*.purchase_unit'] = 'nullable|integer';

        return $rules;
    }
}
