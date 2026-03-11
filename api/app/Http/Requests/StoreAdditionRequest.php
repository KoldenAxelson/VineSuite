<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Addition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdditionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lot_id' => ['required', 'uuid', 'exists:lots,id'],
            'vessel_id' => ['nullable', 'uuid', 'exists:vessels,id'],
            'addition_type' => ['required', 'string', Rule::in(Addition::ADDITION_TYPES)],
            'product_name' => ['required', 'string', 'max:255'],
            'rate' => ['nullable', 'numeric', 'min:0'],
            'rate_unit' => ['nullable', 'string', Rule::in(Addition::RATE_UNITS)],
            'total_amount' => ['required', 'numeric', 'min:0.0001', 'max:999999.9999'],
            'total_unit' => ['required', 'string', Rule::in(Addition::TOTAL_UNITS)],
            'reason' => ['nullable', 'string', 'max:1000'],
            'performed_at' => ['nullable', 'date'],
            'inventory_item_id' => ['nullable', 'uuid'],
        ];
    }
}
