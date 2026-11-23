<?php

namespace App\Http\Requests;

use App\Models\Transfer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

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
        $tenantId = Auth::user()?->tenant_id;
        $rules = Transfer::$rules;

        $rules['from_warehouse_id'] = [
            'required',
            Rule::exists('warehouses', 'id')->where(function ($query) use ($tenantId) {
                $query->where('tenant_id', $tenantId);
            }),
        ];

        $rules['to_warehouse_id'] = [
            'required',
            'different:from_warehouse_id',
            Rule::exists('warehouses', 'id')->where(function ($query) use ($tenantId) {
                $query->where('tenant_id', $tenantId);
            }),
        ];

        return $rules;
    }
}
