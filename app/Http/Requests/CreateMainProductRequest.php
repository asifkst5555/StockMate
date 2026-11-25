<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class CreateMainProductRequest extends FormRequest
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
        $tenantId = Auth::user()?->tenant_id ?: request()->get('tenant_id');
        $productCodeRule = Rule::unique('products', 'product_code');
        $codeRule = Rule::unique('products', 'code');
        if ($tenantId) {
            $productCodeRule->where(fn ($q) => $q->where('tenant_id', $tenantId));
            $codeRule->where(fn ($q) => $q->where('tenant_id', $tenantId));
        } else {
            $productCodeRule->whereNull('tenant_id');
            $codeRule->whereNull('tenant_id');
        }

        $productType = $this->input('product_type', request()->get('product_type'));

        if ($productType == 1) {
            $rules = Product::rules();
            $rules['product_code'] = ['required', $productCodeRule];
            $rules['code'] = ['required', $codeRule];
            $rules['product_unit'] = ['required', 'integer', new \App\Rules\TenantExists('units')];
            $rules['sale_unit'] = ['nullable', 'integer', new \App\Rules\TenantExists('units')];
            $rules['purchase_unit'] = ['nullable', 'integer', new \App\Rules\TenantExists('units')];

            return $rules;
        }

        if ($productType == 2) {
            $variationData = $this->input('variation_data');
            if (is_string($variationData)) {
                $variationData = json_decode($variationData, true);
            }
            $this->merge([
                'variation_data' => $variationData,
            ]);

            return [
                'name' => 'required',
                'product_code' => ['required', $productCodeRule],
                'product_category_id' => ['required', 'integer', new \App\Rules\TenantExists('product_categories')],
                'brand_id' => ['required', 'integer', new \App\Rules\TenantExists('brands')],
                'product_unit' => ['required', 'integer', new \App\Rules\TenantExists('units')],
                'sale_unit' => ['nullable', 'integer', new \App\Rules\TenantExists('units')],
                'purchase_unit' => ['nullable', 'integer', new \App\Rules\TenantExists('units')],
                'quantity_limit' => 'nullable',
                'notes' => 'nullable',
                'barcode_symbol' => 'required',
                'images.*' => 'file|image|mimes:jpg,jpeg,png,webp,gif|mimetypes:image/jpeg,image/png,image/webp,image/gif|max:2048',
                'variation_data.*.product_cost' => 'required|numeric',
                'variation_data.*.product_price' => 'required|numeric',
                'variation_data.*.stock_alert' => 'nullable',
                'variation_data.*.order_tax' => 'nullable|numeric',
                'variation_data.*.tax_type' => 'nullable',
                'variation_data.*.code' => ['required', $codeRule],
            ];
        }

        return [];
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
            'variation_data.*.product_cost.required' => 'The product cost field is required.',
            'variation_data.*.product_price.required' => 'The product price field is required.',
            'variation_data.*.product_cost.numeric' => 'The product cost must be a number.',
            'variation_data.*.product_price.numeric' => 'The product price must be a number.',
            'variation_data.*.order_tax.numeric' => 'The order tax must be a number.',
            'variation_data.*.code.unique' =>  __('messages.error.code_taken'),
            'images.*.max' => __('messages.error.images_max_size'),
        ];
    }
}
