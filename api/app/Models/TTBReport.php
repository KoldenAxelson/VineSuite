<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TTB Report — a generated Form 5120.17 for a reporting month.
 *
 * Reports are auto-generated monthly and start as 'draft' status.
 * Winemakers review and approve before filing with TTB.
 *
 * @property string $id UUID
 * @property int $report_period_month 1-12
 * @property int $report_period_year
 * @property string $status draft|reviewed|filed|amended
 * @property \Carbon\Carbon|null $generated_at
 * @property string|null $reviewed_by UUID of reviewing user
 * @property \Carbon\Carbon|null $reviewed_at
 * @property \Carbon\Carbon|null $filed_at
 * @property string|null $pdf_path Path to generated PDF
 * @property array<string, mixed>|null $data Full report payload (JSONB snapshot)
 * @property string|null $notes
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class TTBReport extends Model
{
    use HasUuids;

    protected $table = 'ttb_reports';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'report_period_month',
        'report_period_year',
        'status',
        'generated_at',
        'reviewed_by',
        'reviewed_at',
        'filed_at',
        'pdf_path',
        'data',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'report_period_month' => 'integer',
            'report_period_year' => 'integer',
            'generated_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'filed_at' => 'datetime',
            'data' => 'array',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return HasMany<TTBReportLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(TTBReportLine::class, 'ttb_report_id');
    }

    // ─── Helpers ─────────────────────────────────────────────────

    /**
     * Check if the report can be regenerated (only drafts).
     */
    public function canRegenerate(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Check if the report can be reviewed.
     */
    public function canReview(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Get the reporting period as a formatted string.
     */
    public function periodLabel(): string
    {
        return date('F Y', mktime(0, 0, 0, $this->report_period_month, 1, $this->report_period_year));
    }
}
