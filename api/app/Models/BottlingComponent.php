<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BottlingComponentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bottling component — a packaging material consumed during a bottling run.
 *
 * Tracks bottles, corks, capsules, labels, and cartons used.
 * When `inventory_item_id` is set, links to a DryGoodsItem for cost lookup
 * and auto-deduction at bottling completion.
 */
class BottlingComponent extends Model
{
    /** @use HasFactory<BottlingComponentFactory> */
    use HasFactory;

    use HasUuids;

    public const COMPONENT_TYPES = [
        'bottle',
        'cork',
        'capsule',
        'label',
        'carton',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'bottling_run_id',
        'component_type',
        'product_name',
        'quantity_used',
        'quantity_wasted',
        'unit',
        'inventory_item_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity_used' => 'integer',
            'quantity_wasted' => 'integer',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────

    /**
     * @return BelongsTo<BottlingRun, $this>
     */
    public function bottlingRun(): BelongsTo
    {
        return $this->belongsTo(BottlingRun::class);
    }

    /**
     * Linked dry goods inventory item for cost lookup and auto-deduction.
     *
     * @return BelongsTo<DryGoodsItem, $this>
     */
    public function dryGoodsItem(): BelongsTo
    {
        return $this->belongsTo(DryGoodsItem::class, 'inventory_item_id');
    }
}
