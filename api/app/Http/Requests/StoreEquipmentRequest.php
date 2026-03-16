<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Equipment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEquipmentRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:150'],
            'equipment_type' => ['required', 'string', Rule::in(Equipment::EQUIPMENT_TYPES)],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'manufacturer' => ['sometimes', 'nullable', 'string', 'max:150'],
            'model_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'purchase_date' => ['sometimes', 'nullable', 'date'],
            'purchase_value' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'location' => ['sometimes', 'nullable', 'string', 'max:150'],
            'status' => ['sometimes', 'string', Rule::in(Equipment::STATUSES)],
            'next_maintenance_due' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
