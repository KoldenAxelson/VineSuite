<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * License — tracks TTB permits, state licenses, and COLAs.
 *
 * Supports expiration tracking with configurable renewal lead days
 * for proactive reminder notifications.
 *
 * @property string $id UUID
 * @property string $license_type ttb_permit, state_license, cola
 * @property string $jurisdiction federal, or state name
 * @property string $license_number
 * @property \Carbon\Carbon|null $issued_date
 * @property \Carbon\Carbon|null $expiration_date
 * @property int $renewal_lead_days
 * @property string|null $document_path
 * @property string|null $notes
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class License extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'license_type',
        'jurisdiction',
        'license_number',
        'issued_date',
        'expiration_date',
        'renewal_lead_days',
        'document_path',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'issued_date' => 'date',
            'expiration_date' => 'date',
            'renewal_lead_days' => 'integer',
        ];
    }

    // ─── Helpers ─────────────────────────────────────────────────

    /**
     * Check if this license is expired.
     */
    public function isExpired(): bool
    {
        if ($this->expiration_date === null) {
            return false;
        }

        return $this->expiration_date->isPast();
    }

    /**
     * Check if this license is within the renewal reminder window.
     */
    public function needsRenewalReminder(): bool
    {
        if ($this->expiration_date === null) {
            return false;
        }

        return $this->expiration_date->lte(
            Carbon::now()->addDays($this->renewal_lead_days)
        );
    }

    /**
     * Days until expiration (negative = already expired).
     */
    public function daysUntilExpiration(): ?int
    {
        if ($this->expiration_date === null) {
            return null;
        }

        return (int) Carbon::now()->startOfDay()->diffInDays($this->expiration_date, false);
    }
}
