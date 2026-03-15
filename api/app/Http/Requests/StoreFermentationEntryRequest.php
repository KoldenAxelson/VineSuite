<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\FermentationEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFermentationEntryRequest extends FormRequest
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
            'entry_date' => ['required', 'date'],
            'temperature' => ['nullable', 'numeric', 'min:30', 'max:120'],
            'brix_or_density' => ['nullable', 'numeric'],
            'measurement_type' => ['nullable', 'required_with:brix_or_density', Rule::in(FermentationEntry::MEASUREMENT_TYPES)],
            'free_so2' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
