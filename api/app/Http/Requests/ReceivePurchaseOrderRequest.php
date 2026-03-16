<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceivePurchaseOrderRequest extends FormRequest
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
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_id' => ['required', 'uuid'],
            'lines.*.quantity_received' => ['required', 'numeric', 'gt:0'],
            'lines.*.cost_per_unit' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }
}
