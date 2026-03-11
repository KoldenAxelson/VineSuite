<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Lot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLotRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(Lot::STATUSES)],
            'source_details' => ['sometimes', 'nullable', 'array'],
            'volume_gallons' => ['sometimes', 'numeric', 'min:0', 'max:999999.9999'],
        ];
    }
}
