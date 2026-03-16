<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\DryGoodsItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDryGoodsItemRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:150'],
            'item_type' => ['sometimes', 'string', Rule::in(DryGoodsItem::ITEM_TYPES)],
            'unit_of_measure' => ['sometimes', 'string', Rule::in(DryGoodsItem::UNITS_OF_MEASURE)],
            'on_hand' => ['sometimes', 'numeric', 'min:0'],
            'reorder_point' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'cost_per_unit' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'vendor_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'vendor_id' => ['sometimes', 'nullable', 'uuid'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
