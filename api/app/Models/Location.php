<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\LogsActivity;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Physical storage location for case goods inventory.
 *
 * Locations represent where bottled wine is stored — tasting room floor,
 * back stock, offsite warehouse, or third-party logistics (3PL).
 * Each location has independent stock levels per SKU.
 *
 * @property string $id UUID
 * @property string $name Location name (e.g. "Tasting Room Floor")
 * @property string|null $address Physical address
 * @property bool $is_active Whether location is currently in use
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, StockLevel> $stockLevels
 * @property-read Collection<int, StockMovement> $stockMovements
 */
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    use HasUuids;
    use LogsActivity;

    /**
     * Fields to exclude from activity logging.
     *
     * @var array<int, string>
     */
    protected array $activityLogExclude = ['updated_at', 'created_at'];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'name',
        'address',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    // ─── Relationships ──────────────────────────────────────────────

    /**
     * Stock levels at this location.
     *
     * @return HasMany<StockLevel, $this>
     */
    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    /**
     * Stock movements at this location.
     *
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    // ─── Scopes ─────────────────────────────────────────────────────

    /**
     * Filter to active locations only.
     *
     * @param  Builder<Location>  $query
     * @return Builder<Location>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
