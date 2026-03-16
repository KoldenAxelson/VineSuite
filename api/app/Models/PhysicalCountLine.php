<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PhysicalCountLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single line in a physical count — one SKU's system vs. actual comparison.
 *
 * @property string $id UUID
 * @property string $physical_count_id FK to physical_counts
 * @property string $sku_id FK to case_goods_skus
 * @property int $system_quantity Snapshot of on_hand at count start
 * @property int|null $counted_quantity Actual count entered by user
 * @property int|null $variance Computed: counted_quantity - system_quantity
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read PhysicalCount $physicalCount
 * @property-read CaseGoodsSku $sku
 */
class PhysicalCountLine extends Model
{
    /** @use HasFactory<PhysicalCountLineFactory> */
    use HasFactory;

    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'physical_count_id',
        'sku_id',
        'system_quantity',
        'counted_quantity',
        'variance',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'system_quantity' => 'integer',
            'counted_quantity' => 'integer',
            'variance' => 'integer',
        ];
    }

    // ─── Relationships ──────────────────────────────────────────────

    /**
     * @return BelongsTo<PhysicalCount, $this>
     */
    public function physicalCount(): BelongsTo
    {
        return $this->belongsTo(PhysicalCount::class);
    }

    /**
     * @return BelongsTo<CaseGoodsSku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(CaseGoodsSku::class, 'sku_id');
    }
}
