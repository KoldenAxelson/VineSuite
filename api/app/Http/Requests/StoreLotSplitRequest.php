<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLotSplitRequest extends FormRequest
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

            // Child lots — at least 2 splits required (splitting into 1 is pointless)
            'children' => ['required', 'array', 'min:2', 'max:20'],
            'children.*.name' => ['required', 'string', 'max:255'],
            'children.*.volume_gallons' => ['required', 'numeric', 'min:0.0001', 'max:999999.9999'],
        ];
    }
}
