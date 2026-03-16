<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseOrderRequest extends FormRequest
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
            'vendor_name' => ['required', 'string', 'max:200'],
            'vendor_id' => ['sometimes', 'nullable', 'uuid'],
            'order_date' => ['required', 'date'],
            'expected_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:order_date'],
            'status' => ['sometimes', Rule::in(PurchaseOrder::STATUSES)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_type' => ['required', Rule::in(PurchaseOrderLine::ITEM_TYPES)],
            'lines.*.item_id' => ['required', 'uuid'],
            'lines.*.item_name' => ['required', 'string', 'max:200'],
            'lines.*.quantity_ordered' => ['required', 'numeric', 'gt:0'],
            'lines.*.cost_per_unit' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }
}
