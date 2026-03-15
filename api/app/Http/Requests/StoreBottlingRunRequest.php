<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\BottlingComponent;
use App\Models\BottlingRun;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBottlingRunRequest extends FormRequest
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
            'bottle_format' => ['required', 'string', Rule::in(BottlingRun::BOTTLE_FORMATS)],
            'bottles_filled' => ['required', 'integer', 'min:1', 'max:999999'],
            'bottles_breakage' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'waste_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'volume_bottled_gallons' => ['required', 'numeric', 'min:0.0001', 'max:999999.9999'],
            'bottles_per_case' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sku' => ['nullable', 'string', 'max:100'],
            'bottled_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],

            // Packaging components
            'components' => ['nullable', 'array', 'max:20'],
            'components.*.component_type' => ['required', 'string', Rule::in(BottlingComponent::COMPONENT_TYPES)],
            'components.*.product_name' => ['required', 'string', 'max:255'],
            'components.*.quantity_used' => ['required', 'integer', 'min:1'],
            'components.*.quantity_wasted' => ['nullable', 'integer', 'min:0'],
            'components.*.unit' => ['nullable', 'string', 'max:50'],
            'components.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
