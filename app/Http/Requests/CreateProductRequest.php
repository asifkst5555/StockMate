<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

class CreateProductRequest extends FormRequest
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
        return Product::rules();
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
