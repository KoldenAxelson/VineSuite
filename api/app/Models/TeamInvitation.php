<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-scoped team invitation.
 *
 * Owner/Admin invites a user by email with a specific role.
 * The invitee clicks a unique link, sets their password, and gets the assigned role.
 * Invitations expire after 72 hours.
 *
 * @property string $id UUID
 * @property string $email Invitee's email address
 * @property string $role Role to assign when accepted
 * @property string $token Cryptographically random 64-char token
 * @property string $invited_by UUID of the inviting user
 * @property \Carbon\Carbon|null $accepted_at When the invitation was accepted
 * @property \Carbon\Carbon $expires_at When the invitation expires (72h from creation)
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class TeamInvitation extends Model
{
    use HasUuids;
    use LogsActivity;

    /** @var array<int, string> */
    protected array $activityLogExclude = ['token', 'updated_at', 'created_at'];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'email',
        'role',
        'token',
        'invited_by',
        'accepted_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * The user who sent this invitation.
     *
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Check if this invitation has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check if this invitation has already been accepted.
     */
    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    /**
     * Check if this invitation is still valid (not expired, not accepted).
     */
    public function isValid(): bool
    {
        return ! $this->isExpired() && ! $this->isAccepted();
    }

    /**
     * Scope: only pending (not accepted, not expired) invitations.
     *
     * @param  Builder<TeamInvitation>  $query
     * @return Builder<TeamInvitation>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')
            ->where('expires_at', '>', now());
    }
}
