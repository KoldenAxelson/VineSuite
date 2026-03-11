<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\WorkOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkOrderRequest extends FormRequest
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
            'operation_type' => ['required', 'string', 'max:255'],
            'lot_id' => ['nullable', 'uuid', 'exists:lots,id'],
            'vessel_id' => ['nullable', 'uuid', 'exists:vessels,id'],
            'assigned_to' => ['nullable', 'uuid', 'exists:users,id'],
            'due_date' => ['nullable', 'date'],
            'status' => ['sometimes', 'string', Rule::in(WorkOrder::STATUSES)],
            'priority' => ['sometimes', 'string', Rule::in(WorkOrder::PRIORITIES)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'template_id' => ['nullable', 'uuid', 'exists:work_order_templates,id'],
        ];
    }
}
