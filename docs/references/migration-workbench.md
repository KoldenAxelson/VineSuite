# Migration Workbench

> Internal tool for orchestrating end-to-end winery data migration. Not part of main codebase.
> Standalone Laravel + Filament application, separate PostgreSQL schema.

---

## Core Contract

**Flow:** INTAKE → EXTRACTING → EXTRACTED → NORMALIZING → (FLAGGED or NORMALIZED) → DRY_RUN → REVIEW → APPROVED → CUTTING_OVER → COMPLETE

**Five source connector types:**
- API (InnoVint, Vintrace, Commerce7)
- CSV export (Ekos, VinesOS, WineDirector)
- Browser automation (Playwright, last resort)

**Three AI models used:**
- Haiku 4.5 for bulk normalization (lots of data, simple extraction)
- Sonnet 4.6 for flagged items (nuanced ambiguity resolution)
- Cost model: < $20 for all but largest Tier 4 migrations

**Never auto-approve flags.** Human reviews every flagged item before cutover.

---

## Repository Structure

```
migration-workbench/
├── app/
│   ├── Connectors/
│   │   ├── Contracts/SourceConnector.php    # Interface
│   │   ├── InnoVintConnector, VintraceConnector, EkosConnector, etc.
│   │   └── PlaywrightConnector              # Browser automation fallback
│   │
│   ├── Transformers/
│   │   ├── Contracts/Transformer.php
│   │   └── LotTransformer, VesselTransformer, CustomerTransformer, etc.
│   │
│   ├── Normalizers/
│   │   ├── LotNameNormalizer
│   │   ├── AdditionLogNormalizer
│   │   ├── CustomerDeduplicator
│   │   └── AmbiguityFlagger
│   │
│   ├── Jobs/
│   │   ├── ExtractSourceDataJob
│   │   ├── RunNormalizationJob
│   │   ├── RunDryRunJob
│   │   ├── GenerateVerificationReportJob
│   │   └── RunProductionCutoverJob
│   │
│   └── Models/
│       ├── MigrationProject
│       ├── RawRecord, TransformedRecord
│       ├── FlaggedItem
│       └── VerificationChecklist
│
├── playwright/scrapers/
│   ├── generic-table-scraper.js
│   └── package.json
│
└── tests/
```

---

## Connector Interface

```php
interface SourceConnector
{
    // Extract all raw data from source system
    public function extract(): RawMigrationData;

    // Test connectivity (no side effects)
    public function testConnection(): ConnectionResult;

    // Preview: record counts, date ranges
    public function preview(): ExtractionPreview;
}
```

**Gotchas:**
- Always store original `rawData` on every transformed record — enables re-transformation without re-extraction
- Pagination: loop until `has_more` is false; log record counts per entity type

---

## Transformer Pattern

One class per entity type. Maps source fields → WinerySaaS schema.

```php
class LotTransformer implements Transformer
{
    public function transform(Collection $raw, string $sourceSystem): Collection
    {
        return $raw->map(fn($r) => match($sourceSystem) {
            'innovint' => $this->fromInnoVint($r),
            'vintrace' => $this->fromVintrace($r),
            default => throw new UnsupportedSourceException(),
        });
    }
}
```

**Rule:** Always preserve raw data. Status mappings must handle multiple source variants (e.g., 'active', 'in_progress' both → 'in_progress').

---

## AI Normalization

**Use AI for:** Lot name parsing, addition log entries, customer deduplication, variety classification.
**Don't use AI for:** TTB code mapping, volume math, status rules (use lookup tables).

**Cost control:**
- Haiku for high-volume simple work
- Sonnet only for flagged items needing human judgment
- Batch processing (50-100 records) to manage context
- Log all token usage per migration

**Confidence threshold:** Flag items with confidence < 0.8. Always include `flag_reason` when flagged.

---

## Migration Lifecycle

**Step 1: INTAKE**
- Create MigrationProject: winery name, source system, API creds/CSV, expected go-live, complexity tier
- Never modify intake data after submission

**Step 2: EXTRACTING → EXTRACTED**
- Dispatch ExtractSourceDataJob
- Stores all raw records in raw_records table
- Logs counts per entity type

**Step 3: NORMALIZING**
- Dispatch RunNormalizationJob
- Transforms raw → TransformedRecord
- Runs AI normalizers
- Creates FlaggedItem for confidence < 0.8
- Status: NORMALIZED (no flags) or FLAGGED (requires review)

**Step 4: (Conditionally) REVIEW FLAGGED ITEMS**
- Operator reviews each flag in Filament
- Accept, correct, or mark intentional
- Manually advance to DRY_RUN

**Step 5: DRY_RUN → REVIEW**
- Dispatch RunDryRunJob
- Loads into temporary per-winery staging schema
- Runs same validation rules as production
- Generates VerificationReport
- Status: REVIEW

**Step 6: VERIFICATION**
- Winery given read-only staging access
- Both operator and winery sign off on 12-item checklist
- Checklist includes: counts, vessel/barrel details, customer deduplication, payment token notice

**Step 7: CUT-OVER**
- Scheduled for specific datetime (avoid Fridays, harvest season, pre-club-run)
- Dispatch RunProductionCutoverJob
- Load verified data → production schema
- Send re-auth emails (payment tokens cannot migrate)
- Mark tenant `launched_at`
- Archive staging schema (30-day retention, then drop)

---

## Card-on-File Handling

Payment tokens are Stripe-merchant-scoped and cannot transfer. All club members must re-authorize.

**Reality:**
- 48h post-migration: ~60-70% re-auth rate
- 14d: ~80-85%
- 30d: ~88-92%
- Remaining ~8-12% are inactive anyway

**Process:**
1. Pre-migration: Review re-auth email with winery (on-brand, friendly)
2. Cutover job sends re-auth emails automatically
3. Reminders at 14d and 3d before next club run
4. First post-migration club run can process only auth'd members or wait for higher re-auth rate

---

## Evolution Path

**Phase 1 (Now):** Manual. Operator runs every migration. 4-8 hours per customer.

**Phase 2:** VA-trained. Top 3 connectors stable. VA runs end-to-end. Operator reviews flags + approves cutover. 30-60 min operator attention.

**Phase 3:** Self-serve. Workbench exposed as "Launch Assistant" in Management Portal. Winery uploads CSV. Winery resolves flags themselves. 0-15 min operator attention for Tier 2-3.

**Never self-serve:** Tier 5 (Playwright), multi-entity merges, corrupted/incomplete data, legal review needed.

---

## Rules for Developers

- **Never auto-approve flags** — humans must resolve every one
- **Raw records immutable** — no UPDATE on raw_records. Re-run transformer if wrong.
- **Test with real fixture files** — not live source systems. Anonymize before committing.
- **Log everything** — every extraction, transformation, AI call, flag resolution, cutover step
- **30-day safety net** — winery's old system stays readable for 30 days post-migration

---

## Verification Report (Auto-Generated)

Sent to operator + winery after dry run. Includes:
- Entity counts per status
- Flagged items requiring review (specific examples, not just totals)
- Payment token re-auth notice
- Inventory variance check
- Dual sign-off checkboxes

---

*Reference version for Phase 1 (manual). As connectors stabilize and seed data accumulates, this will evolve toward VA-assisted and self-serve modes.*
