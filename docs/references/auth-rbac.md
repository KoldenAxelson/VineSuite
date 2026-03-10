# Authentication & RBAC

> Last updated: 2026-03-10
> Relevant source: `api/app/Models/User.php`, `api/database/seeders/RolesAndPermissionsSeeder.php`, `api/app/Http/Controllers/Auth/`
> Architecture doc: Section 2.1, 2.2

---

## What This Is
Laravel Sanctum token auth for all clients. Scoped tokens per client type (Portal, Cellar App, POS, Widgets, Public API). Role-based access control within each tenant via spatie/laravel-permission v7.2. No Passport, no OAuth — Sanctum throughout.

## How It Works
1. **Registration** — `POST /api/v1/auth/register` with `X-Tenant-ID` header. Creates a User in the tenant schema with role `owner`, assigns the `owner` spatie role, returns a Sanctum token.
2. **Login** — `POST /api/v1/auth/login` with `X-Tenant-ID`, `email`, `password`, `client_type`, `device_name`. Validates credentials, checks `is_active`, creates a Sanctum token scoped to the client type's abilities. Updates `last_login_at`.
3. **Authenticated requests** — Send `Authorization: Bearer {token}` + `X-Tenant-ID`. Stancl's `InitializeTenancyByRequestData` middleware identifies the tenant, Sanctum resolves the user.
4. **Logout** — `POST /api/v1/auth/logout` revokes the current token.
5. **Authorization** — Two layers: token abilities (what the CLIENT can do) and role permissions (what the USER can do). Both must pass.

## Token Abilities

| Client Type | Abilities |
|---|---|
| `portal` | `['*']` — full access |
| `cellar_app` | events, lots, vessels, additions, transfers, work-orders, lab, barrels (create/read/update) |
| `pos_app` | events, orders, customers, products, inventory, club, reservations (create/read/update) |
| `widget` | products:read, reservations:create/read, club:create/read, orders:create/read, customers:create |
| `public_api` | `['*']` — full access (Pro tier only) |

Defined in `User::TOKEN_ABILITIES`.

## Roles

7 roles seeded by `RolesAndPermissionsSeeder` on tenant creation:

| Role | Scope |
|---|---|
| **owner** | All ~55 permissions |
| **admin** | All except billing |
| **winemaker** | Production, compliance, reporting, lab, barrels, vessels, additions, transfers, work-orders |
| **cellar_hand** | Work orders, additions, transfers, barrels, lab results (no settings, users, billing) |
| **tasting_room_staff** | POS, customers, reservations, products, club, orders |
| **accountant** | Reports, COGS, integrations (read-heavy) |
| **read_only** | All `.read` permissions only |

## Key Files
- `app/Models/User.php` — HasApiTokens, HasRoles, HasUuids. TOKEN_ABILITIES constant.
- `app/Models/CentralUser.php` — Central-connection user for multi-winery switching.
- `database/seeders/RolesAndPermissionsSeeder.php` — Full permission matrix.
- `app/Http/Controllers/Auth/LoginController.php` — Token-scoped login.
- `app/Http/Controllers/Auth/RegisterController.php` — Owner registration.
- `app/Http/Middleware/EnsureUserHasRole.php` — Dual check: role column + spatie roles.
- `database/migrations/tenant/2026_03_10_000002_create_permission_tables.php` — Tenant-scoped spatie tables.
- `database/migrations/tenant/2026_03_10_000003_create_personal_access_tokens_table.php` — Tenant-scoped Sanctum tokens.

## Usage Patterns

**Checking permissions in code:**
```php
// Quick role check (no DB query — reads role column)
if ($user->isAdmin()) { ... }

// Granular permission check (spatie — queries DB)
if ($user->hasPermissionTo('lots.create')) { ... }

// Middleware (route-level)
Route::middleware('role:owner,admin')->...
Route::middleware('permission:lots.create')->...
```

**Creating scoped tokens:**
```php
$token = $user->createToken('portal', User::TOKEN_ABILITIES['portal']);
```

## Gotchas
- **Token abilities AND role permissions must both pass.** A cellar_hand user with a portal token (`*` abilities) is still blocked from `settings.update` by their role permissions.
- **MustVerifyEmail is currently removed** from User model. Will be re-added when verification routes are set up.
- **Permission/token tables live only in tenant schemas.** The vendor:publish migrations were deleted from central `database/migrations/`. Don't re-publish them.
- **Auth guard caching in tests:** After revoking a token, call `app('auth')->forgetGuards()` before testing that the token is actually rejected.
- **Password reset flow** — controllers exist but are not yet tested. Will test via Mailpit when verification infrastructure is set up.

## History
- 2026-03-10: Sub-Task 4 complete. Sanctum + spatie/permission installed. 7 roles, ~55 permissions. 13 tests passing (7 auth + 6 RBAC).
