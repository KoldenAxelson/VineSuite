<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TTB Report Line Item — one row in a TTB Form 5120.17 report.
 *
 * Each line belongs to a part (I-V) and tracks a specific category of
 * wine operations with gallonage and source event traceability.
 *
 * @property string $id UUID
 * @property string $ttb_report_id
 * @property string $part I, II, III, IV, V
 * @property string $section A or B
 * @property int $line_number
 * @property string $category
 * @property string $wine_type not_over_16, over_16_to_21, over_21_to_24, artificially_carbonated, sparkling, hard_cider, all
 * @property string $description
 * @property int $gallons
 * @property array<int, string> $source_event_ids
 * @property bool $needs_review
 * @property string|null $notes
 * @property Carbon $created_at
 */
class TTBReportLine extends Model
{
    use HasUuids;

    protected $table = 'ttb_report_lines';

    protected $keyType = 'string';

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $fillable = [
        'ttb_report_id',
        'part',
        'section',
        'line_number',
        'category',
        'wine_type',
        'description',
        'gallons',
        'source_event_ids',
        'needs_review',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'gallons' => 'integer',
            'source_event_ids' => 'array',
            'needs_review' => 'boolean',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────

    /**
     * @return BelongsTo<TTBReport, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(TTBReport::class, 'ttb_report_id');
    }
}
