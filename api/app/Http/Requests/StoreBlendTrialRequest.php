<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBlendTrialRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'version' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:5000'],

            // Components — at least 2 source lots required for a blend
            'components' => ['required', 'array', 'min:2', 'max:20'],
            'components.*.source_lot_id' => ['required', 'uuid', 'exists:lots,id'],
            'components.*.percentage' => ['required', 'numeric', 'min:0.0001', 'max:100'],
            'components.*.volume_gallons' => ['required', 'numeric', 'min:0.0001', 'max:999999.9999'],
        ];
    }
}
