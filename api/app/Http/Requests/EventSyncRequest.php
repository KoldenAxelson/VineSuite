<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a batch of events submitted from mobile apps.
 *
 * Each event in the `events` array must have:
 * - entity_type, entity_id, operation_type (strings)
 * - payload (object/array)
 * - performed_at (ISO 8601 timestamp, within last 30 days)
 * - idempotency_key (required for sync — must be unique per event)
 * - device_id (optional)
 */
class EventSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Auth handled by Sanctum middleware
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'min:1', 'max:100'],
            'events.*.entity_type' => ['required', 'string', 'max:50'],
            'events.*.entity_id' => ['required', 'uuid'],
            'events.*.operation_type' => ['required', 'string', 'max:50'],
            'events.*.payload' => ['required', 'array'],
            'events.*.performed_at' => ['required', 'date', 'before_or_equal:now', 'after:'.now()->subDays(30)->toIso8601String()],
            'events.*.idempotency_key' => ['required', 'string', 'max:100'],
            'events.*.device_id' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'events.*.performed_at.after' => 'The performed_at timestamp cannot be more than 30 days in the past.',
            'events.*.performed_at.before_or_equal' => 'The performed_at timestamp cannot be in the future.',
            'events.max' => 'A maximum of 100 events can be synced per request.',
        ];
    }
}
