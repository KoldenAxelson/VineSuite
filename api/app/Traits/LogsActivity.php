<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Log;

/**
 * Trait for models that should auto-log create/update/delete to the activity log.
 *
 * Usage: Add `use LogsActivity;` to any Eloquent model.
 *
 * Optionally override:
 * - $activityLogExclude: array of fields to exclude from logging (e.g., 'password', 'remember_token')
 * - $activityLogOnly: array of fields to include (if set, only these are logged)
 *
 * Example:
 *   class WineryProfile extends Model
 *   {
 *       use LogsActivity;
 *       protected array $activityLogExclude = ['updated_at', 'created_at'];
 *   }
 */
trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        static::created(function ($model) {
            $model->logActivity('created', null, $model->getLoggableAttributes());
        });

        static::updated(function ($model) {
            $dirty = $model->getDirty();
            $changedFields = array_keys($dirty);

            // Filter out excluded fields
            $changedFields = $model->filterLoggableFields($changedFields);

            if (empty($changedFields)) {
                return; // Nothing worth logging changed
            }

            $oldValues = [];
            $newValues = [];

            foreach ($changedFields as $field) {
                $oldValues[$field] = $model->getOriginal($field);
                $newValues[$field] = $model->getAttribute($field);
            }

            $model->logActivity('updated', $oldValues, $newValues, $changedFields);
        });

        static::deleted(function ($model) {
            $model->logActivity('deleted', $model->getLoggableAttributes(), null);
        });
    }

    /**
     * Write an activity log entry.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<int, string>|null  $changedFields
     */
    protected function logActivity(
        string $action,
        ?array $oldValues,
        ?array $newValues,
        ?array $changedFields = null,
    ): void {
        try {
            ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => $action,
                'model_type' => static::class,
                'model_id' => $this->getKey(),
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'changed_fields' => $changedFields,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        } catch (\Throwable $e) {
            // Don't let activity logging failures break the application
            Log::warning('Activity logging failed', [
                'action' => $action,
                'model_type' => static::class,
                'model_id' => $this->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get attributes suitable for logging, filtered by include/exclude lists.
     *
     * @return array<string, mixed>
     */
    protected function getLoggableAttributes(): array
    {
        $attributes = $this->attributesToArray();
        $fields = array_keys($attributes);
        $fields = $this->filterLoggableFields($fields);

        return array_intersect_key($attributes, array_flip($fields));
    }

    /**
     * Filter field names through include/exclude lists.
     *
     * @param  array<int, string>  $fields
     * @return array<int, string>
     */
    protected function filterLoggableFields(array $fields): array
    {
        // Always exclude these sensitive fields
        $alwaysExclude = ['password', 'remember_token'];

        // Model-specific exclusions
        /** @var array<int, string> $modelExclusions */
        $modelExclusions = isset($this->activityLogExclude) ? $this->activityLogExclude : [];
        $exclude = array_merge($alwaysExclude, $modelExclusions);

        // If model specifies an "only" list, use it
        if (isset($this->activityLogOnly) && ! empty($this->activityLogOnly)) {
            $fields = array_intersect($fields, $this->activityLogOnly);
        }

        return array_values(array_diff($fields, $exclude));
    }
}
