<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\PurchaseOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePurchaseOrderRequest extends FormRequest
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
            'vendor_name' => ['sometimes', 'string', 'max:200'],
            'vendor_id' => ['sometimes', 'nullable', 'uuid'],
            'order_date' => ['sometimes', 'date'],
            'expected_date' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', Rule::in(PurchaseOrder::STATUSES)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
