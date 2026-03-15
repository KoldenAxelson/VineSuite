<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\LabAnalysis;
use App\Models\LabThreshold;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLabThresholdRequest extends FormRequest
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
            'test_type' => ['sometimes', 'string', Rule::in(LabAnalysis::TEST_TYPES)],
            'variety' => ['nullable', 'string', 'max:100'],
            'min_value' => ['nullable', 'numeric'],
            'max_value' => ['nullable', 'numeric'],
            'alert_level' => ['sometimes', 'string', Rule::in(LabThreshold::ALERT_LEVELS)],
        ];
    }
}
