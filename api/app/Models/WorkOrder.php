<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\LogsActivity;
use Database\Factories\WorkOrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Work order — the daily cellar workflow unit.
 *
 * Winemakers create work orders and assign them to cellar hands.
 * Completing a work order writes the appropriate event to the event log.
 * Work orders are the primary trigger for most production events.
 *
 * @property string $id UUID
 * @property string $operation_type Operation type (free-text, configurable per winery)
 * @property string|null $lot_id FK to lots
 * @property string|null $vessel_id FK to vessels
 * @property string|null $assigned_to FK to users
 * @property \Illuminate\Support\Carbon|null $due_date
 * @property string $status pending|in_progress|completed|skipped
 * @property string $priority low|normal|high
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property string|null $completed_by FK to users
 * @property string|null $completion_notes
 * @property string|null $template_id FK to work_order_templates
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class WorkOrder extends Model
{
    /** @use HasFactory<WorkOrderFactory> */
    use HasFactory;

    use HasUuids;
    use LogsActivity;

    public const STATUSES = ['pending', 'in_progress', 'completed', 'skipped'];

    public const PRIORITIES = ['low', 'normal', 'high'];

    /** @var array<int, string> */
    protected array $activityLogExclude = ['updated_at', 'created_at'];

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var array<string, string> */
    protected $attributes = [
        'status' => 'pending',
        'priority' => 'normal',
    ];

    protected $fillable = [
        'operation_type',
        'lot_id',
        'vessel_id',
        'assigned_to',
        'due_date',
        'status',
        'priority',
        'notes',
        'completed_at',
        'completed_by',
        'completion_notes',
        'hours',
        'labor_cost',
        'template_id',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'hours' => 'decimal:2',
            'labor_cost' => 'decimal:4',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────

    /**
     * @return BelongsTo<Lot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    /**
     * @return BelongsTo<Vessel, $this>
     */
    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * @return BelongsTo<WorkOrderTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(WorkOrderTemplate::class, 'template_id');
    }

    // ─── Scopes ──────────────────────────────────────────────────

    /**
     * @param  Builder<WorkOrder>  $query
     * @return Builder<WorkOrder>
     */
    public function scopeWithWorkOrderStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * @param  Builder<WorkOrder>  $query
     * @return Builder<WorkOrder>
     */
    public function scopeWithPriority(Builder $query, string $priority): Builder
    {
        return $query->where('priority', $priority);
    }

    /**
     * @param  Builder<WorkOrder>  $query
     * @return Builder<WorkOrder>
     */
    public function scopeAssignedTo(Builder $query, string $userId): Builder
    {
        return $query->where('assigned_to', $userId);
    }

    /**
     * @param  Builder<WorkOrder>  $query
     * @return Builder<WorkOrder>
     */
    public function scopeDueOn(Builder $query, string $date): Builder
    {
        return $query->whereDate('due_date', $date);
    }

    /**
     * @param  Builder<WorkOrder>  $query
     * @return Builder<WorkOrder>
     */
    public function scopeDueBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('due_date', [$from, $to]);
    }

    /**
     * @param  Builder<WorkOrder>  $query
     * @return Builder<WorkOrder>
     */
    public function scopeOfOperationType(Builder $query, string $operationType): Builder
    {
        return $query->where('operation_type', $operationType);
    }

    /**
     * @param  Builder<WorkOrder>  $query
     * @return Builder<WorkOrder>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('due_date', '<', now()->toDateString())
            ->whereIn('status', ['pending', 'in_progress']);
    }
}
