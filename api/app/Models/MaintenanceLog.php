<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\LogsActivity;
use Database\Factories\MaintenanceLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Maintenance log entry for a piece of equipment.
 *
 * Records cleaning, CIP, calibration, repair, inspection, and preventive
 * maintenance activities for compliance audits.
 *
 * @property string $id UUID
 * @property string $equipment_id FK to equipment
 * @property string $maintenance_type cleaning, cip, calibration, repair, inspection, preventive
 * @property Carbon $performed_date
 * @property string|null $performed_by UUID of the user
 * @property string|null $description What was done
 * @property string|null $findings Results or observations
 * @property float|null $cost Cost of the maintenance
 * @property Carbon|null $next_due_date When this type of maintenance is next due
 * @property bool|null $passed For calibration/inspection: true = passed, false = failed, null = N/A
 * @property string|null $notes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class MaintenanceLog extends Model
{
    /** @use HasFactory<MaintenanceLogFactory> */
    use HasFactory;

    use HasUuids;
    use LogsActivity;

    public const MAINTENANCE_TYPES = [
        'cleaning',
        'cip',
        'calibration',
        'repair',
        'inspection',
        'preventive',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'equipment_id',
        'maintenance_type',
        'performed_date',
        'performed_by',
        'description',
        'findings',
        'cost',
        'next_due_date',
        'passed',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'performed_date' => 'date',
            'cost' => 'decimal:2',
            'next_due_date' => 'date',
            'passed' => 'boolean',
        ];
    }

    // ─── Relationships ──────────────────────────────────────────

    /**
     * @return BelongsTo<Equipment, $this>
     */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    // ─── Scopes ─────────────────────────────────────────────────────

    /**
     * @param  Builder<MaintenanceLog>  $query
     * @return Builder<MaintenanceLog>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('maintenance_type', $type);
    }

    /**
     * @param  Builder<MaintenanceLog>  $query
     * @return Builder<MaintenanceLog>
     */
    public function scopeForEquipment(Builder $query, string $equipmentId): Builder
    {
        return $query->where('equipment_id', $equipmentId);
    }

    /**
     * @param  Builder<MaintenanceLog>  $query
     * @return Builder<MaintenanceLog>
     */
    public function scopePerformedBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('performed_date', [$from, $to]);
    }

    /**
     * Calibration records only (common audit request).
     *
     * @param  Builder<MaintenanceLog>  $query
     * @return Builder<MaintenanceLog>
     */
    public function scopeCalibrationRecords(Builder $query): Builder
    {
        return $query->where('maintenance_type', 'calibration');
    }

    /**
     * CIP records only (common audit request).
     *
     * @param  Builder<MaintenanceLog>  $query
     * @return Builder<MaintenanceLog>
     */
    public function scopeCipRecords(Builder $query): Builder
    {
        return $query->where('maintenance_type', 'cip');
    }
}
