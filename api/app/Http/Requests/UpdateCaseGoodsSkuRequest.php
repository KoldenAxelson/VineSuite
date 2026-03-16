<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\CaseGoodsSku;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCaseGoodsSkuRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // RBAC handled by route middleware
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'wine_name' => ['sometimes', 'string', 'max:255'],
            'vintage' => ['sometimes', 'integer', 'min:1900', 'max:2100'],
            'varietal' => ['sometimes', 'string', 'max:100'],
            'format' => ['sometimes', 'string', Rule::in(CaseGoodsSku::FORMATS)],
            'case_size' => ['sometimes', 'integer', Rule::in(CaseGoodsSku::CASE_SIZES)],
            'upc_barcode' => ['nullable', 'string', 'max:50'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'cost_per_bottle' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'is_active' => ['sometimes', 'boolean'],
            'tasting_notes' => ['nullable', 'string', 'max:5000'],
            'lot_id' => ['nullable', 'uuid', 'exists:lots,id'],
            'bottling_run_id' => ['nullable', 'uuid', 'exists:bottling_runs,id'],
            'image' => ['nullable', 'image', 'max:5120'],
            'tech_sheet' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
        ];
    }
}
