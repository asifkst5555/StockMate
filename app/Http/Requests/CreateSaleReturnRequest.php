<?php

namespace App\Http\Requests;

use App\Models\SaleReturn;
use Illuminate\Foundation\Http\FormRequest;

class CreateSaleReturnRequest extends FormRequest
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
            SaleReturn::$rules,
            ['customer_id' => 'customers', 'warehouse_id' => 'warehouses', 'sale_id' => 'sales'],
            []
        );

        $rules['status'] = 'required|integer|in:1,2';
        $rules['payment_status'] = 'nullable|integer|in:1,2';
        $rules['payment_type'] = 'nullable|integer';
        $rules['tax_rate'] = 'nullable|numeric|min:0|max:100';
        $rules['tax_amount'] = 'nullable|numeric|min:0';
        $rules['discount'] = 'nullable|numeric|min:0';
        $rules['shipping'] = 'nullable|numeric|min:0';
        $rules['grand_total'] = 'nullable|numeric|min:0';
        $rules['paid_amount'] = 'nullable|numeric|min:0';
        $rules['received_amount'] = 'nullable|numeric|min:0';
        $rules['note'] = 'nullable|string';
        $rules['notes'] = 'nullable|string';
        $rules['sale_return_items'] = 'required|array|min:1';
        $rules['sale_return_items.*.sale_return_item_id'] = 'nullable|integer';
        $rules['sale_return_items.*.product_id'] = ['required', 'integer', new \App\Rules\TenantExists('products')];
        $rules['sale_return_items.*.quantity'] = 'required|numeric|gt:0';
        $rules['sale_return_items.*.product_price'] = 'required|numeric|min:0';
        $rules['sale_return_items.*.discount_value'] = 'nullable|numeric|min:0';
        $rules['sale_return_items.*.tax_value'] = 'nullable|numeric|min:0|max:100';
        $rules['sale_return_items.*.discount_type'] = 'nullable|integer|in:1,2';
        $rules['sale_return_items.*.tax_type'] = 'nullable|integer|in:1,2';
        $rules['sale_return_items.*.sale_unit'] = 'nullable|integer';

        return $rules;
    }
}
