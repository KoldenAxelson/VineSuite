# Vineyard Management

## Phase
Phase 7

## Dependencies
- `01-foundation.md` — event log, auth, Filament
- `02-production-core.md` — Lot model (harvest creates lots)
- `04-inventory.md` — raw materials (spray chemicals inventory)

## Goal
Track vineyard blocks, seasonal activities, sampling data, spray applications, and harvest management. Harvest events auto-create lots in the cellar module, bridging vineyard to production. This module is important for estate wineries that grow their own grapes and need to track farming costs through to COGS.

## Data Models

- **VineyardBlock** — `id` (UUID), `vineyard_name`, `block_name`, `acreage`, `variety`, `clone`, `rootstock`, `row_spacing`, `vine_count`, `year_planted`, `status` (active/replanted/fallow/removed), `grower_contract_id` (nullable), `created_at`, `updated_at`
- **VineyardActivity** — `id` (UUID), `block_id`, `activity_type` (pruning/suckering/shoot_thinning/leaf_pulling/green_harvest/canopy_management/irrigation/cover_crop/harvest), `date`, `workers` (JSON), `hours`, `notes`, `inputs_used` (JSON), `labor_cost`, `created_at`
- **VineyardSample** — `id` (UUID), `block_id`, `date`, `brix`, `ph`, `ta`, `yan` (nullable), `notes`, `created_at`
- **SprayApplication** — `id` (UUID), `block_id`, `date`, `chemical_product`, `rate`, `total_applied`, `unit`, `applicator`, `equipment`, `phi_days` (pre-harvest interval), `rei_hours` (restricted entry interval), `notes`, `created_at`
- **HarvestEvent** — `id` (UUID), `block_id`, `date`, `picker_crew`, `start_time`, `end_time`, `projected_tons`, `actual_tons`, `brix_at_pickup`, `fruit_condition_notes`, `lot_id` (FK — auto-created lot), `grower_payment_amount` (nullable), `created_at`

## Sub-Tasks

### 1. Vineyard block management
**Files to create:** Model, migration, Filament resource, API controller
**Acceptance criteria:** Full CRUD for blocks with variety, clone, rootstock, acreage. Sub-block support. Multi-vineyard (estate + purchased fruit sources). Block status lifecycle.

### 2. Seasonal activity logging
**Files to create:** VineyardActivity model, migration, Filament resource
**Acceptance criteria:** Log activities by type per block with labor tracking. Work order generation from vineyard activities. Labor cost tracking feeds into COGS.

### 3. Vineyard sampling and analytics
**Files to create:** VineyardSample model, migration, chart components
**Acceptance criteria:** Brix/pH/TA/YAN sampling per block per date. Trend charts (Brix curve over season). Target range indicators configurable per block/variety.

### 4. Spray and chemical application log
**Files to create:** SprayApplication model, migration, chemical product library
**Acceptance criteria:** Application log with PHI/REI tracking. Chemical product library with defaults. Organic certification mode — flags non-approved inputs. Application report for audits.

### 5. Harvest management and lot creation
**Files to create:** HarvestEvent model, migration, service
**Acceptance criteria:** Harvest event per block with actual vs projected tonnage. Auto-creates lot in cellar module on harvest completion. Grower payment calculation (price/ton × actual tons, with Brix adjustments). Writes `harvest_logged` event.

## API Endpoints

| Method | Path | Description | Auth Scope |
|--------|------|-------------|------------|
| GET | `/api/v1/vineyard/blocks` | List blocks | Authenticated |
| POST | `/api/v1/vineyard/blocks` | Create block | winemaker+ |
| POST | `/api/v1/vineyard/activities` | Log activity | Authenticated |
| POST | `/api/v1/vineyard/samples` | Record sample | Authenticated |
| POST | `/api/v1/vineyard/sprays` | Log application | winemaker+ |
| POST | `/api/v1/vineyard/harvest` | Log harvest | winemaker+ |

## Events

| Event Name | Payload Fields | Materialized State Updated |
|------------|---------------|---------------------------|
| `harvest_logged` | block_id, tons, brix, lot_id_created | harvest_events, lots |
| `spray_applied` | block_id, chemical, rate, phi | spray_applications |
| `sample_recorded` | block_id, brix, ph, ta | vineyard_samples |
| `activity_logged` | block_id, type, hours, cost | vineyard_activities |

## Testing Notes
- **Integration tests:** Harvest → lot auto-creation → verify lot in production module. Spray PHI tracking (flag if harvest within PHI window).
