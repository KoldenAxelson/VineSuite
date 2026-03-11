<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\WorkOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkStoreWorkOrderRequest extends FormRequest
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
            // Common fields applied to all work orders
            'operation_type' => ['required', 'string', 'max:255'],
            'due_date' => ['nullable', 'date'],
            'priority' => ['sometimes', 'string', Rule::in(WorkOrder::PRIORITIES)],
            'assigned_to' => ['nullable', 'uuid', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:5000'],

            // Targets — each with optional lot_id and vessel_id
            'targets' => ['required', 'array', 'min:1', 'max:100'],
            'targets.*.lot_id' => ['nullable', 'uuid', 'exists:lots,id'],
            'targets.*.vessel_id' => ['nullable', 'uuid', 'exists:vessels,id'],
        ];
    }
}
