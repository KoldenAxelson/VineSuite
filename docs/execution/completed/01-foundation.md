# Foundation

## Phase
Phase 1

## Dependencies
None — this is the first module. Everything else depends on this.

## Goal
Stand up the entire platform skeleton: Docker development environment, Laravel 12 application with multi-tenancy, authentication with RBAC, the append-only event log (the single most important data structure in the system), Stripe billing for SaaS subscriptions, the Filament-based Management Portal shell, and CI/CD pipeline. Nothing else can be built until this is solid.

## Data Models

### Central Schema (shared across all tenants)
- **Tenant** — `id`, `name`, `slug`, `domain`, `plan` (starter/growth/pro), `stripe_customer_id`, `stripe_subscription_id`, `launched_at`, `created_at`, `updated_at`
- **CentralUser** — `id`, `name`, `email`, `password`, `email_verified_at`, `created_at` (used for tenant-level user lookup and multi-winery switching)

### Tenant Schema (per-winery)
- **User** — `id`, `central_user_id`, `name`, `email`, `password`, `role` (owner/admin/winemaker/cellar_hand/tasting_room_staff/accountant/read_only), `is_active`, `invited_by`, `last_login_at`, `created_at`, `updated_at`
- **Permission** — `id`, `name`, `guard_name`
- **Role** — `id`, `name`, `guard_name` (uses spatie/laravel-permission)
- **Event** — `id` (UUID), `entity_type`, `entity_id` (UUID), `operation_type`, `payload` (JSONB), `performed_by` (UUID → users), `performed_at` (timestamptz), `synced_at` (timestamptz nullable), `device_id`, `idempotency_key` (unique), `created_at`
- **WineryProfile** — `id`, `name`, `address`, `city`, `state`, `zip`, `phone`, `email`, `website`, `ttb_permit_number`, `bond_type`, `fiscal_year_start_month`, `unit_preference` (gallons/liters), `case_format_preference`, `timezone`, `logo_path`, `created_at`, `updated_at`
- **ActivityLog** — `id`, `user_id`, `action`, `subject_type`, `subject_id`, `properties` (JSON), `created_at`

## Sub-Tasks

### 1. Docker Compose development environment
**Description:** Create `docker-compose.yml` with services for Laravel app (PHP 8.4+), PostgreSQL 16, Redis 7, Meilisearch, Mailpit, and Horizon worker. Bind app to `0.0.0.0:8000` so physical devices on local network can reach it.
**Files to create:**
- `docker-compose.yml`
- `api/Dockerfile` (PHP 8.4-fpm with required extensions: pdo_pgsql, redis, gd, zip, bcmath)
- `api/.env.example`
**Acceptance criteria:**
- `docker compose up -d` starts all services without errors
- `docker compose exec app php -v` shows PHP 8.4+
- PostgreSQL is accessible on port 5432
- Redis on 6379, Meilisearch on 7700, Mailpit on 8025
**Gotchas:** Use `linux/arm64` compatible images (dev machine is Mac Mini M2). PostgreSQL image must be `postgres:16`. Ensure volumes are named for data persistence.

### 2. Laravel 12 project initialization
**Description:** Initialize a fresh Laravel 12 project inside the `api/` directory. Configure PostgreSQL as the default database, Redis for cache and queue, and Mailpit for local mail.
**Files to create/modify:**
- `api/` — fresh Laravel 12 project via `composer create-project`
- `api/.env` — configured for Docker services (DB_HOST=postgres, REDIS_HOST=redis, etc.)
- `api/config/database.php` — ensure pgsql is default
**Acceptance criteria:**
- `php artisan migrate` runs successfully against PostgreSQL
- `php artisan tinker` connects and can query
- Queue connection is set to Redis
- Mail driver pointed at Mailpit
**Gotchas:** Do not use SQLite for testing — always test against real PostgreSQL to match production.

### 3. Multi-tenancy setup with stancl/tenancy
**Description:** Install and configure `stancl/tenancy` for schema-per-tenant isolation. Central schema holds tenant registry and billing data. Each tenant gets an isolated PostgreSQL schema.
**Files to create/modify:**
- `composer require stancl/tenancy`
- `api/config/tenancy.php` — configure schema-per-tenant, central domains, tenant identification by subdomain + API token header
- `api/app/Models/Tenant.php` — extends stancl's Tenant model with plan, stripe fields
- Central migrations for `tenants`, `domains` tables
- `api/app/Jobs/CreateTenantJob.php` — queued job: create schema → run tenant migrations → seed defaults → send welcome email
**Acceptance criteria:**
- Can create a tenant via artisan command or tinker
- Tenant gets its own PostgreSQL schema
- Tenant migrations run in isolation
- Central schema is untouched by tenant migrations
- `CreateTenantJob` completes in under 10 seconds
**Gotchas:** Tenant identification must support both subdomain (`winery.vinesuite.com`) AND API token header (for mobile apps). Test that cross-tenant data access is impossible.

### 4. Authentication system (Sanctum + RBAC)
**Description:** Implement authentication with Laravel Sanctum for API token auth across all clients. Set up role-based access control with spatie/laravel-permission. Define the 7 roles from the planning doc with a permission matrix.
**Files to create/modify:**
- `composer require laravel/sanctum spatie/laravel-permission`
- `api/app/Models/User.php` — HasRoles, HasApiTokens traits
- `api/database/migrations/xxxx_create_permission_tables.php`
- `api/database/seeders/RolesAndPermissionsSeeder.php` — defines all 7 roles and their permissions
- `api/app/Http/Controllers/Auth/LoginController.php`
- `api/app/Http/Controllers/Auth/RegisterController.php`
- `api/app/Http/Controllers/Auth/ForgotPasswordController.php`
- `api/app/Http/Middleware/EnsureUserHasRole.php`
- `api/routes/api.php` — auth routes under `/api/v1/auth/`
**Acceptance criteria:**
- User can register, verify email, login, and receive a Sanctum token
- Token works for authenticated API requests
- Roles are created: Owner, Admin, Winemaker, Cellar Hand, Tasting Room Staff, Accountant, Read-Only
- Permission middleware blocks unauthorized access correctly
- Password reset flow works via Mailpit in dev
**Gotchas:** Sanctum tokens must be scoped per client type (portal, cellar_app, pos_app, widget). No Passport/OAuth — Sanctum only. Tokens should include abilities array for fine-grained control.

### 5. Team invitation system
**Description:** Implement invite-based team member onboarding. Owner sends invite link, invitee clicks link, sets password, gets assigned a role.
**Files to create/modify:**
- `api/app/Models/TeamInvitation.php` — `id`, `email`, `role`, `token`, `invited_by`, `accepted_at`, `expires_at`
- `api/database/migrations/xxxx_create_team_invitations_table.php`
- `api/app/Http/Controllers/TeamInvitationController.php` — send, accept, cancel
- `api/app/Mail/TeamInvitationMail.php`
- `api/app/Http/Controllers/Auth/AcceptInvitationController.php`
**Acceptance criteria:**
- Owner can invite a user by email with a specific role
- Invitee receives email with a unique accept link
- Clicking accept creates the user account with the assigned role
- Expired invitations (>72h) are rejected
- Duplicate invitations to the same email are blocked
**Gotchas:** Invitations must be tenant-scoped. The token should be cryptographically random (Str::random(64)).

### 6. Event log table and base service
**Description:** Create the events table (the core of the entire system) and an EventLogger service class that all modules will use to write events. This is the append-only event log described in architecture.md Section 3.
**Files to create/modify:**
- `api/database/migrations/xxxx_create_events_table.php` — exact schema from architecture doc (UUID PK, entity_type, entity_id, operation_type, payload JSONB, performed_by, performed_at, synced_at, device_id, idempotency_key UNIQUE)
- `api/app/Services/EventLogger.php` — `log(string $entityType, string $entityId, string $operationType, array $payload, ?string $deviceId = null, ?string $idempotencyKey = null): Event`
- `api/app/Models/Event.php` — Eloquent model with proper casts (payload as array, UUIDs, timestamps)
- Indexes: `(entity_type, entity_id)`, `(operation_type)`, `(performed_at)`
**Acceptance criteria:**
- Events can be created with all required fields
- `idempotency_key` enforces uniqueness (duplicate submissions are silently ignored or return existing event)
- `payload` is stored as JSONB and queryable
- `performed_at` is a client-provided timestamp (not auto-generated server time)
- `synced_at` is null for locally-created events, set on server receipt for mobile-synced events
- Events are never updated or deleted — only INSERT operations
**Gotchas:** This table will grow large. Ensure `performed_at` index is a BRIN index for time-series queries. The idempotency key prevents duplicate event writes from mobile sync retries — this is critical for offline safety.

### 7. Event sync API endpoint
**Description:** Create the batch event sync endpoint that mobile apps will POST events to. Accepts an array of events, validates each, writes to the event log, triggers materialized state updates, and returns sync confirmation.
**Files to create/modify:**
- `api/app/Http/Controllers/Api/V1/EventSyncController.php` — `POST /api/v1/events/sync`
- `api/app/Http/Requests/EventSyncRequest.php` — validates array of events with required fields
- `api/app/Services/EventProcessor.php` — processes each event, updates materialized state tables via event-specific handlers
- `api/routes/api.php` — register sync endpoint
**Acceptance criteria:**
- Accepts a batch of events (JSON array)
- Each event with a duplicate `idempotency_key` is skipped (not an error)
- Sets `synced_at` timestamp on receipt
- Returns array of accepted/skipped event IDs
- Validates that `performed_at` is within reasonable range (not more than 30 days in the past)
- Requires authenticated Sanctum token
**Gotchas:** This endpoint must be idempotent — calling it twice with the same events produces the same result. It must handle partial failures gracefully (some events accepted, some rejected). Use database transactions per event, not per batch, so one bad event doesn't reject the entire batch.

### 8. Winery profile and onboarding setup
**Description:** Create the winery profile model and a basic onboarding flow. When a tenant is created, the owner fills in winery details (name, address, TTB permit, preferences).
**Files to create/modify:**
- `api/app/Models/WineryProfile.php`
- `api/database/migrations/xxxx_create_winery_profiles_table.php`
- `api/app/Http/Controllers/Api/V1/WineryProfileController.php`
- `api/database/seeders/DemoWinerySeeder.php` — creates a realistic demo winery with sample data
**Acceptance criteria:**
- Winery profile is created automatically when tenant is provisioned
- Owner can update all profile fields via API
- Unit preferences (gallons/liters) are stored and respected in all subsequent calculations
- TTB permit number is stored for compliance module
- Demo seeder creates a convincing sample winery for development and demos
**Gotchas:** Fiscal year start month affects reporting periods across all modules. Timezone must be stored and used for all scheduled jobs (club processing, reports).

### 9. Filament Management Portal shell
**Description:** Install Filament v3 and set up the basic portal structure with navigation, tenant-aware authentication, and the layout that all subsequent modules will build into.
**Files to create/modify:**
- `composer require filament/filament:"^3.0"`
- `api/app/Providers/Filament/AdminPanelProvider.php` — configure panel, auth, navigation groups
- `api/app/Filament/Pages/Dashboard.php` — placeholder dashboard
- `api/app/Filament/Resources/UserResource.php` — team member management
- Navigation groups: Production, Inventory, Compliance, Sales, Club, CRM, Settings
**Acceptance criteria:**
- Filament admin panel accessible at `/portal`
- Only authenticated users with portal access can enter
- Navigation sidebar shows grouped menu items (empty placeholders for future modules)
- User management resource allows Owner/Admin to view, invite, and deactivate team members
- Tenant context is correctly applied — users only see their winery's data
**Gotchas:** Pin Filament to v3.x — do not allow v4 upgrades without explicit decision. Filament auth must integrate with the existing Sanctum + RBAC setup, not replace it.

### 10. Stripe billing integration (SaaS subscriptions)
**Description:** Set up Stripe Connect for SaaS subscription billing. Tenants subscribe to Starter/Growth/Pro plans. Handle plan changes, cancellations, and webhook events.
**Files to create/modify:**
- `composer require laravel/cashier`
- `api/app/Http/Controllers/BillingController.php` — checkout, portal, plan change
- `api/app/Http/Controllers/WebhookController.php` — Stripe webhook handler
- `api/config/cashier.php`
- Central migrations for Cashier tables
- `api/app/Listeners/HandleSubscriptionChange.php`
**Acceptance criteria:**
- New tenant can subscribe to a plan via Stripe Checkout
- Plan upgrades/downgrades work correctly
- Subscription status is synced via webhooks (payment_succeeded, subscription_updated, subscription_deleted)
- Cancelled tenants retain read-only access for 30 days
- Stripe Customer Portal accessible for billing self-service
**Gotchas:** This is central-schema billing (not per-tenant Stripe Connect — that's for payment processing later). Use Stripe test mode throughout development. Webhook endpoint must be excluded from CSRF protection.

### 11. Activity logging system
**Description:** Implement per-user activity logging for audit trail. Every significant action (create, update, delete on any model) is logged with who, what, when, and old/new values.
**Files to create/modify:**
- `api/app/Models/ActivityLog.php`
- `api/database/migrations/xxxx_create_activity_logs_table.php`
- `api/app/Traits/LogsActivity.php` — trait that models can use to auto-log changes
- `api/app/Filament/Resources/ActivityLogResource.php` — read-only Filament view
**Acceptance criteria:**
- Any model using `LogsActivity` trait automatically logs create/update/delete
- Log entries include: user, action type, model type, model ID, changed fields with old and new values
- Activity log is viewable in the Management Portal (read-only, filterable by user and date)
- Activity log cannot be modified or deleted by any user role
**Gotchas:** This is separate from the event log. The event log tracks winery operations (additions, transfers, etc.). The activity log tracks system-level changes (user edited a profile, changed a setting). Both are immutable.

### 12. CI/CD pipeline setup
**Description:** Set up GitHub Actions for automated testing and deployment. Tests must pass before deployment. Deploy to staging via Laravel Forge.
**Files to create/modify:**
- `.github/workflows/ci.yml` — PHPUnit, Pint, PHPStan, PostgreSQL service container
- `.github/workflows/deploy.yml` — deploy to Forge on main branch push (after CI passes)
- `api/phpunit.xml` — configured for PostgreSQL test database
- `api/phpstan.neon` — level 6 minimum
- `api/.php-cs-fixer.php` or Pint config
**Acceptance criteria:**
- Push to any branch triggers CI (tests, linting, static analysis)
- Push to `main` triggers deploy to staging after CI passes
- PHPUnit runs against a real PostgreSQL service container (not SQLite)
- PHPStan at level 6 passes with zero errors
- Pint formatting is enforced
**Gotchas:** The CI PostgreSQL service must match production version (16). GitHub Actions should cache Composer dependencies for speed. Deploy webhook to Forge should only fire after ALL checks pass.

### 13. API response envelope and error handling
**Description:** Standardize all API responses to use the consistent envelope format from architecture.md. Set up global exception handling for clean error responses.
**Files to create/modify:**
- `api/app/Http/Responses/ApiResponse.php` — helper class for `{ "data": {}, "meta": {}, "errors": [] }` envelope
- `api/app/Exceptions/Handler.php` — customize exception rendering for API routes
- `api/app/Http/Middleware/ForceJsonResponse.php` — ensure all API routes return JSON
- `api/app/Http/Resources/BaseResource.php` — base API resource class using envelope
**Acceptance criteria:**
- All API responses follow `{ "data": ..., "meta": ..., "errors": [...] }` format
- Validation errors return 422 with field-level error details in the envelope
- 404s return clean JSON (not HTML)
- 500s return generic error in production, detailed in development
- Pagination metadata included in `meta` for list endpoints
**Gotchas:** Filament (portal) routes should NOT use the API envelope — they render HTML/Livewire. Only `/api/*` routes use the envelope.

### 14. Rate limiting and API versioning
**Description:** Configure rate limiting per token type and set up API versioning from day one.
**Files to create/modify:**
- `api/app/Providers/RouteServiceProvider.php` — define `/api/v1/` prefix
- `api/routes/api_v1.php` — versioned route file
- `api/app/Http/Middleware/ThrottleByTokenType.php` — different rate limits for portal vs mobile vs widget
**Acceptance criteria:**
- All API routes are under `/api/v1/`
- Rate limiting: Portal tokens 120 req/min, Mobile tokens 60 req/min, Widget tokens 30 req/min
- Rate limit headers included in all responses (`X-RateLimit-Limit`, `X-RateLimit-Remaining`)
- Exceeding rate limit returns 429 with clean error envelope
**Gotchas:** Widget API keys need per-key AND per-origin throttling (prevents abuse without impacting legitimate traffic from busy tasting room weekends).

### 15. Demo seeder with realistic winery data
**Description:** Create a comprehensive database seeder that provisions a demo winery tenant with realistic data for development and demos to winemakers.
**Files to create/modify:**
- `api/database/seeders/DemoWinerySeeder.php`
- `api/database/seeders/DatabaseSeeder.php` — calls demo seeder
**Acceptance criteria:**
- Running `php artisan db:seed` creates a demo tenant "Paso Robles Cellars"
- Demo tenant has: owner user, 2 staff users, winery profile with realistic Paso Robles details
- Includes sample data stubs (empty initially — will be populated as modules are built)
- Demo data is idempotent (running seed twice doesn't create duplicates)
- Includes test users with each role for permission testing
**Gotchas:** This seeder will grow as each module is built. Keep it modular — each module adds its own demo data in a sub-seeder.

## API Endpoints

| Method | Path | Description | Auth Scope |
|--------|------|-------------|------------|
| POST | `/api/v1/auth/register` | Register new account + create tenant | Public |
| POST | `/api/v1/auth/login` | Login, returns Sanctum token | Public |
| POST | `/api/v1/auth/logout` | Revoke current token | Authenticated |
| POST | `/api/v1/auth/forgot-password` | Send password reset email | Public |
| POST | `/api/v1/auth/reset-password` | Reset password with token | Public |
| GET | `/api/v1/auth/user` | Get current user profile | Authenticated |
| POST | `/api/v1/events/sync` | Batch sync events from mobile | Authenticated (cellar_app, pos_app) |
| GET | `/api/v1/winery` | Get winery profile | Authenticated |
| PUT | `/api/v1/winery` | Update winery profile | Authenticated (owner, admin) |
| POST | `/api/v1/team/invite` | Send team invitation | Authenticated (owner, admin) |
| GET | `/api/v1/team` | List team members | Authenticated |
| DELETE | `/api/v1/team/{user}` | Deactivate team member | Authenticated (owner, admin) |

## Events

| Event Name | Payload Fields | Materialized State Updated |
|------------|---------------|---------------------------|
| `user_invited` | email, role, invited_by | team_invitations table |
| `user_activated` | user_id, role | users table |
| `user_deactivated` | user_id, reason | users table |
| `winery_profile_updated` | changed_fields, old_values, new_values | winery_profiles table |

## Testing Notes
- **Unit tests:** EventLogger service (event creation, idempotency, validation), RBAC permission checks for each role, tenant isolation verification
- **Integration tests:** Full registration → tenant creation → login → API access flow. Event sync endpoint with duplicate idempotency keys. Cross-tenant access must be blocked (user in Tenant A cannot access Tenant B data). Stripe webhook handling for subscription lifecycle.
- **Critical:** Test that the events table enforces immutability — no UPDATE or DELETE queries should ever succeed on this table (enforce via database trigger or application-level guard)
