<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\FermentationRound;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFermentationRoundRequest extends FormRequest
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
            'lot_id' => ['sometimes', 'uuid', 'exists:lots,id'],
            'round_number' => ['required', 'integer', 'min:1'],
            'fermentation_type' => ['required', Rule::in(FermentationRound::FERMENTATION_TYPES)],
            'inoculation_date' => ['required', 'date'],
            'yeast_strain' => ['nullable', 'string', 'max:100'],
            'ml_bacteria' => ['nullable', 'string', 'max:100'],
            'target_temp' => ['nullable', 'numeric', 'min:30', 'max:120'],
            'nutrients_schedule' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
