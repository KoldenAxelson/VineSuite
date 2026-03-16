# Multi-Brand / Multi-Winery Support

## Phase
Phase 8

## Dependencies
- `01-foundation.md` — multi-tenancy (this extends the tenant model with cross-tenant user linking)
- `19-reporting.md` — consolidated reporting queries across brands
- All modules — multi-brand affects data scoping in every module

> **Pre-implementation check:** This spec predates completed phases. Before starting, load `CONVENTIONS.md` and review phase recaps for any dependency phases listed above. Patterns, service boundaries, and data model decisions may affect assumptions in this spec.

## Ideas to Evaluate

> Review these before starting this phase. If they fit, create additional sub-tasks.

- `ideas/custom-crush-ap-portal.md` — Full alternating proprietorship with per-client schemas, shared resource scheduling (Sub-Task 3 covers simple case; this is the full version)

## Goal
Allow a single user to manage multiple winery brands under one login (Pro tier). Serves two use cases: (1) multi-label operations that run several brands from one facility but need separate data, branding, and compliance per label, and (2) custom crush facilities that produce wine for multiple client wineries and need per-client data isolation with owner-level visibility across all. Each brand remains a fully isolated tenant schema — multi-brand is a cross-tenant user linking and UI convenience, not a data merge.

## Data Models

- **BrandGroup** — `id` (UUID), `name`, `owner_user_id`, `created_at`, `updated_at`
  - Relationships: hasMany BrandGroupTenants, belongsTo User (owner)

- **BrandGroupTenant** — `id`, `brand_group_id`, `tenant_id`, `display_name` (override for brand switcher), `sort_order`, `created_at`
  - Relationships: belongsTo BrandGroup, belongsTo Tenant

- **BrandGroupUser** — `id`, `brand_group_id`, `user_id`, `tenant_id`, `role` (per-tenant role for this user), `created_at`
  - A user can have different roles per tenant within the group (Owner of Brand A, Winemaker of Brand B)

- **CustomFieldDefinition** — `id` (UUID), `entity_type` (lot/customer/vessel/order), `field_name`, `field_label`, `field_type` (text/number/date/select/boolean), `options` (JSON — for select type), `is_required`, `sort_order`, `created_at`, `updated_at` [GROWTH]
  - Per-tenant (each winery defines their own custom fields)

- **CustomFieldValue** — `id`, `custom_field_definition_id`, `entity_type`, `entity_id`, `value` (TEXT — stored as string, cast based on field_type), `created_at`, `updated_at`

## Sub-Tasks

### 1. Brand group and cross-tenant user linking
**Description:** Allow a user to be linked to multiple tenant schemas via a brand group. Implement the brand switcher dropdown in the portal header.

**Files to create:**
- `api/app/Models/BrandGroup.php`
- `api/app/Models/BrandGroupTenant.php`
- `api/app/Models/BrandGroupUser.php`
- `api/database/migrations/` for brand group tables (CENTRAL schema, not tenant schema)
- `api/app/Http/Middleware/BrandSwitcherMiddleware.php`
- `api/app/Filament/Components/BrandSwitcher.php` — Livewire dropdown in portal header
- `api/app/Services/BrandGroupService.php`

**Acceptance criteria:**
- Brand group created when first additional brand is added
- Brand switcher dropdown appears in portal header when user has 2+ brands
- Switching brands changes the active tenant context instantly (no re-login)
- Each brand has fully isolated data (separate tenant schemas — no data sharing)
- User's role can differ per brand (Owner of Brand A, Winemaker of Brand B)
- Brand context persists across page navigation within session
- API tokens are per-brand (a token for Brand A cannot access Brand B data)

**Gotchas:** Brand group tables live in the CENTRAL schema (public), not in tenant schemas — they're cross-tenant by nature. The brand switcher must update the `stancl/tenancy` current tenant context cleanly. Watch for session state leaks — switching brands must fully reset all cached queries, Filament navigation state, and form data. Never allow implicit cross-tenant queries.

### 2. Consolidated cross-brand reporting [PRO]
**Description:** Owner-level reports that aggregate data across all brands in the group.

**Files to create:**
- `api/app/Services/Reporting/ConsolidatedReportService.php`
- `api/app/Filament/Pages/ConsolidatedDashboard.php`

**Acceptance criteria:**
- Revenue across all brands (with per-brand breakdown)
- Inventory totals across all brands
- COGS and margin comparison per brand (side-by-side)
- Club membership totals across all brands
- Only accessible by users with Owner role on ALL brands in the group
- Consolidated view clearly labeled (so users don't confuse it with single-brand data)
- Date range and other filters apply across all brands consistently

**Gotchas:** Consolidated reports must query across tenant schemas — this is the ONE place where cross-schema queries are allowed. Use the central connection or iterate per-tenant. Performance matters: if an owner has 5 brands, the report runs 5 queries and merges results — this must be < 5 seconds. Currency is assumed to be the same across all brands (USD) — no multi-currency support at this point.

### 3. Custom crush client management
**Description:** Custom crush facilities need to track which lots belong to which client winery, bill per-client, and give client wineries read-only access to their lots.

**Files to create:**
- `api/app/Models/CrushClient.php` (within the tenant schema)
- `api/database/migrations/xxxx_create_crush_clients_table.php`
- `api/app/Filament/Resources/CrushClientResource.php`

**Acceptance criteria:**
- Custom crush facility is a single tenant
- Lots can be tagged with a crush client
- Billing reports per client (materials used, labor, overhead allocation)
- Client wineries can get read-only portal access to their specific lots (filtered view)
- Client users see only their lots, not other clients' lots
- Crush client's lots appear in their TTB report (if they have their own permit)

**Gotchas:** Custom crush is a niche but high-value use case. The facility operates under their own bond, but each client may have their own bond too. Lots need to track both "physically located at facility X" and "owned by permit holder Y." This has TTB implications — clarify with real custom crush operators before finalizing the data model.

### 4. Custom fields on key records [GROWTH]
**Description:** Allow wineries to add custom fields to lots, customers, vessels, and orders for winery-specific tracking needs.

**Files to create:**
- `api/app/Models/CustomFieldDefinition.php`
- `api/app/Models/CustomFieldValue.php`
- `api/database/migrations/xxxx_create_custom_field_definitions_table.php`
- `api/database/migrations/xxxx_create_custom_field_values_table.php`
- `api/app/Services/CustomFieldService.php`
- `api/app/Filament/Pages/CustomFieldSetup.php`

**Acceptance criteria:**
- Define custom fields per entity type (lot, customer, vessel, order)
- Field types: text, number, date, select (with options), boolean
- Custom fields appear in Filament forms for the relevant entity
- Custom field values stored in EAV pattern (entity-attribute-value)
- Custom fields searchable and filterable in list views
- Custom field data included in CSV exports
- Custom fields available in reporting filters and segmentation

**Gotchas:** EAV (entity-attribute-value) pattern is simple but slow for queries involving many custom fields. Keep it for the v1 implementation — if performance becomes an issue with heavy custom field usage, consider JSONB columns as an alternative. Limit custom fields per entity type (e.g., 20 max) to prevent abuse. Custom field definitions are per-tenant.

## API Endpoints

| Method | Path | Description | Auth Scope |
|--------|------|-------------|------------|
| GET | `/api/v1/brands` | List brands in group | owner+ |
| POST | `/api/v1/brands/switch/{tenant}` | Switch active brand | Authenticated |
| GET | `/api/v1/consolidated/dashboard` | Cross-brand dashboard [PRO] | owner (all brands) |
| GET | `/api/v1/consolidated/revenue` | Cross-brand revenue report [PRO] | owner (all brands) |
| GET | `/api/v1/custom-fields/{entity_type}` | List custom fields | Authenticated |
| POST | `/api/v1/custom-fields` | Create custom field | admin+ |
| PUT | `/api/v1/{entity_type}/{id}/custom-fields` | Set custom field values | Authenticated |

## Events

| Event Name | Payload Fields | Materialized State Updated |
|------------|---------------|---------------------------|
| `brand_added_to_group` | brand_group_id, tenant_id | brand_group_tenants |
| `brand_switched` | user_id, from_tenant_id, to_tenant_id | (session only) |
| `custom_field_created` | entity_type, field_name, field_type | custom_field_definitions |

## Testing Notes
- **Unit tests:** Brand switcher context isolation (no data leakage between brands). Custom field EAV storage and retrieval. Consolidated report aggregation math.
- **Integration tests:** Create 2 brands → add data to each → switch between them → verify complete data isolation. Consolidated report across 2 brands → verify totals match sum of individual brand totals. Custom field: define field → set value → verify it appears in forms, exports, and filters.
- **CRITICAL:** Brand switching must NEVER leak data between tenants. This is the single most important test. Create Brand A with Lot "Secret Recipe" and Brand B with no lots. Switch to Brand B. Verify Lot "Secret Recipe" is not visible in any view, API endpoint, or search result. Test this for every entity type.
