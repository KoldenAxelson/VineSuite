<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Barrel;
use App\Models\Vessel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBarrelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled by route middleware
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Vessel fields
            'name' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', Rule::in(Vessel::STATUSES)],
            'purchase_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],

            // Barrel-specific fields
            'cooperage' => ['nullable', 'string', 'max:255'],
            'toast_level' => ['nullable', 'string', Rule::in(Barrel::TOAST_LEVELS)],
            'oak_type' => ['nullable', 'string', Rule::in(Barrel::OAK_TYPES)],
            'forest_origin' => ['nullable', 'string', 'max:255'],
            'volume_gallons' => ['sometimes', 'numeric', 'min:0.0001', 'max:999999.9999'],
            'years_used' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'qr_code' => ['nullable', 'string', 'max:255'],
        ];
    }
}
