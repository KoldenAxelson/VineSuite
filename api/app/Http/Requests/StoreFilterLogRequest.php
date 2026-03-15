<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\FilterLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFilterLogRequest extends FormRequest
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
            'vessel_id' => ['nullable', 'uuid', 'exists:vessels,id'],
            'filter_type' => ['required', Rule::in(FilterLog::FILTER_TYPES)],
            'filter_media' => ['nullable', 'string', 'max:255'],
            'flow_rate_lph' => ['nullable', 'numeric', 'min:0.01', 'max:99999.99'],
            'volume_processed_gallons' => ['required', 'numeric', 'min:0.0001', 'max:999999.9999'],

            // Fining details
            'fining_agent' => ['nullable', 'string', 'max:255'],
            'fining_rate' => ['nullable', 'numeric', 'min:0.0001', 'max:9999.9999'],
            'fining_rate_unit' => ['nullable', Rule::in(FilterLog::FINING_RATE_UNITS)],
            'bench_trial_notes' => ['nullable', 'string', 'max:5000'],
            'treatment_notes' => ['nullable', 'string', 'max:5000'],

            // Analysis references (nullable UUIDs — lab module not built yet)
            'pre_analysis_id' => ['nullable', 'uuid'],
            'post_analysis_id' => ['nullable', 'uuid'],

            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
