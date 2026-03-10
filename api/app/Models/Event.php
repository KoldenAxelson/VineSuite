<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable event log entry.
 *
 * Architecture doc Section 3: All winery operations are recorded as immutable events.
 * Events are never updated or deleted — only INSERT.
 * Corrections are new events (e.g., addition_corrected).
 *
 * @property string $id UUID
 * @property string $entity_type 'lot', 'vessel', 'barrel', 'inventory_item', 'order'
 * @property string $entity_id UUID of the entity this event relates to
 * @property string $operation_type 'addition', 'transfer', 'rack', 'bottle', 'blend', 'sale'
 * @property array $payload Operation-specific data (JSONB)
 * @property string|null $performed_by UUID of the user who performed the operation
 * @property \Carbon\Carbon $performed_at Client timestamp (may be from offline device)
 * @property \Carbon\Carbon|null $synced_at Server receipt timestamp (null for locally-created events)
 * @property string|null $device_id Identifies which client submitted
 * @property string|null $idempotency_key Prevents duplicate event submission on retry
 * @property \Carbon\Carbon $created_at
 */
class Event extends Model
{
    use HasUuids;

    /**
     * Events are immutable — no updated_at column.
     */
    public const UPDATED_AT = null;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'entity_type',
        'entity_id',
        'operation_type',
        'payload',
        'performed_by',
        'performed_at',
        'synced_at',
        'device_id',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'performed_at' => 'datetime',
            'synced_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The user who performed this operation.
     */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /**
     * Scope: events for a specific entity.
     */
    public function scopeForEntity($query, string $entityType, string $entityId)
    {
        return $query->where('entity_type', $entityType)
            ->where('entity_id', $entityId);
    }

    /**
     * Scope: events of a specific operation type.
     */
    public function scopeOfType($query, string $operationType)
    {
        return $query->where('operation_type', $operationType);
    }

    /**
     * Scope: events in a time range (by performed_at).
     */
    public function scopePerformedBetween($query, $from, $to)
    {
        return $query->whereBetween('performed_at', [$from, $to]);
    }
}
