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
 * Inventory auto-deduction will be wired in 04-inventory.md.
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
}
