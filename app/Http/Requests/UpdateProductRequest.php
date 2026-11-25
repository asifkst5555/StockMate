<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
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
        $rules = Product::rules();
        $productId = $this->route('product');
        if (is_object($productId)) {
            $productId = $productId->id;
        }

        $tenantId = Auth::user()?->tenant_id ?: request()->get('tenant_id');
        $codeRule = Rule::unique('products', 'code')->ignore($productId);
        $productCodeRule = Rule::unique('products', 'product_code')->ignore($productId);

        if ($tenantId) {
            $codeRule->where(fn ($q) => $q->where('tenant_id', $tenantId));
            $productCodeRule->where(fn ($q) => $q->where('tenant_id', $tenantId));
        } else {
            $codeRule->whereNull('tenant_id');
            $productCodeRule->whereNull('tenant_id');
        }

        $rules['code'] = ['required', $codeRule];
        $rules['product_code'] = ['required', $productCodeRule];
        $rules['product_unit'] = ['required', 'integer', new \App\Rules\TenantExists('units')];
        $rules['sale_unit'] = ['nullable', 'integer', new \App\Rules\TenantExists('units')];
        $rules['purchase_unit'] = ['nullable', 'integer', new \App\Rules\TenantExists('units')];

        return $rules;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $purchaseUnit = $this->input('purchase_unit');
            $saleUnit = $this->input('sale_unit');

            if (! empty($purchaseUnit) && ! empty($saleUnit) && (int) $purchaseUnit !== (int) $saleUnit) {
                $validator->errors()->add(
                    'sale_unit',
                    'Unsupported unit conversion: purchase unit and sale unit must match (1:1 unit parity required).'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'code.unique' => __('messages.error.code_taken'),
            'product_code.unique' => __('messages.error.code_taken'),
            'images.*.max' => __('messages.error.images_max_size'),
        ];
    }
}
