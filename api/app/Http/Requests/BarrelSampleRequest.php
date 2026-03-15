<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BarrelSampleRequest extends FormRequest
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
            'barrel_id' => ['required', 'uuid', 'exists:barrels,id'],
            'lot_id' => ['required', 'uuid', 'exists:lots,id'],
            'volume_ml' => ['required', 'numeric', 'min:1', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
