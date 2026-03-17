<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LabelComplianceCheckFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Label compliance check — a single rule evaluation against a label profile.
 *
 * Each check validates one TTB labeling rule (varietal 75%, AVA 85%,
 * vintage 95%, or California conjunctive labeling) and stores whether
 * the blend composition passes, with detailed breakdown and remediation.
 *
 * @property string $id UUID
 * @property string $label_profile_id FK to LabelProfile
 * @property string $rule_type varietal_75|ava_85|vintage_95|conjunctive_label
 * @property string $threshold Required percentage threshold
 * @property string|null $actual_percentage Computed actual percentage
 * @property bool $passes Whether the rule is satisfied
 * @property array<string, mixed>|null $details Breakdown and remediation
 * @property \Illuminate\Support\Carbon $checked_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class LabelComplianceCheck extends Model
{
    /** @use HasFactory<LabelComplianceCheckFactory> */
    use HasFactory;

    use HasUuids;

    public const RULE_VARIETAL_75 = 'varietal_75';

    public const RULE_AVA_85 = 'ava_85';

    public const RULE_VINTAGE_95 = 'vintage_95';

    public const RULE_CONJUNCTIVE_LABEL = 'conjunctive_label';

    public const RULE_TYPES = [
        self::RULE_VARIETAL_75,
        self::RULE_AVA_85,
        self::RULE_VINTAGE_95,
        self::RULE_CONJUNCTIVE_LABEL,
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'label_profile_id',
        'rule_type',
        'threshold',
        'actual_percentage',
        'passes',
        'details',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'threshold' => 'decimal:2',
            'actual_percentage' => 'decimal:4',
            'passes' => 'boolean',
            'details' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────

    /**
     * @return BelongsTo<LabelProfile, $this>
     */
    public function labelProfile(): BelongsTo
    {
        return $this->belongsTo(LabelProfile::class);
    }
}
