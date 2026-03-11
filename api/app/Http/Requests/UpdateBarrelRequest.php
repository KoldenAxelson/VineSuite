<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Barrel;
use App\Models\Vessel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBarrelRequest extends FormRequest
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
            // Vessel fields (mutable)
            'name' => ['sometimes', 'string', 'max:255'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', Rule::in(Vessel::STATUSES)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],

            // Barrel-specific fields
            'cooperage' => ['sometimes', 'nullable', 'string', 'max:255'],
            'toast_level' => ['sometimes', 'nullable', 'string', Rule::in(Barrel::TOAST_LEVELS)],
            'oak_type' => ['sometimes', 'nullable', 'string', Rule::in(Barrel::OAK_TYPES)],
            'forest_origin' => ['sometimes', 'nullable', 'string', 'max:255'],
            'years_used' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'qr_code' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
