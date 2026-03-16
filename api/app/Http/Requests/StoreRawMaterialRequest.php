<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\RawMaterial;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRawMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // RBAC handled at route level
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'category' => ['required', 'string', Rule::in(RawMaterial::CATEGORIES)],
            'unit_of_measure' => ['required', 'string', Rule::in(RawMaterial::UNITS_OF_MEASURE)],
            'on_hand' => ['sometimes', 'numeric', 'min:0'],
            'reorder_point' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'cost_per_unit' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'expiration_date' => ['sometimes', 'nullable', 'date'],
            'vendor_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'vendor_id' => ['sometimes', 'nullable', 'uuid'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
