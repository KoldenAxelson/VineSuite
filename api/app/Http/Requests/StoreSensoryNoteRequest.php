<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\SensoryNote;
use Illuminate\Foundation\Http\FormRequest;

class StoreSensoryNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'lot_id' => ['sometimes', 'uuid', 'exists:lots,id'],
            'date' => ['required', 'date'],
            'rating' => ['nullable', 'numeric', 'min:0'],
            'rating_scale' => ['nullable', 'in:'.implode(',', SensoryNote::RATING_SCALES)],
            'nose_notes' => ['nullable', 'string', 'max:5000'],
            'palate_notes' => ['nullable', 'string', 'max:5000'],
            'overall_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
