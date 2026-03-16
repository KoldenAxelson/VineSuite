# Authentication & RBAC

> Sanctum + spatie/permission. Scoped tokens per client type. Two-layer auth: token abilities + role permissions.

---

## How It Works

1. **Login:** `POST /api/v1/auth/login` with email, password, client_type, device_name → Sanctum token scoped to client abilities
2. **Request:** Send `Authorization: Bearer {token}` + `X-Tenant-ID` header
3. **Auth check:** Token abilities AND role permissions must both pass
4. **Logout:** Token revoked

---

## Token Abilities

| Client Type | Abilities |
|---|---|
| `portal` | `['*']` (full access) |
| `cellar_app` | events, lots, vessels, additions, transfers, work-orders, lab, barrels (CRUD) |
| `pos_app` | events, orders, customers, products, inventory, club, reservations (CRUD) |
| `widget` | products:read, reservations/club/orders:create/read, customers:create |
| `public_api` | `['*']` (Pro tier only) |

Defined in `User::TOKEN_ABILITIES`.

---

## Roles (7 seeded)

| Role | Permissions |
|---|---|
| **owner** | All ~55 permissions |
| **admin** | All except billing |
| **winemaker** | Production, compliance, reporting, lab, barrels, vessels, additions, transfers, work-orders |
| **cellar_hand** | Work orders, additions, transfers, barrels, lab results (no settings/users/billing) |
| **tasting_room_staff** | POS, customers, reservations, products, club, orders |
| **accountant** | Reports, COGS, integrations (read-heavy) |
| **read_only** | All `.read` permissions only |

---

## Token Name Contract

Format: `client_type|context` (e.g., `portal|registration`, `cellar_app|My iPhone`)

**Why?** `ThrottleByTokenType` middleware splits on `|` to determine rate-limit tier. Don't encode as DB column — keeps vendor schema unmodified.

**What breaks if skipped:** Rate limiter falls back to lowest tier (30 req/min) silently instead of failing loudly.

---

## Usage

```php
// Quick role check (no DB query)
if ($user->isAdmin()) { ... }

// Granular permission (DB query)
if ($user->hasPermissionTo('lots.create')) { ... }

// Middleware
Route::middleware('role:owner,admin')->...
Route::middleware('permission:lots.create')->...

// Creating scoped token
$token = $user->createToken('cellar_app|My iPhone', User::TOKEN_ABILITIES['cellar_app']);
```

---

## Rate Limits

| Client Type | Limit |
|---|---|
| portal | 120 req/min |
| cellar_app | 60 req/min |
| pos_app | 60 req/min |
| widget | 30 req/min |
| public_api | 60 req/min |
| unauthenticated | 30 req/min (per IP) |

Responses include `X-RateLimit-Limit`, `X-RateLimit-Remaining` headers. 429 with `Retry-After` on limit exceeded.

---

## Gotchas

- **Both layers must pass** — cellar_hand user with portal token (`*` abilities) is still blocked from `settings.update` by role permissions
- **Token + role check:** Middleware `EnsureUserHasRole` does dual check
- **Permission/token tables in tenant schemas only** — not in central DB
- **Test guard caching:** Call `app('auth')->forgetGuards()` after revoking tokens before testing rejection
- **MustVerifyEmail removed** from User — re-add when verification routes are set up

---

## Key Files

- `app/Models/User.php` — TOKEN_ABILITIES constant, HasApiTokens, HasRoles
- `app/Http/Controllers/Auth/LoginController.php` — Token-scoped login
- `app/Http/Middleware/EnsureUserHasRole.php` — Dual auth check
- `database/seeders/RolesAndPermissionsSeeder.php` — Full matrix
- `database/migrations/tenant/2026_03_10_000002_create_permission_tables.php`
- `database/migrations/tenant/2026_03_10_000003_create_personal_access_tokens_table.php`

---

*Phase 1 (Sub-Task 4). Sanctum + spatie v7.2. 13 tests (7 auth + 6 RBAC). Rate limiting via token type (Sub-Task 14).*
