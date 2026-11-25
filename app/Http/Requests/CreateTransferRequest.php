<?php

namespace App\Http\Requests;

use App\Models\Transfer;
use App\Rules\TenantExists;
use Illuminate\Foundation\Http\FormRequest;

class CreateTransferRequest extends FormRequest
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
        $rules = Transfer::$rules;

        $rules['from_warehouse_id'] = [
            'required',
            'integer',
            new TenantExists('warehouses'),
        ];

        $rules['to_warehouse_id'] = [
            'required',
            'integer',
            'different:from_warehouse_id',
            new TenantExists('warehouses'),
        ];

        $rules['order_tax'] = 'nullable|numeric|min:0|max:100';
        $rules['tax_rate'] = 'nullable|numeric|min:0|max:100';
        $rules['discount'] = 'nullable|numeric|min:0';
        $rules['shipping'] = 'nullable|numeric|min:0';

        $rules['transfer_items'] = 'required|array|min:1';
        $rules['transfer_items.*.product_id'] = [
            'required',
            'integer',
            'distinct',
            new TenantExists('products'),
        ];
        $rules['transfer_items.*.product_price'] = 'nullable|numeric|min:0';
        $rules['transfer_items.*.tax_type'] = 'required|integer|in:1,2';
        $rules['transfer_items.*.tax_value'] = 'nullable|numeric|min:0|max:100';
        $rules['transfer_items.*.discount_type'] = 'required|integer|in:1,2';
        $rules['transfer_items.*.discount_value'] = 'nullable|numeric|min:0';
        $rules['transfer_items.*.quantity'] = 'required|numeric|gt:0';

        return $rules;
    }
}
