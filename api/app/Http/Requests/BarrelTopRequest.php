<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BarrelTopRequest extends FormRequest
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
            'source_vessel_id' => ['required', 'uuid', 'exists:vessels,id'],
            'lot_id' => ['required', 'uuid', 'exists:lots,id'],
            'barrels' => ['required', 'array', 'min:1', 'max:200'],
            'barrels.*.barrel_id' => ['required', 'uuid', 'exists:barrels,id'],
            'barrels.*.volume_gallons' => ['required', 'numeric', 'min:0.0001', 'max:999999.9999'],
        ];
    }
}
