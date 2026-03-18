<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\LogsActivity;
use Database\Factories\WorkOrderTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Work order template — reusable blueprints for common cellar operations.
 *
 * Templates allow one-click creation of work orders for frequently
 * performed operations like Pump Over, Rack, Add SO2, etc.
 *
 * @property string $id UUID
 * @property string $name Template display name
 * @property string $operation_type Operation type (configurable per winery)
 * @property string|null $default_notes Default notes pre-filled on work orders
 * @property bool $is_active Whether the template is available for use
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class WorkOrderTemplate extends Model
{
    /** @use HasFactory<WorkOrderTemplateFactory> */
    use HasFactory;

    use HasUuids;
    use LogsActivity;

    /**
     * Common operation types seeded for new wineries.
     * Wineries can add their own — these are NOT enforced as an enum.
     */
    public const DEFAULT_OPERATION_TYPES = [
        'Pump Over',
        'Punch Down',
        'Rack',
        'Add SO2',
        'Fine',
        'Filter',
        'Transfer',
        'Top',
        'Sample',
        'Barrel Down',
        'Press',
        'Inoculate',
    ];

    /** @var array<int, string> */
    protected array $activityLogExclude = ['updated_at', 'created_at'];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
        'operation_type',
        'default_notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────

    /**
     * Work orders created from this template.
     *
     * @return HasMany<WorkOrder, $this>
     */
    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'template_id');
    }

    // ─── Scopes ──────────────────────────────────────────────────

    /**
     * Filter to active templates only.
     *
     * @param  Builder<WorkOrderTemplate>  $query
     * @return Builder<WorkOrderTemplate>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
