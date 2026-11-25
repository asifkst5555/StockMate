<?php

namespace App\Http\Requests;

use App\Models\Quotation;
use Illuminate\Foundation\Http\FormRequest;

class UpdateQuotationRequest extends FormRequest
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
        return \App\Rules\TenantExists::scopeRules(
            Quotation::$rules,
            ['customer_id' => 'customers', 'warehouse_id' => 'warehouses'],
            ['quotation_items.*.product_id' => 'products']
        );
    }
}
