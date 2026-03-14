<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\PressLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePressLogRequest extends FormRequest
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
            'press_type' => ['required', Rule::in(PressLog::PRESS_TYPES)],
            'fruit_weight_kg' => ['required', 'numeric', 'min:0.0001', 'max:999999.9999'],
            'total_juice_gallons' => ['required', 'numeric', 'min:0.0001', 'max:999999.9999'],

            // Fractions array
            'fractions' => ['required', 'array', 'min:1', 'max:10'],
            'fractions.*.fraction' => ['required', Rule::in(PressLog::FRACTION_TYPES)],
            'fractions.*.volume_gallons' => ['required', 'numeric', 'min:0.0001', 'max:999999.9999'],
            'fractions.*.create_child_lot' => ['nullable', 'boolean'],

            // Pomace
            'pomace_weight_kg' => ['nullable', 'numeric', 'min:0', 'max:999999.9999'],
            'pomace_destination' => ['nullable', Rule::in(PressLog::POMACE_DESTINATIONS)],

            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
