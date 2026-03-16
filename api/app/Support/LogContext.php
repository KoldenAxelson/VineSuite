<?php

declare(strict_types=1);

namespace App\Support;

/**
 * LogContext — standardizes log context keys across all services.
 *
 * Automatically appends tenant_id and user_id to every log call.
 * Usage: Log::info('Something happened', LogContext::with(['lot_id' => $id], $userId))
 *
 * This avoids scattered `'tenant_id' => tenant('id')` calls and
 * ensures consistent key naming across the entire codebase.
 */
class LogContext
{
    /**
     * Build a log context array with standard fields appended.
     *
     * @param  array<string, mixed>  $context  Service-specific context
     * @param  string|null  $userId  The user performing the action (standardized as 'user_id')
     * @return array<string, mixed>
     */
    public static function with(array $context, ?string $userId = null): array
    {
        $standard = [
            'tenant_id' => function_exists('tenant') ? tenant('id') : null,
        ];

        if ($userId !== null) {
            $standard['user_id'] = $userId;
        }

        // Service-specific context takes precedence over standard fields
        return array_merge($standard, $context);
    }
}
