# Public API & Webhooks

## Phase
Phase 8

## Dependencies
- `01-foundation.md` — Sanctum auth (Pro-tier API uses scoped Sanctum tokens with abilities)
- All modules — the public API exposes endpoints from every module
- `18-notifications.md` — webhook dispatch uses the same event listener pattern

## Goal
Expose a documented, versioned public REST API for Pro tier subscribers. Wineries can integrate their own custom systems, build reports, or connect third-party tools. Includes configurable webhook subscriptions for real-time event notifications with HMAC signing and delivery guarantees. Uses Sanctum scoped token abilities (not Passport/OAuth) for fine-grained permissions. The API design follows the same patterns as the internal API — Pro-tier API access is a permission layer on top of existing endpoints, not a separate API.

## Data Models

- **ApiToken** — uses Laravel Sanctum's `personal_access_tokens` table with additional metadata:
  - Extended fields: `description`, `last_used_ip`, `requests_count`, `rate_limit_per_minute` (default 60), `is_sandbox`
  - Token abilities define scopes: `read:lots`, `write:lots`, `read:orders`, `write:orders`, `read:customers`, `read:inventory`, `read:reports`, `webhooks:manage`

- **WebhookEndpoint** — `id` (UUID), `url`, `description`, `secret` (HMAC signing key, auto-generated), `events` (JSON array of subscribed event types), `is_active`, `last_delivery_at`, `failure_count` (consecutive failures), `disabled_at` (auto-disabled after 10 consecutive failures), `created_at`, `updated_at`
  - Relationships: hasMany WebhookDeliveries

- **WebhookDelivery** — `id` (UUID), `webhook_endpoint_id`, `event_type`, `payload` (JSON), `response_status` (integer HTTP status), `response_body` (TEXT, truncated to 1000 chars), `attempt_number`, `delivered_at` (nullable — null if failed), `next_retry_at` (nullable), `created_at`
  - Relationships: belongsTo WebhookEndpoint

## Sub-Tasks

### 1. API token management with scoped abilities
**Description:** Pro tier users can create, manage, and revoke API tokens with specific permission scopes via Sanctum abilities.

**Files to create:**
- `api/app/Filament/Pages/ApiTokenManagement.php`
- `api/app/Http/Middleware/EnsureProTier.php`
- `api/app/Http/Middleware/EnsureTokenHasAbility.php`
- `api/app/Http/Middleware/ApiRateLimiter.php` — per-token rate limiting

**Acceptance criteria:**
- Create tokens with selected abilities (checkboxes for each scope)
- Token description and label (e.g., "QuickBooks Integration", "Custom Dashboard")
- Revoke tokens (immediate effect)
- Token activity: last used timestamp, IP, total request count
- Per-token rate limiting (configurable, default 60 requests/minute)
- Token list shows all active tokens with last used time
- Separate rate limits from internal app tokens (Portal, Cellar, POS have higher limits)

**Gotchas:** Sanctum tokens are plain-text in the database (hashed). The full token is only shown ONCE at creation — if the user loses it, they must create a new one. Display a clear "copy this now, you won't see it again" UI. Pro tier gate: non-Pro tenants get 403 on token creation endpoint. Token abilities are checked via `$request->user()->tokenCan('read:lots')` — ensure every API endpoint checks the relevant ability.

### 2. Public API endpoint documentation (OpenAPI/Swagger)
**Description:** Auto-generated interactive API documentation from route definitions and request/response schemas.

**Files to create:**
- Install and configure `dedoc/scramble` (or `darkaonline/l5-swagger`)
- Add OpenAPI attributes/annotations to all API controllers
- `api/app/Http/Controllers/Api/V1/ApiDocsController.php`

**Acceptance criteria:**
- Interactive API docs at `/api/docs` (publicly accessible, no auth required to browse)
- All endpoints documented with: method, path, description, request body schema, response schema, required abilities
- Authentication section: how to create a token, how to use Bearer auth
- "Try it out" functionality (requires token)
- Code examples in: cURL, PHP, Python, JavaScript
- Versioned: docs clearly show `/api/v1/` prefix

**Gotchas:** Use `dedoc/scramble` — it generates OpenAPI specs from Laravel route definitions and Form Requests automatically, requiring minimal annotations. Much less maintenance than manually writing Swagger YAML. The docs page must be rate-limited (prevent scraping). Code examples should show real endpoint paths, not placeholders.

### 3. Webhook subscription system
**Description:** Wineries configure webhook endpoints that receive signed HTTP POST notifications when events occur.

**Files to create:**
- `api/app/Models/WebhookEndpoint.php`
- `api/app/Models/WebhookDelivery.php`
- `api/database/migrations/xxxx_create_webhook_endpoints_table.php`
- `api/database/migrations/xxxx_create_webhook_deliveries_table.php`
- `api/app/Services/WebhookDispatchService.php`
- `api/app/Jobs/DispatchWebhookJob.php`
- `api/app/Listeners/WebhookEventListener.php` — subscribes to relevant events
- `api/app/Filament/Resources/WebhookEndpointResource.php`

**Acceptance criteria:**
- Configure webhook URL + select events to subscribe to (checkboxes)
- HMAC-SHA256 signature on every payload (`X-Signature` header, same pattern as Stripe)
- Payload includes: event type, timestamp, tenant ID, structured event data
- Delivery log: each attempt recorded with status code, response body (truncated), timing
- Retry with exponential backoff: 1 min, 5 min, 30 min, 2 hours, 12 hours (5 attempts total)
- Auto-disable endpoint after 10 consecutive failures (with notification to winery)
- Re-enable requires manual action (prevents silently broken webhooks)
- Subscribable events: `order.placed`, `order.fulfilled`, `order.refunded`, `payment.captured`, `payment.failed`, `club.charge.processed`, `club.member.joined`, `club.member.cancelled`, `lot.created`, `lot.updated`, `inventory.adjusted`, `reservation.booked`

**Gotchas:** Webhook payloads must be idempotent-safe — include an event UUID so the receiver can deduplicate. Signing key is per-endpoint (not per-tenant) — if a winery has multiple endpoints, each has its own secret. Payload size limit: truncate large payloads to 64KB. Never include sensitive data (full customer PII, payment tokens) in webhook payloads — include IDs and let the receiver fetch details via the API.

### 4. Webhook testing tools
**Description:** Tools for developers to test their webhook integration without waiting for real events.

**Files to create:**
- `api/app/Http/Controllers/Api/V1/WebhookTestController.php`

**Acceptance criteria:**
- "Send test event" button per webhook endpoint (sends a test payload with `test: true` flag)
- Webhook delivery log visible in portal (status, response, timing for each delivery attempt)
- Signature verification example code in docs (PHP, Python, Node.js, Ruby)
- Sandbox mode: API tokens marked as sandbox only trigger webhooks to endpoints also marked sandbox

**Gotchas:** Test events must be clearly distinguishable from real events (`"test": true` in payload). Provide a signature verification code snippet in every common language — this is where most integrators get stuck.

### 5. API versioning and deprecation strategy
**Description:** Establish the versioning pattern for long-term API stability.

**Files to create:**
- `api/app/Http/Middleware/ApiVersioning.php`
- Documentation in API docs about versioning policy

**Acceptance criteria:**
- All endpoints under `/api/v1/` prefix (already established in Phase 1)
- Version specified in URL path (not header — simpler for integrators)
- Deprecation policy: v1 endpoints supported for minimum 12 months after v2 launch
- Deprecated endpoints return `Sunset` header with retirement date
- Changelog endpoint or page showing breaking changes between versions

**Gotchas:** Don't over-engineer versioning for v1 launch. The main goal is establishing the pattern so it's not a breaking retrofit later. Practically, v2 is unlikely before the platform has 50+ active API integrators.

## API Endpoints

| Method | Path | Description | Auth Scope |
|--------|------|-------------|------------|
| POST | `/api/v1/tokens` | Create API token | owner+ (Pro) |
| GET | `/api/v1/tokens` | List tokens | owner+ (Pro) |
| DELETE | `/api/v1/tokens/{id}` | Revoke token | owner+ (Pro) |
| GET | `/api/v1/webhooks` | List webhook endpoints | webhooks:manage |
| POST | `/api/v1/webhooks` | Create webhook endpoint | webhooks:manage |
| PUT | `/api/v1/webhooks/{id}` | Update webhook | webhooks:manage |
| DELETE | `/api/v1/webhooks/{id}` | Delete webhook | webhooks:manage |
| GET | `/api/v1/webhooks/{id}/deliveries` | Delivery log | webhooks:manage |
| POST | `/api/v1/webhooks/{id}/test` | Send test event | webhooks:manage |
| GET | `/api/docs` | API documentation | Public |

## Events

| Event Name | Payload Fields | Materialized State Updated |
|------------|---------------|---------------------------|
| `api_token_created` | token_id, abilities, description | personal_access_tokens |
| `api_token_revoked` | token_id | personal_access_tokens |
| `webhook_endpoint_created` | endpoint_id, url, events | webhook_endpoints |
| `webhook_delivered` | endpoint_id, event_type, status_code | webhook_deliveries |
| `webhook_failed` | endpoint_id, event_type, error, attempt | webhook_deliveries |
| `webhook_endpoint_auto_disabled` | endpoint_id, consecutive_failures | webhook_endpoints |

## Testing Notes
- **Unit tests:** Token ability checking (token with `read:lots` can GET lots, cannot POST lots). HMAC signature generation and verification. Webhook retry scheduling (exponential backoff timing). Rate limiter per-token isolation.
- **Integration tests:** Full webhook lifecycle: create endpoint → subscribe to events → trigger event → verify webhook delivered with correct payload and valid signature. Token creation → API call → verify abilities enforced. Auto-disable after 10 failures → re-enable → verify delivery resumes.
- **Critical:** Signature verification — webhook receivers MUST be able to verify payload authenticity. Test that a tampered payload fails verification. Token isolation — a token for Tenant A must not access Tenant B data, even if both are Pro tier. Rate limiting — verify a burst of 100 requests within 1 minute correctly throttles after limit.
