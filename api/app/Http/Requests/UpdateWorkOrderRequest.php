<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\WorkOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkOrderRequest extends FormRequest
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
            'status' => ['sometimes', 'string', Rule::in(WorkOrder::STATUSES)],
            'priority' => ['sometimes', 'string', Rule::in(WorkOrder::PRIORITIES)],
            'assigned_to' => ['sometimes', 'nullable', 'uuid', 'exists:users,id'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'completion_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
