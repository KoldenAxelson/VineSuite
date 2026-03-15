<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\LabAnalysis;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLabAnalysisRequest extends FormRequest
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
            'test_date' => ['required', 'date'],
            'test_type' => ['required', 'string', Rule::in(LabAnalysis::TEST_TYPES)],
            'value' => ['required', 'numeric'],
            'unit' => ['required', 'string', 'max:30'],
            'method' => ['nullable', 'string', 'max:100'],
            'analyst' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'source' => ['nullable', 'string', Rule::in(LabAnalysis::SOURCES)],
        ];
    }
}
