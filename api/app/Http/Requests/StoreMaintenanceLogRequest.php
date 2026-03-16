<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\MaintenanceLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaintenanceLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // RBAC handled at route level
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'equipment_id' => ['required', 'uuid', 'exists:equipment,id'],
            'maintenance_type' => ['required', 'string', Rule::in(MaintenanceLog::MAINTENANCE_TYPES)],
            'performed_date' => ['required', 'date'],
            'performed_by' => ['sometimes', 'nullable', 'uuid'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'findings' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'next_due_date' => ['sometimes', 'nullable', 'date'],
            'passed' => ['sometimes', 'nullable', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
