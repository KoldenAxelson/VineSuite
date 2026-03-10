<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable activity log entry — audit trail for system-level changes.
 *
 * Tracks who changed what, when, and the old/new values.
 * Separate from the event log (winery operations).
 * Cannot be updated or deleted (enforced by DB trigger).
 *
 * @property string $id UUID
 * @property string|null $user_id Who performed the action
 * @property string $action 'created', 'updated', 'deleted'
 * @property string $model_type Fully qualified model class name
 * @property string $model_id UUID of the affected model
 * @property array<string, mixed>|null $old_values Previous values (null for create)
 * @property array<string, mixed>|null $new_values New values (null for delete)
 * @property array<int, string>|null $changed_fields List of field names that changed
 * @property string|null $ip_address Request IP
 * @property string|null $user_agent Browser/device info
 * @property \Carbon\Carbon $created_at
 */
class ActivityLog extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'action',
        'model_type',
        'model_id',
        'old_values',
        'new_values',
        'changed_fields',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'changed_fields' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The user who performed this action.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope: logs for a specific model instance.
     *
     * @param  Builder<ActivityLog>  $query
     * @return Builder<ActivityLog>
     */
    public function scopeForModel(Builder $query, string $modelType, string $modelId): Builder
    {
        return $query->where('model_type', $modelType)
            ->where('model_id', $modelId);
    }

    /**
     * Scope: logs by a specific user.
     *
     * @param  Builder<ActivityLog>  $query
     * @return Builder<ActivityLog>
     */
    public function scopeByUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: logs of a specific action type.
     *
     * @param  Builder<ActivityLog>  $query
     * @return Builder<ActivityLog>
     */
    public function scopeOfAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }
}
