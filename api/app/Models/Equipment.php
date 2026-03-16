<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\LogsActivity;
use Database\Factories\EquipmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Equipment register item.
 *
 * Tracks winery equipment with serial numbers, purchase info,
 * maintenance schedules, and operational status for compliance audits.
 *
 * @property string $id UUID
 * @property string $name
 * @property string $equipment_type tank, pump, press, filter, bottling_line, lab_instrument, forklift, other
 * @property string|null $serial_number
 * @property string|null $manufacturer
 * @property string|null $model_number
 * @property \Illuminate\Support\Carbon|null $purchase_date
 * @property float|null $purchase_value
 * @property string|null $location
 * @property string $status operational, maintenance, retired
 * @property \Illuminate\Support\Carbon|null $next_maintenance_due
 * @property bool $is_active
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MaintenanceLog> $maintenanceLogs
 */
class Equipment extends Model
{
    /** @use HasFactory<EquipmentFactory> */
    use HasFactory;

    use HasUuids;
    use LogsActivity;

    protected $table = 'equipment';

    public const EQUIPMENT_TYPES = [
        'tank',
        'pump',
        'press',
        'filter',
        'bottling_line',
        'lab_instrument',
        'forklift',
        'other',
    ];

    public const STATUSES = [
        'operational',
        'maintenance',
        'retired',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = [
        'status' => 'operational',
        'is_active' => true,
    ];

    protected $fillable = [
        'name',
        'equipment_type',
        'serial_number',
        'manufacturer',
        'model_number',
        'purchase_date',
        'purchase_value',
        'location',
        'status',
        'next_maintenance_due',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'purchase_value' => 'decimal:2',
            'next_maintenance_due' => 'date',
            'is_active' => 'boolean',
        ];
    }

    // ─── Relationships ──────────────────────────────────────────

    /**
     * @return HasMany<MaintenanceLog, $this>
     */
    public function maintenanceLogs(): HasMany
    {
        return $this->hasMany(MaintenanceLog::class);
    }

    // ─── Scopes ─────────────────────────────────────────────────────

    /**
     * @param  Builder<Equipment>  $query
     * @return Builder<Equipment>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<Equipment>  $query
     * @return Builder<Equipment>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('equipment_type', $type);
    }

    /**
     * @param  Builder<Equipment>  $query
     * @return Builder<Equipment>
     */
    public function scopeOfStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Equipment with maintenance due on or before the given date.
     *
     * @param  Builder<Equipment>  $query
     * @return Builder<Equipment>
     */
    public function scopeMaintenanceDue(Builder $query, ?string $beforeDate = null): Builder
    {
        $date = $beforeDate ?? now()->toDateString();

        return $query->whereNotNull('next_maintenance_due')
            ->where('next_maintenance_due', '<=', $date);
    }

    /**
     * Equipment with maintenance due within the given number of days.
     *
     * @param  Builder<Equipment>  $query
     * @return Builder<Equipment>
     */
    public function scopeMaintenanceDueSoon(Builder $query, int $days = 30): Builder
    {
        return $query->whereNotNull('next_maintenance_due')
            ->where('next_maintenance_due', '>=', now()->toDateString())
            ->where('next_maintenance_due', '<=', now()->addDays($days)->toDateString());
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    /**
     * Whether this equipment has overdue maintenance.
     */
    public function isMaintenanceOverdue(): bool
    {
        if ($this->next_maintenance_due === null) {
            return false;
        }

        return $this->next_maintenance_due->isPast();
    }

    /**
     * Filament badge color for the current status.
     */
    public function statusColor(): string
    {
        return match ($this->status) {
            'operational' => 'success',
            'maintenance' => 'warning',
            'retired' => 'gray',
            default => 'secondary',
        };
    }
}
