<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Lot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Auth handled by middleware
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'variety' => ['required', 'string', 'max:255'],
            'vintage' => ['required', 'integer', 'min:1900', 'max:2100'],
            'source_type' => ['required', Rule::in(Lot::SOURCE_TYPES)],
            'source_details' => ['sometimes', 'nullable', 'array'],
            'volume_gallons' => ['required', 'numeric', 'min:0', 'max:999999.9999'],
            'status' => ['sometimes', Rule::in(Lot::STATUSES)],
            'parent_lot_id' => ['sometimes', 'nullable', 'uuid', 'exists:lots,id'],
        ];
    }
}
