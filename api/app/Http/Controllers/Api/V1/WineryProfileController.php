<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\WineryProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Winery profile management.
 *
 * GET  /api/v1/winery  — returns the current tenant's winery profile
 * PUT  /api/v1/winery  — updates the winery profile (owner/admin only)
 */
class WineryProfileController extends Controller
{
    /**
     * Get the current tenant's winery profile.
     */
    public function show(): JsonResponse
    {
        $profile = WineryProfile::firstOrFail();

        return ApiResponse::success($profile);
    }

    /**
     * Update the winery profile.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'dba_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'website' => ['sometimes', 'nullable', 'url', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address_line_1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line_2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'state' => ['sometimes', 'nullable', 'string', 'size:2'],
            'zip' => ['sometimes', 'nullable', 'string', 'max:10'],
            'country' => ['sometimes', 'string', 'size:2'],
            'timezone' => ['sometimes', 'string', 'max:50', 'timezone:all'],
            'ttb_permit_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'ttb_registry_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'state_license_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'unit_system' => ['sometimes', 'string', Rule::in(['imperial', 'metric'])],
            'currency' => ['sometimes', 'string', 'size:3'],
            'fiscal_year_start_month' => ['sometimes', 'integer', 'between:1,12'],
            'date_format' => ['sometimes', 'string', 'max:20'],
            'onboarding_complete' => ['sometimes', 'boolean'],
        ]);

        $profile = WineryProfile::firstOrFail();

        $oldValues = $profile->only(array_keys($validated));
        $profile->update($validated);

        Log::info('Winery profile updated', [
            'changed_fields' => array_keys($validated),
            'old_values' => $oldValues,
            'new_values' => $profile->fresh()->only(array_keys($validated)),
            'user_id' => $request->user()->id,
            'tenant_id' => tenant('id'),
        ]);

        return ApiResponse::success($profile->fresh(), meta: ['message' => 'Winery profile updated successfully.']);
    }
}
