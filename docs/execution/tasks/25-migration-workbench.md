# Migration Workbench

## Phase
Phase 8

## Dependencies
- `01-foundation.md` — target tenant schema (migration loads data into production tenant schemas)
- `02-production-core.md` — Lot, Vessel, Barrel models (migration imports production data)
- `04-inventory.md` — SKU and stock models (migration imports inventory)
- `13-crm-email.md` — Customer model (migration imports customer/club data)
- `10-wine-club.md` — ClubMember model (migration imports club memberships)
- `11-ecommerce.md` — Order model (migration imports order history)

## Goal
Build the internal Migration Workbench — a separate Laravel + Filament application used to orchestrate end-to-end data migration for every new winery subscriber. Handles extraction from source systems (InnoVint, vintrace, Commerce7, etc.), AI-assisted normalization of messy data, dry-run verification, and production cut-over. This is the tool that makes "We'll migrate your data" a real offer instead of a placeholder. A high-quality migration tool is not optional — it's a sales blocker (see README.md Key Risks #4).

## Data Models (Workbench-specific — separate DB from main SaaS)

- **MigrationProject** — `id` (UUID), `winery_name`, `target_tenant_id`, `source_systems` (JSON array), `status` (intake/extracting/extracted/normalizing/normalized/dry_run/review/approved/cutting_over/complete/flagged), `complexity_tier` (1-5), `expected_go_live`, `api_credentials_encrypted` (JSON), `notes`, `operator_id`, `created_at`, `updated_at`
- **RawRecord** — `id` (UUID), `migration_project_id`, `source_system`, `entity_type` (lot/vessel/barrel/customer/club_member/order/inventory/lab_analysis), `raw_data` (JSONB — IMMUTABLE, never edited), `created_at`
- **TransformedRecord** — `id` (UUID), `migration_project_id`, `entity_type`, `transformed_data` (JSONB), `raw_record_id`, `normalization_applied` (boolean), `created_at`
- **FlaggedItem** — `id` (UUID), `migration_project_id`, `type` (lot_name/variety/customer_duplicate/volume_mismatch/unknown), `raw_value`, `parsed_data` (JSONB), `flag_reason`, `resolution` (accepted/corrected/skipped), `resolved_by`, `resolved_at`, `created_at`
- **VerificationChecklist** — `id`, `migration_project_id`, `section`, `description`, `operator_approved` (boolean), `winery_approved` (boolean), `notes`, `created_at`

## Sub-Tasks

### 1. Workbench Laravel project setup
**Description:** Create a separate Laravel + Filament application for the migration workbench. Not part of the main SaaS codebase.
**Files to create:**
- `migration-workbench/` — fresh Laravel 12 project
- `migration-workbench/docker-compose.yml` — PostgreSQL, Redis, Node.js (for Playwright)
- Filament v3 installation and panel configuration
**Acceptance criteria:**
- Separate application running on its own port
- PostgreSQL for workbench data + per-winery staging schemas
- Redis for queue (Horizon)
- Filament dashboard accessible
- Internal access only (Cloudflare Access or VPN-gated)
**Gotchas:** This is NOT part of the main SaaS codebase — separate repo, separate deployment, separate database. It connects to the production SaaS database only during cut-over.

### 2. Source connector interface and InnoVint connector
**Description:** Build the connector interface and the first concrete connector for InnoVint (the most common source system).
**Files to create:**
- `migration-workbench/app/Connectors/Contracts/SourceConnector.php`
- `migration-workbench/app/Connectors/InnoVintConnector.php`
**Acceptance criteria:**
- SourceConnector interface: `extract()`, `testConnection()`, `preview()`
- InnoVint connector: API-based extraction with pagination
- Raw records stored in `raw_records` table with `source_system` tag
- Preview shows record counts by entity type before committing to full extraction
- Test connection validates API token
**Gotchas:** InnoVint API may have rate limits. Implement throttling. Always store raw data — never discard it even if transformation succeeds.

### 3. Additional source connectors (vintrace, Commerce7, Ekos, CSV)
**Description:** Build connectors for other common source systems.
**Files to create:**
- `migration-workbench/app/Connectors/VintraceConnector.php`
- `migration-workbench/app/Connectors/Commerce7Connector.php`
- `migration-workbench/app/Connectors/EkosConnector.php`
- `migration-workbench/app/Connectors/CSVConnector.php` — for spreadsheet migrations
**Acceptance criteria:**
- Each connector implements the same interface
- Commerce7: API for customer/order/club data
- Vintrace: API + CSV fallback
- Ekos: CSV only (no public API)
- CSV connector: uploads winery's spreadsheets using provided templates
**Gotchas:** Commerce7 API is well-documented. Vintrace API requires requesting access. Ekos has no API — CSV export from their admin panel is the only path.

### 4. Playwright browser automation connector
**Description:** Last-resort connector for legacy systems with no API or export. Playwright scripts navigate the source UI and scrape data.
**Files to create:**
- `migration-workbench/app/Connectors/PlaywrightConnector.php`
- `migration-workbench/playwright/scrapers/generic-table-scraper.js`
- `migration-workbench/playwright/package.json`
**Acceptance criteria:**
- Laravel calls Playwright via symfony/process
- Script logs in, navigates to data tables, paginates, extracts JSON
- Output consumed by PlaywrightConnector as raw records
- Headless Chrome (no UI needed)
**Gotchas:** Browser automation is fragile — UI changes break scrapers. Use this ONLY for systems with no other export path. Maintain per-scraper version tracking.

### 5. Transformer layer (source → VineSuite schema)
**Description:** Transform raw records from source system format to VineSuite's data schema.
**Files to create:**
- `migration-workbench/app/Transformers/Contracts/Transformer.php`
- `migration-workbench/app/Transformers/LotTransformer.php`
- `migration-workbench/app/Transformers/VesselTransformer.php`
- `migration-workbench/app/Transformers/BarrelTransformer.php`
- `migration-workbench/app/Transformers/CustomerTransformer.php`
- `migration-workbench/app/Transformers/ClubMemberTransformer.php`
- `migration-workbench/app/Transformers/OrderHistoryTransformer.php`
- `migration-workbench/app/Transformers/InventoryTransformer.php`
- `migration-workbench/app/Transformers/LabAnalysisTransformer.php`
**Acceptance criteria:**
- Each transformer maps source fields to VineSuite fields
- Handles per-source-system field naming differences
- Preserves original raw data on every transformed record
- Status mapping (source status names → VineSuite status names)
- Transforms are re-runnable (fix transformer → re-transform from raw data)
**Gotchas:** Always store `rawData` on every transformed record. If transformation was wrong, re-run from raw without re-extracting.

### 6. AI normalization layer
**Description:** AI-assisted normalization for messy data — lot name parsing, variety classification, customer deduplication, addition log parsing.
**Files to create:**
- `migration-workbench/app/Normalizers/LotNameNormalizer.php`
- `migration-workbench/app/Normalizers/VarietyNormalizer.php`
- `migration-workbench/app/Normalizers/CustomerDeduplicator.php`
- `migration-workbench/app/Normalizers/AdditionLogNormalizer.php`
- `migration-workbench/app/Normalizers/AmbiguityFlagger.php`
**Acceptance criteria:**
- Lot name parser: extract variety, vintage, clone, vineyard from free-text names
- Variety normalizer: "Cab", "CS", "Cabernet" → "Cabernet Sauvignon"
- Customer deduplicator: fuzzy match on name/email/address
- Anything with confidence < 0.8 flagged for human review
- Uses claude-haiku-4-5-20251001 for bulk (cheap), claude-sonnet-4-6 for complex flagged items
- Token usage logged per project
**Gotchas:** AI normalization runs as queued jobs, never synchronously. Cost estimate: $0.50-$20 per migration depending on record count.

### 7. Flagged item review workflow
**Description:** Filament UI for reviewing and resolving flagged items (low-confidence AI parses, ambiguities, data quality issues).
**Files to create:**
- `migration-workbench/app/Filament/Resources/FlaggedItemResource.php`
- `migration-workbench/app/Models/FlaggedItem.php`
- `migration-workbench/database/migrations/xxxx_create_flagged_items_table.php`
**Acceptance criteria:**
- Flagged items listed with: raw value, AI's suggested parse, confidence score, flag reason
- Operator can: accept suggestion, manually correct, mark as intentionally blank
- All flags must be resolved before proceeding to dry run
- Resolution logged (who, when, what was changed)
**Gotchas:** Never auto-approve flagged items. A human must resolve every flag.

### 8. Dry run and verification report
**Description:** Load transformed data into a temporary staging schema, validate, and generate a verification report.
**Files to create:**
- `migration-workbench/app/Jobs/RunDryRunJob.php`
- `migration-workbench/app/Jobs/GenerateVerificationReportJob.php`
- `migration-workbench/app/Filament/Pages/CutoverControl.php`
**Acceptance criteria:**
- Creates temporary per-winery PostgreSQL schema
- Loads all transformed records
- Runs validation rules (same as production import)
- Generates verification report: record counts, flagged issues, warnings
- Report shareable with winery for sign-off
- Verification checklist (both operator and winery must sign off)
**Gotchas:** Staging schema is kept for 30 days after cut-over, then dropped. Winery can poke around in staging before approving.

### 9. Production cut-over job
**Description:** Final step — load verified data into the winery's production tenant schema.
**Files to create:**
- `migration-workbench/app/Jobs/RunProductionCutoverJob.php`
**Acceptance criteria:**
- Loads all records into production tenant schema
- Runs final validation pass
- Triggers re-auth emails to club members with non-migratable payment tokens
- Sends go-live confirmation to winery
- Sets tenant `launched_at` timestamp
- Archives staging schema
- Marks project `COMPLETE`
**Gotchas:** Schedule cut-overs for Monday mornings. Avoid Fridays, avoid harvest season, avoid the week before a club run. Card-on-file tokens cannot be migrated between payment processors — all club members need to re-authorize.

### 10. Migration fixture tests
**Description:** Test connectors and transformers against real (anonymized) fixture files.
**Files to create:**
- `migration-workbench/tests/Connectors/InnoVintConnectorTest.php`
- `migration-workbench/tests/Transformers/LotTransformerTest.php`
- `migration-workbench/tests/Fixtures/` — anonymized real export files
**Acceptance criteria:**
- Each connector tested against captured fixture files (not live APIs)
- Fixture files versioned with source system export format date
- Tests catch format changes when source systems update their exports
- All transformers tested with fixtures from each supported source system
**Gotchas:** Never test against live source systems in CI. Capture real exports, anonymize PII, use as fixtures.

## API Endpoints
None — this is an internal tool, not a public API. All interaction is via the Filament dashboard.

## Events
Migration events are logged in the workbench's own database, not the main SaaS event log.

## Testing Notes
- **Unit tests:** Each transformer (per source system), AI normalization (with mock Anthropic API), status mapping logic
- **Integration tests:** Full migration lifecycle: intake → extract (from fixtures) → transform → normalize → dry run → verify → cut-over (to test tenant)
- **Critical:** Raw record immutability — verify no UPDATE queries can run on raw_records table. Re-transformation from raw data must produce identical results.
