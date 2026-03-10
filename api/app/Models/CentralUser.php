<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Central User model — lives in the central (public) schema.
 *
 * Used for tenant-level user lookup and multi-winery switching.
 * A CentralUser can have accounts in multiple tenants.
 *
 * @property string $id UUID
 * @property string $name
 * @property string $email
 * @property string $password
 * @property \Carbon\Carbon|null $email_verified_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class CentralUser extends Authenticatable
{
    use HasUuids;
    use Notifiable;

    protected $connection = 'pgsql'; // Always use central connection

    protected $table = 'central_users';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
