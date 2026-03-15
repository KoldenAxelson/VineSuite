<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BlendTrialComponentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Blend trial component — one source lot's contribution to a blend trial.
 *
 * Stores the percentage and volume from a single source lot.
 * On finalization, the volume is deducted from the source lot.
 */
class BlendTrialComponent extends Model
{
    /** @use HasFactory<BlendTrialComponentFactory> */
    use HasFactory;

    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'blend_trial_id',
        'source_lot_id',
        'percentage',
        'volume_gallons',
    ];

    protected function casts(): array
    {
        return [
            'percentage' => 'decimal:4',
            'volume_gallons' => 'decimal:4',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────

    /**
     * @return BelongsTo<BlendTrial, $this>
     */
    public function blendTrial(): BelongsTo
    {
        return $this->belongsTo(BlendTrial::class);
    }

    /**
     * @return BelongsTo<Lot, $this>
     */
    public function sourceLot(): BelongsTo
    {
        return $this->belongsTo(Lot::class, 'source_lot_id');
    }
}
