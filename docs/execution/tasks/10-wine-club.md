# Wine Club & Subscriptions

## Phase
Phase 7

## Dependencies
- `01-foundation.md` — auth, event log, Filament, Stripe billing
- `04-inventory.md` — case goods SKUs and stock levels (club shipments deduct inventory)
- `11-ecommerce.md` — club processing creates orders through the eCommerce order pipeline
- `15-payments-advanced.md` — card-on-file charging for club members

## Goal
Build the wine club management and batch processing system. Wine clubs are the most important revenue channel for most DTC wineries — a quarterly club run can generate $50-100k in a single batch. This module handles club configuration (multiple tiers), member management, the batch processing workflow (preview → customize → charge → ship), and failed payment recovery. Club processing is the biggest pain point after TTB — most wineries spend a full day on it. This module reduces it to an hour.

## Data Models

- **ClubTier** — `id` (UUID), `name`, `description`, `shipment_frequency` (monthly/bimonthly/quarterly/biannual/annual), `min_bottles`, `max_bottles`, `default_bottles`, `price_type` (fixed/variable), `fixed_price` (nullable), `discount_percent` (nullable), `perks` (JSON), `max_skips_per_year`, `is_active`, `sort_order`, `created_at`, `updated_at`
- **ClubMember** — `id` (UUID), `customer_id`, `club_tier_id`, `status` (active/paused/cancelled/pending), `join_date`, `join_source` (pos/online/manual), `pause_until` (nullable), `skips_used_this_year` (integer), `payment_token_id` (FK), `ship_to_address_id` (FK), `preferences` (JSON — varietal preferences, no reds, etc.), `notes`, `cancelled_at`, `cancel_reason`, `created_at`, `updated_at`
  - Relationships: belongsTo Customer, belongsTo ClubTier, hasMany ClubShipments
- **ClubProcessingRun** — `id` (UUID), `run_date`, `club_tier_id` (nullable — null means all tiers), `status` (draft/customization_window/processing/completed/cancelled), `customization_window_start`, `customization_window_end`, `total_members_charged`, `total_revenue`, `total_failed`, `notes`, `created_at`, `updated_at`
- **ClubShipment** — `id` (UUID), `processing_run_id`, `club_member_id`, `order_id` (FK — links to eCommerce order), `status` (pending/charged/failed/shipped/delivered), `charge_amount`, `charge_attempts`, `last_charge_error`, `tracking_number`, `shipped_at`, `created_at`, `updated_at`
  - Relationships: belongsTo ClubMember, belongsTo ProcessingRun, belongsTo Order, hasMany ShipmentItems
- **ClubShipmentItem** — `id`, `club_shipment_id`, `sku_id`, `quantity`, `price_per_unit`

## Sub-Tasks

### 1. Club tier configuration
**Description:** Build the club tier management system — define multiple club tiers with different frequencies, bottle counts, pricing, and perks.
**Files to create:**
- `api/app/Models/ClubTier.php`
- `api/database/migrations/xxxx_create_club_tiers_table.php`
- `api/app/Filament/Resources/ClubTierResource.php`
- `api/app/Http/Controllers/Api/V1/ClubTierController.php`
**Acceptance criteria:**
- Unlimited club tiers (e.g., "Library Club", "Estate Club", "Futures Club")
- Configurable per tier: frequency, bottle range, fixed or variable pricing, discount %
- Perks configurable as JSON (complimentary tastings, event access, etc.)
- Tiers reorderable (sort_order for display)

### 2. Club member management
**Description:** Member CRUD with status lifecycle (active → paused → cancelled), linked to customer profiles and payment methods.
**Files to create:**
- `api/app/Models/ClubMember.php`
- `api/database/migrations/xxxx_create_club_members_table.php`
- `api/app/Filament/Resources/ClubMemberResource.php`
- `api/app/Http/Controllers/Api/V1/ClubMemberController.php`
- `api/app/Services/ClubMemberService.php`
**Acceptance criteria:**
- Member record linked to customer profile
- Status lifecycle: pending → active → paused → cancelled
- Join via: POS (from pos-app), online signup widget, manual entry
- Pause/skip tracking with per-tier skip limits
- Payment method and shipping address linked
- Member preferences stored (varietal preferences, quantity preferences)
- Writes `club_member_joined` / `club_member_cancelled` events

### 3. Club processing run — pre-run report
**Description:** Build the pre-run workflow. Before charging, generate a report showing: who will be charged, estimated revenue, members with expired/missing payment methods, members in the customization window.
**Files to create:**
- `api/app/Models/ClubProcessingRun.php`
- `api/database/migrations/xxxx_create_club_processing_runs_table.php`
- `api/app/Services/ClubProcessingService.php`
- `api/app/Filament/Pages/ClubProcessing.php` (custom Livewire page)
**Acceptance criteria:**
- Create a processing run for a specific date and tier(s)
- Pre-run report: member count, estimated revenue, missing payment methods, paused members (excluded), address issues
- Configurable customization window (e.g., "members have 7 days to swap wines")

### 4. Member customization window
**Description:** Before charging, open a window where members can customize their shipment (swap wines, add bottles, skip).
**Files to create:**
- `api/app/Http/Controllers/Api/V1/ClubCustomizationController.php`
- `api/app/Notifications/ClubCustomizationWindowOpenNotification.php`
**Acceptance criteria:**
- Email notification sent to all members when customization window opens
- Member can via portal/widget: view their default shipment, swap specific wines, add extra bottles, skip this shipment
- Staff can customize on behalf of a member in the portal
- Window has configurable start/end dates

### 5. Batch charge execution
**Description:** Process charges for all eligible members in a run. Real-time progress tracking. Failed payment handling with auto-retry.
**Files to create:**
- `api/app/Jobs/ProcessClubChargesJob.php`
- `api/app/Services/ClubChargeService.php`
- `api/app/Models/ClubShipment.php`
- `api/app/Models/ClubShipmentItem.php`
- `api/database/migrations/xxxx_create_club_shipments_table.php`
- `api/database/migrations/xxxx_create_club_shipment_items_table.php`
**Acceptance criteria:**
- Batch charges processed via queued job (not synchronous)
- Real-time progress visible in portal (via Reverb websocket)
- Per-member: charge stored card → success or failure recorded
- Failed charges: auto-retry schedule (3 attempts over 7 days)
- Failed charge notification to member (email)
- Declined card queue in portal for staff to manually address
- Post-run report: total charged, failed count, revenue
- Each successful charge writes `club_charge_processed` event
- Creates orders in the eCommerce order pipeline

### 6. Club shipment management
**Description:** After charging, manage the physical shipment — packing lists, shipping labels, tracking.
**Files to create:**
- `api/app/Services/ClubShipmentService.php`
- `api/app/Filament/Pages/ClubShipments.php`
**Acceptance criteria:**
- Packing list generation per member (what wines, what quantities)
- Batch label printing integration (links to shipping module in eCommerce)
- Tracking number capture (bulk upload from carrier)
- Shipment notification email to member with tracking
- Shipment status tracking: pending → packed → shipped → delivered

### 7. Member self-service portal
**Description:** API endpoints for the member portal widget — members manage their own subscription.
**Files to create:**
- `api/app/Http/Controllers/Api/V1/MemberPortalController.php`
**Acceptance criteria:**
- Member login (via customer auth)
- View current tier and next shipment date
- Update shipping address and payment method
- Skip next shipment (within allowed skips)
- Customize upcoming shipment (during customization window)
- View shipment history and tracking
- Cancel membership (with optional exit survey)

### 8. Club demo data and testing
**Files to modify:** `api/database/seeders/ClubSeeder.php`
**Acceptance criteria:**
- Demo winery has 3 club tiers, 312 active members across tiers, 1 completed processing run with shipment history
- At least 5 members with various states (active, paused, failed payment)

## API Endpoints

| Method | Path | Description | Auth Scope |
|--------|------|-------------|------------|
| GET | `/api/v1/club/tiers` | List club tiers | Authenticated |
| POST | `/api/v1/club/tiers` | Create tier | owner+ |
| GET | `/api/v1/club/members` | List members | admin+ |
| POST | `/api/v1/club/members` | Add member | admin+ |
| PUT | `/api/v1/club/members/{member}` | Update member | admin+ |
| POST | `/api/v1/club/runs` | Create processing run | owner+ |
| POST | `/api/v1/club/runs/{run}/process` | Execute batch charges | owner+ |
| GET | `/api/v1/club/runs/{run}/status` | Get run progress | admin+ |
| GET | `/api/v1/member-portal/profile` | Member self-service | member_auth |
| PUT | `/api/v1/member-portal/address` | Update address | member_auth |
| PUT | `/api/v1/member-portal/payment` | Update payment | member_auth |
| POST | `/api/v1/member-portal/skip` | Skip shipment | member_auth |

## Events

| Event Name | Payload Fields | Materialized State Updated |
|------------|---------------|---------------------------|
| `club_member_joined` | customer_id, tier_id, join_source | club_members |
| `club_member_cancelled` | member_id, reason | club_members |
| `club_charge_processed` | member_id, run_id, amount, status | club_shipments, orders |
| `payment_failed` | member_id, amount, error, retry_number | club_shipments |

## Testing Notes
- **Unit tests:** Pre-run report calculations, skip limit enforcement, customization window logic, charge amount calculation with discounts
- **Integration tests:** Full processing run lifecycle: create run → customization window → batch charge → verify orders created, inventory deducted, events written
- **Critical:** Failed payment retry logic — simulate expired card, verify 3 retries over 7 days, verify member notification on each failure
