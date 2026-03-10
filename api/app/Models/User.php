<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Tenant-scoped User model.
 *
 * Each tenant has its own users table with roles and permissions.
 * Links to CentralUser via central_user_id for multi-winery switching.
 *
 * @property string $id UUID
 * @property string|null $central_user_id Links to CentralUser for multi-winery switching
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string $role The user's primary role (owner/admin/winemaker/cellar_hand/tasting_room_staff/accountant/read_only)
 * @property bool $is_active
 * @property string|null $invited_by UUID of the user who invited this user
 * @property \Carbon\Carbon|null $last_login_at
 * @property \Carbon\Carbon|null $email_verified_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasRoles;
    use HasUuids;
    use Notifiable;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'central_user_id',
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'invited_by',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Sanctum token abilities based on client type.
     * Tokens are scoped per client: portal, cellar_app, pos_app, widget, public_api.
     */
    public const TOKEN_ABILITIES = [
        'portal' => ['*'],  // Full access via web portal
        'cellar_app' => [
            'events:create',
            'lots:read',
            'vessels:read',
            'work-orders:read',
            'work-orders:update',
            'additions:create',
            'transfers:create',
            'barrels:read',
            'barrels:update',
            'lab:create',
            'fermentation:create',
            'inventory:read',
            'profile:read',
        ],
        'pos_app' => [
            'events:create',
            'orders:create',
            'orders:read',
            'orders:update',
            'customers:read',
            'customers:create',
            'inventory:read',
            'products:read',
            'reservations:read',
            'profile:read',
        ],
        'widget' => [
            'products:read',
            'reservations:create',
            'reservations:read',
            'club:join',
        ],
        'public_api' => ['*'],  // Pro tier — scoped by plan
    ];

    /**
     * Check if the user has the given role (by the `role` column, not spatie).
     * This is a quick check; spatie HasRoles handles the full permission matrix.
     */
    public function hasSimpleRole(string $role): bool
    {
        return $this->role === $role;
    }

    /**
     * Check if the user is an owner or admin.
     */
    public function isAdmin(): bool
    {
        return in_array($this->role, ['owner', 'admin']);
    }
}
