<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Transfer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransferRequest extends FormRequest
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
            'lot_id' => ['required', 'uuid', 'exists:lots,id'],
            'from_vessel_id' => ['required', 'uuid', 'exists:vessels,id', 'different:to_vessel_id'],
            'to_vessel_id' => ['required', 'uuid', 'exists:vessels,id'],
            'volume_gallons' => ['required', 'numeric', 'min:0.0001', 'max:999999.9999'],
            'transfer_type' => ['required', 'string', Rule::in(Transfer::TRANSFER_TYPES)],
            'variance_gallons' => ['nullable', 'numeric', 'min:0', 'max:999999.9999'],
            'performed_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
