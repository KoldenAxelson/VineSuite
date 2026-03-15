<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\LabAnalysis;
use App\Models\LabThreshold;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLabThresholdRequest extends FormRequest
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
            'test_type' => ['required', 'string', Rule::in(LabAnalysis::TEST_TYPES)],
            'variety' => ['nullable', 'string', 'max:100'],
            'min_value' => ['nullable', 'numeric'],
            'max_value' => ['nullable', 'numeric'],
            'alert_level' => ['required', 'string', Rule::in(LabThreshold::ALERT_LEVELS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'min_value.numeric' => 'The minimum value must be a number.',
            'max_value.numeric' => 'The maximum value must be a number.',
        ];
    }
}
