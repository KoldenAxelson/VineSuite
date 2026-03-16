<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PurchaseOrderLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Line item on a purchase order.
 *
 * @property string $id UUID
 * @property string $purchase_order_id
 * @property string $item_type dry_goods or raw_material
 * @property string $item_id UUID of the DryGoodsItem or RawMaterial
 * @property string $item_name Denormalized name for portability
 * @property float $quantity_ordered
 * @property float $quantity_received
 * @property float|null $cost_per_unit
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property PurchaseOrder $purchaseOrder
 */
class PurchaseOrderLine extends Model
{
    /** @use HasFactory<PurchaseOrderLineFactory> */
    use HasFactory;

    use HasUuids;

    public const ITEM_TYPES = [
        'dry_goods',
        'raw_material',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = [
        'quantity_received' => 0,
    ];

    protected $fillable = [
        'purchase_order_id',
        'item_type',
        'item_id',
        'item_name',
        'quantity_ordered',
        'quantity_received',
        'cost_per_unit',
    ];

    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'decimal:2',
            'quantity_received' => 'decimal:2',
            'cost_per_unit' => 'decimal:4',
        ];
    }

    // ─── Relationships ──────────────────────────────────────────────

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    // ─── Helpers ────────────────────────────────────────────────────

    /**
     * Quantity still outstanding.
     */
    public function quantityRemaining(): float
    {
        return max(0, (float) $this->quantity_ordered - (float) $this->quantity_received);
    }

    /**
     * Whether this line is fully received.
     */
    public function isFullyReceived(): bool
    {
        return (float) $this->quantity_received >= (float) $this->quantity_ordered;
    }
}
