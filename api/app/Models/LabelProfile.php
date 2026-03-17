<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LabelProfileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Label profile — intended label claims for a blend or bottled SKU.
 *
 * Stores the winemaker's label declarations (varietal, AVA, vintage, alcohol)
 * and tracks whether the current blend composition satisfies TTB labeling rules.
 * Once locked (at bottling), the compliance snapshot becomes an immutable audit record.
 *
 * @property string $id UUID
 * @property string|null $blend_trial_id FK to BlendTrial
 * @property string|null $sku_id FK to CaseGoodsSku (linked after bottling)
 * @property string|null $varietal_claim Varietal name on label (e.g. "Syrah")
 * @property string|null $ava_claim AVA on label (e.g. "Paso Robles")
 * @property string|null $sub_ava_claim Sub-AVA on label (e.g. "Adelaida District")
 * @property int|null $vintage_claim Vintage year on label
 * @property string|null $alcohol_claim Declared alcohol % on label
 * @property array<string, mixed>|null $other_claims Additional label claims
 * @property string $compliance_status passing|failing|unchecked
 * @property array<string, mixed>|null $compliance_snapshot Full breakdown at lock time
 * @property \Illuminate\Support\Carbon|null $locked_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class LabelProfile extends Model
{
    /** @use HasFactory<LabelProfileFactory> */
    use HasFactory;

    use HasUuids;

    public const STATUSES = [
        'unchecked',
        'passing',
        'failing',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'blend_trial_id',
        'sku_id',
        'varietal_claim',
        'ava_claim',
        'sub_ava_claim',
        'vintage_claim',
        'alcohol_claim',
        'other_claims',
        'compliance_status',
        'compliance_snapshot',
        'locked_at',
    ];

    protected function casts(): array
    {
        return [
            'vintage_claim' => 'integer',
            'alcohol_claim' => 'decimal:2',
            'other_claims' => 'array',
            'compliance_snapshot' => 'array',
            'locked_at' => 'datetime',
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
     * @return BelongsTo<CaseGoodsSku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(CaseGoodsSku::class, 'sku_id');
    }

    /**
     * @return HasMany<LabelComplianceCheck, $this>
     */
    public function complianceChecks(): HasMany
    {
        return $this->hasMany(LabelComplianceCheck::class)->orderByDesc('checked_at');
    }

    // ─── State ───────────────────────────────────────────────────

    /**
     * Whether this profile is locked (immutable after bottling).
     */
    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    // ─── Scopes ──────────────────────────────────────────────────

    /**
     * @param  Builder<LabelProfile>  $query
     * @return Builder<LabelProfile>
     */
    public function scopePassing(Builder $query): Builder
    {
        return $query->where('compliance_status', 'passing');
    }

    /**
     * @param  Builder<LabelProfile>  $query
     * @return Builder<LabelProfile>
     */
    public function scopeFailing(Builder $query): Builder
    {
        return $query->where('compliance_status', 'failing');
    }
}
