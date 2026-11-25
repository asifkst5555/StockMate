<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UpdateMainProductRequest extends FormRequest
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
        $mainProductId = $this->route('product') ?? $this->route('main_product');
        if (is_object($mainProductId)) {
            $mainProductId = $mainProductId->id;
        }

        $ignoreProductIds = [];
        if ($mainProductId) {
            $ignoreProductIds = Product::where('main_product_id', $mainProductId)->pluck('id')->all();
        }

        $productCodeRule = Rule::unique('products', 'product_code');
        if ($tenantId) {
            $productCodeRule->where(fn ($q) => $q->where('tenant_id', $tenantId));
        } else {
            $productCodeRule->whereNull('tenant_id');
        }
        if (! empty($ignoreProductIds)) {
            $productCodeRule->whereNotIn('id', $ignoreProductIds);
        }

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
        ];
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
            'images.*.image' => __('messages.error.invalid_image'),
            'images.*.mimes' => __('messages.error.allowed_image_types'),
            'images.*' => __('messages.error.images_max_size'),
        ];
    }
}
