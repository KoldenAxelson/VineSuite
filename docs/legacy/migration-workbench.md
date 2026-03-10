# migration-workbench.md
# WinerySaaS — Migration Workbench
> Internal tool documentation. Not customer-facing.
> Read alongside `README.md` and `architecture.md`.
> This tool is operated by the founder or a trained VA on behalf of onboarding wineries.

---

## Purpose

The Migration Workbench is a standalone internal Laravel + Filament application used to orchestrate
the end-to-end data migration for every new winery subscriber. It handles extraction from source
systems, AI-assisted normalization of messy data, dry-run verification, and the final production
cut-over.

The goal is to make migrations:
- **Repeatable** — same process every time, no improvisation
- **Auditable** — every decision logged, every ambiguity flagged for human review
- **Non-destructive** — nothing touches the winery's production environment until they sign off
- **Progressively autonomous** — manual today, VA-operated tomorrow, self-serve eventually

---

## Workbench Architecture

### Technology
- **Framework:** Laravel 12 (separate application, not part of the main SaaS codebase)
- **Admin UI:** Filament v3 (migration job management, review queues, customer tracking)
- **Browser Automation:** Playwright via a Node.js sidecar process (called from Laravel via
  `symfony/process`)
- **AI Normalization:** Anthropic API (claude-haiku-4-5-20251001 for high-volume normalization,
  claude-sonnet-4-6 for complex ambiguity resolution)
- **Queue:** Redis + Laravel Horizon (long-running extraction and transformation jobs)
- **Staging DB:** Spins up a per-winery schema in the same PostgreSQL instance as the
  migration workbench (separate from production SaaS DB)
- **Deployment:** Single Hetzner server, Forge-managed, internal access only
  (Cloudflare Access or VPN-gated)

### Repository Structure
```
migration-workbench/
├── app/
│   ├── Connectors/             # Source system data extraction
│   │   ├── Contracts/
│   │   │   └── SourceConnector.php     # Interface all connectors implement
│   │   ├── InnoVintConnector.php
│   │   ├── VintraceConnector.php
│   │   ├── EkosConnector.php
│   │   ├── Commerce7Connector.php
│   │   ├── VinesOSConnector.php
│   │   ├── WineDirectConnector.php
│   │   └── PlaywrightConnector.php     # Generic browser automation fallback
│   │
│   ├── Transformers/           # Schema mapping: source → WinerySaaS schema
│   │   ├── Contracts/
│   │   │   └── Transformer.php
│   │   ├── LotTransformer.php
│   │   ├── VesselTransformer.php
│   │   ├── BarrelTransformer.php
│   │   ├── CustomerTransformer.php
│   │   ├── ClubMemberTransformer.php
│   │   ├── OrderHistoryTransformer.php
│   │   ├── InventoryTransformer.php
│   │   ├── LabAnalysisTransformer.php
│   │   └── VineyardTransformer.php
│   │
│   ├── Normalizers/            # AI-assisted data cleaning
│   │   ├── LotNameNormalizer.php
│   │   ├── AdditionLogNormalizer.php
│   │   ├── CustomerDeduplicator.php
│   │   ├── VarietyNormalizer.php
│   │   └── AmbiguityFlagger.php
│   │
│   ├── Jobs/                   # Queued migration jobs
│   │   ├── ExtractSourceDataJob.php
│   │   ├── RunNormalizationJob.php
│   │   ├── RunDryRunJob.php
│   │   ├── GenerateVerificationReportJob.php
│   │   └── RunProductionCutoverJob.php
│   │
│   ├── Models/
│   │   ├── MigrationProject.php        # One per winery onboarding
│   │   ├── RawRecord.php               # Raw extracted data before transformation
│   │   ├── TransformedRecord.php       # Post-transformation, pre-load
│   │   ├── FlaggedItem.php             # Items requiring human review
│   │   └── VerificationChecklist.php
│   │
│   └── Filament/
│       ├── Resources/
│       │   ├── MigrationProjectResource.php
│       │   ├── FlaggedItemResource.php
│       │   └── VerificationChecklistResource.php
│       └── Pages/
│           ├── MigrationDashboard.php
│           └── CutoverControl.php
│
├── playwright/                 # Node.js Playwright scripts
│   ├── scrapers/
│   │   ├── amphora.js
│   │   ├── vinbalance.js
│   │   └── generic-table-scraper.js
│   └── package.json
│
└── tests/
    ├── Connectors/
    ├── Transformers/
    └── Normalizers/
```

---

## Source System Connectors

Every connector implements the same interface regardless of extraction method:

```php
interface SourceConnector
{
    // Returns raw extracted data grouped by entity type
    public function extract(): RawMigrationData;

    // Tests connectivity before committing to a full extract
    public function testConnection(): ConnectionResult;

    // Returns a summary of what will be extracted (counts, date ranges)
    public function preview(): ExtractionPreview;
}
```

### Connector Reference

| Connector | Method | Notes |
|---|---|---|
| `InnoVintConnector` | API + CSV fallback | Read API available; request read-only token from winery |
| `VintraceConnector` | API + CSV fallback | Vintrace API available; some fields only in CSV export |
| `EkosConnector` | CSV | No public read API; winery exports CSVs from Ekos |
| `Commerce7Connector` | API | Full read API; customer/order/club history |
| `VinesOSConnector` | CSV | CSV export from VinesOS admin panel |
| `WineDirectConnector` | API + CSV | Legacy — most wineries already migrating off to Commerce7 |
| `PlaywrightConnector` | Browser automation | Last resort for legacy systems with no export path |

### API Extraction Pattern

```php
class InnoVintConnector implements SourceConnector
{
    public function __construct(
        private string $apiToken,
        private string $winerySlug
    ) {}

    public function extract(): RawMigrationData
    {
        return new RawMigrationData(
            lots:        $this->paginateEndpoint('/lots'),
            vessels:     $this->paginateEndpoint('/vessels'),
            barrels:     $this->paginateEndpoint('/barrels'),
            workOrders:  $this->paginateEndpoint('/work-orders'),
            labAnalysis: $this->paginateEndpoint('/analysis'),
            inventory:   $this->paginateEndpoint('/inventory'),
        );
    }

    private function paginateEndpoint(string $endpoint): Collection
    {
        $results = collect();
        $page = 1;

        do {
            $response = Http::withToken($this->apiToken)
                ->get("https://api.innovint.us/v1{$endpoint}", [
                    'page'     => $page,
                    'per_page' => 100,
                ]);

            $results = $results->merge($response->json('data'));
            $hasMore = $response->json('meta.has_more');
            $page++;

        } while ($hasMore);

        return $results;
    }
}
```

### Playwright Scraper Pattern (Legacy Systems)

For systems with no export path, a Node.js Playwright script navigates the source system UI and
extracts data table by table. Laravel calls it as a subprocess and consumes the JSON output.

```javascript
// playwright/scrapers/generic-table-scraper.js
const { chromium } = require('playwright');

async function scrapeTable(url, credentials, tableSelector) {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage();

    await page.goto(url);
    await page.fill('#username', credentials.username);
    await page.fill('#password', credentials.password);
    await page.click('#login-btn');

    const rows = [];
    let hasNextPage = true;

    while (hasNextPage) {
        await page.waitForSelector(tableSelector);
        const pageData = await page.evaluate((selector) => {
            const table = document.querySelector(selector);
            const headers = [...table.querySelectorAll('th')]
                .map(th => th.innerText.trim());
            const dataRows = [...table.querySelectorAll('tbody tr')]
                .map(tr => {
                    const cells = [...tr.querySelectorAll('td')]
                        .map(td => td.innerText.trim());
                    return Object.fromEntries(
                        headers.map((h, i) => [h, cells[i]])
                    );
                });
            return dataRows;
        }, tableSelector);

        rows.push(...pageData);

        const nextBtn = await page.$('[aria-label="Next page"]:not([disabled])');
        if (nextBtn) {
            await nextBtn.click();
            await page.waitForLoadState('networkidle');
        } else {
            hasNextPage = false;
        }
    }

    await browser.close();
    process.stdout.write(JSON.stringify(rows));
}
```

---

## Transformers

Transformers map source system field names and data shapes to the WinerySaaS schema. One class
per entity type. Each transformer accepts a raw record collection and returns a transformed
collection ready for loading.

```php
class LotTransformer implements Transformer
{
    public function transform(Collection $rawLots, string $sourceSystem): Collection
    {
        return $rawLots->map(function ($raw) use ($sourceSystem) {
            return match($sourceSystem) {
                'innovint' => $this->fromInnoVint($raw),
                'vintrace' => $this->fromVintrace($raw),
                'ekos'     => $this->fromEkos($raw),
                default    => throw new UnsupportedSourceException($sourceSystem),
            };
        });
    }

    private function fromInnoVint(array $raw): TransformedLot
    {
        return new TransformedLot(
            externalId:   $raw['id'],
            name:         $raw['name'],
            variety:      $raw['variety'] ?? null,         // may need normalization
            vintage:      $raw['vintage_year'] ?? null,
            volumeGallons: $raw['volume_gallons'] ?? 0,
            status:       $this->mapStatus($raw['status']),
            notes:        $raw['notes'] ?? null,
            rawData:      $raw,                            // always preserve original
        );
    }

    private function mapStatus(string $sourceStatus): string
    {
        return match($sourceStatus) {
            'active', 'in_progress' => 'in_progress',
            'aging'                 => 'aging',
            'bottled', 'finished'   => 'bottled',
            'archived', 'inactive'  => 'archived',
            default                 => 'in_progress',
        };
    }
}
```

**Rule:** Always store `rawData` (the original source record) on every transformed record. If the
transformation was wrong, you can re-run it without re-extracting from the source system.

---

## AI Normalization Layer

The normalization layer runs after transformation. It handles the messy reality that winery data
entry is inconsistent across 8 years and 15 different cellar hands.

### When to Use AI Normalization

Use it for pattern recognition on unstructured text. Do not use it for business logic decisions.

| Task | Use AI? | Why |
|---|---|---|
| Parse lot name into structured fields | ✅ Yes | Highly variable free text |
| Normalize addition log entries | ✅ Yes | Inconsistent units, abbreviations, typos |
| Deduplicate customer records | ✅ Yes | Name/email fuzzy matching at scale |
| Classify variety from free text | ✅ Yes | "Cab", "CS", "Cabernet" all mean the same thing |
| Map TTB operation codes | ❌ No | Deterministic — use a lookup table |
| Calculate volume conversions | ❌ No | Pure math |
| Determine if a lot is active | ❌ No | Rule-based status mapping |

### Normalization Prompts

**Lot Name Parser**
```php
class LotNameNormalizer
{
    public function normalize(string $lotName): NormalizedLot
    {
        $response = Anthropic::messages()->create([
            'model'      => 'claude-haiku-4-5-20251001', // haiku for cost on high volume
            'max_tokens' => 200,
            'messages'   => [[
                'role'    => 'user',
                'content' => "Extract structured fields from this winery lot name. 
                              Return ONLY valid JSON with these fields: 
                              variety (string), vintage (4-digit year or null), 
                              clone (string or null), vineyard_source (string or null), 
                              other_descriptors (array of strings),
                              confidence (0.0-1.0).
                              If confidence is below 0.8, explain in a 'flag_reason' field.
                              
                              Lot name: \"{$lotName}\""
            ]],
        ]);

        $data = json_decode($response->content[0]->text, true);

        if (($data['confidence'] ?? 1.0) < 0.8) {
            FlaggedItem::create([
                'type'        => 'lot_name',
                'raw_value'   => $lotName,
                'parsed_data' => $data,
                'flag_reason' => $data['flag_reason'] ?? 'Low confidence parse',
            ]);
        }

        return new NormalizedLot($data);
    }
}
```

**Addition Log Normalizer**
```php
$prompt = "Parse this cellar addition log entry into structured data.
           Return ONLY valid JSON with: addition_type, quantity (numeric), 
           unit, target_vessels (array), date (YYYY-MM-DD or null),
           confidence (0.0-1.0), flag_reason (if confidence < 0.8).
           
           Known addition types: SO2, Yeast, DAP, Fermaid-O, Fermaid-K, 
           Tartaric Acid, Citric Acid, Bentonite, Egg White, Isinglass,
           Mega Purple, Oak Chips, ML Bacteria, Copper Sulfate.
           
           Entry: \"{$rawEntry}\"";
```

**Customer Deduplicator**
```php
// Run in batches of 50 records to stay within context limits
$prompt = "Review this list of winery customer records for duplicates and test entries.
           Return JSON with two arrays: 
           'duplicate_groups' (arrays of IDs that appear to be the same person),
           'test_records' (IDs that appear to be test/internal entries).
           Base duplicate detection on: name similarity, email similarity, 
           same address with different name spellings.
           
           Records: " . json_encode($batch);
```

### Cost Control

AI normalization runs as a queued job, never synchronously. Token usage is logged per
migration project. Estimated costs:

| Migration Tier | Approx. Records | Estimated Token Cost |
|---|---|---|
| Spreadsheet (Tier 2) | < 500 lots, < 1k customers | < $0.50 |
| Single platform (Tier 3) | 500-2k lots, 2-10k customers | $1 — $5 |
| Dual platform (Tier 4) | 1k-5k lots, 5-25k customers | $5 — $20 |

Use `claude-haiku-4-5-20251001` for bulk normalization (high volume, simple extraction).
Use `claude-sonnet-4-6` only for flagged items requiring nuanced resolution.

---

## Migration Project Lifecycle

### States

```
INTAKE → EXTRACTING → EXTRACTED → NORMALIZING → NORMALIZED
→ DRY_RUN → REVIEW → APPROVED → CUTTING_OVER → COMPLETE
                ↓
            FLAGGED (requires human attention before proceeding)
```

### Step-by-Step Process

**Step 1: INTAKE**

Create a `MigrationProject` record via the Filament dashboard:
- Winery name and their production SaaS tenant ID
- Source system(s): production platform + DTC platform (if dual)
- API credentials or CSV upload
- Expected go-live date
- Complexity tier (auto-estimated from preview, manually adjustable)
- Migration notes (anything unusual the winery flagged on the intake form)

**Step 2: EXTRACTING**

Dispatch `ExtractSourceDataJob`. This:
- Calls `connector->extract()` for each source system
- Stores all raw records in the `raw_records` table with `migration_project_id`
- Sets project status to `EXTRACTED` on completion
- Logs record counts per entity type

Never modify raw records after extraction. They are the source of truth if anything goes wrong.

**Step 3: NORMALIZING**

Dispatch `RunNormalizationJob`. This:
- Runs each transformer over the raw records → creates `transformed_records`
- Runs AI normalizers over transformed records
- Creates `flagged_items` for anything with confidence < 0.8 or rule violations
- Sets project status to `NORMALIZED` or `FLAGGED` (if flagged items require review)

If `FLAGGED`: operator reviews flagged items in Filament, resolves each one
(accept suggested parse, manually correct, or mark as intentionally blank).
Once all flags resolved, manually advance to `DRY_RUN`.

**Step 4: DRY_RUN**

Dispatch `RunDryRunJob`. This:
- Creates a temporary per-winery schema in the workbench's PostgreSQL instance
- Loads all transformed/normalized records into that schema
- Runs the same validation rules the production import runs
- Generates the `VerificationReport` (see below)
- Sets project status to `REVIEW`

**Step 5: REVIEW**

The winery is given read-only access to their staging environment.
They log in with a temporary credential, poke around, verify things look right.

The `VerificationChecklist` is shared with the winery. Both operator and winery
representative must sign off on each section before proceeding.

**Verification Checklist Sections:**
- [ ] Active lot count matches source system
- [ ] Barrel count and cooperage details look correct
- [ ] Vessel registry matches current cellar layout
- [ ] Customer count is in the expected range
- [ ] Club member count and tier assignments are correct
- [ ] Order history looks complete (spot-check 5-10 orders)
- [ ] Inventory counts match a recent physical count
- [ ] Lab analysis history is present for active lots
- [ ] No duplicate customers visible
- [ ] Card-on-file tokens: re-auth email list reviewed and approved
- [ ] Winery owner has completed a test order on staging
- [ ] Winery owner has completed a test club processing dry run on staging

**Step 6: CUT-OVER**

Scheduled for a specific datetime (avoid Fridays, avoid harvest season,
avoid the week before a planned club processing run).

`RunProductionCutoverJob`:
- Loads all verified records into the winery's production tenant schema
- Runs final validation pass
- Triggers re-auth emails to club members with non-migratable payment tokens
- Sends go-live confirmation to winery owner
- Sets the tenant's `launched_at` timestamp
- Archives the staging schema (kept for 30 days, then dropped)
- Marks migration project `COMPLETE`

---

## Verification Report Format

Generated automatically after dry run. Sent to both operator and winery.

```
MIGRATION VERIFICATION REPORT
Winery: Paso Robles Cellars
Migration ID: mig_01J8X...
Generated: 2025-11-14 09:23 PST
Source: InnoVint (production) + Commerce7 (DTC)

─────────────────────────────────────────────
PRODUCTION DATA
─────────────────────────────────────────────
Lots imported:              847
  ├─ Active / In Progress:  43
  ├─ Aging:                 128
  ├─ Bottled:               589
  └─ Archived:              87

Vessels imported:           67
  ├─ Tanks:                 24
  └─ Barrels:               43 (from 312 individual barrel records)

Lab analysis records:       4,821
Work order history:         2,341

⚠️  ITEMS REQUIRING YOUR REVIEW (3)
  • Lot "2019 Mystery Red" — variety could not be determined from name or 
    composition. Currently set to "Unknown Red Blend". Please correct.
  • Vessel "Tank 12A" — capacity not found in source data. Set to 0 gallons.
    Please update with actual capacity.
  • 847 gallons of Lot 2021-CS-04 in source system exceeds vessel capacity 
    by 12 gallons. Likely a data entry error in InnoVint. Please verify.

─────────────────────────────────────────────
CUSTOMER & DTC DATA
─────────────────────────────────────────────
Customers imported:         12,847
  ├─ Deduplicated:          43 duplicate pairs merged
  └─ Flagged invalid email: 127 (list attached)

Wine club members:          312 active
  ├─ Presidentes Club:      87
  ├─ Estate Club:           156
  └─ Futures Club:          69

Orders imported (2 years):  8,421
  ├─ Tasting room:          3,201
  ├─ Online:                4,102
  └─ Club:                  1,118

⚠️  PAYMENT TOKEN NOTICE
  312 club members have stored payment methods in Commerce7.
  These tokens cannot be transferred. A re-authorization email will be
  sent to all 312 members on go-live day. Members have 30 days to
  update their payment method before their next club processing run.
  Re-auth email preview: [link]

─────────────────────────────────────────────
INVENTORY
─────────────────────────────────────────────
Case goods SKUs imported:   47
Total cases on hand:        1,842

⚠️  VARIANCE NOTICE
  InnoVint export shows 1,842 cases. Please verify this against your
  most recent physical inventory count before approving cut-over.
  If there is a variance, update the count in your staging environment
  before approving.

─────────────────────────────────────────────
SIGN-OFF REQUIRED BEFORE CUT-OVER
─────────────────────────────────────────────
Operator sign-off:          [ ] Pending
Winery sign-off:            [ ] Pending
Scheduled cut-over:         2025-11-18 06:00 PST (Monday, pre-harvest)
```

---

## The Card-on-File Problem — Handling Guide

Wine club members' stored payment methods cannot be transferred between
payment processors. This is a Stripe (and general payment industry) constraint —
tokenized cards are scoped to the merchant account that captured them.

**What this means:**
- All club members need to re-authorize a card with the new platform
- Until they do, they show as "payment method missing" in the club processor
- Their first club run after migration will require a re-auth before charging

**Handling procedure:**

1. **Pre-migration:** During the winery's verification window, review the re-auth
   email list with them. Confirm the email template is personalized and on-brand.

2. **On go-live:** The cutover job automatically sends re-auth emails to all
   affected members. Email contains:
   - Friendly explanation (upgrade message, not alarming)
   - One-click link to the member portal CC update page
   - Deadline (30 days before next club run)

3. **Reminders:** Automated emails at 14 days and 3 days before deadline
   for members who haven't re-authorized.

4. **Club processing:** The first post-migration club run has a pre-run report
   showing how many members still need re-auth. Winery can choose to:
   - Process only members with valid payment methods (skip the rest)
   - Delay the run until re-auth rate is above a threshold (e.g., 90%)
   - Manually re-enter cards for VIP members who haven't responded

**Realistic re-auth rates:**
- 48 hours post-migration: ~60-70%
- 14 days: ~80-85%
- 30 days: ~88-92%
- The remaining ~8-12% are typically inactive members who churn anyway

---

## Ongoing Connector Maintenance

Source system export formats change occasionally. Each connector should have:

- A `version` field tracking the last-verified export format date
- Integration tests that run against fixture files (captured real exports,
  anonymized) to catch format changes
- A `CONNECTOR_CHANGELOG.md` per connector noting any format changes observed

When InnoVint or vintrace push a breaking change to their export format,
the corresponding connector will fail its fixture test. Fix the connector,
update the fixture, bump the version.

---

## Evolution Path

### Phase 1 — Manual (Now → ~25 customers)
Operator runs every migration. Uses the workbench to orchestrate. Learns the
edge cases. Budget 4-8 hours per customer. All AI flagged items resolved by operator.

### Phase 2 — VA-Assisted (~25-100 customers)
Connectors for top 3 source systems (InnoVint, vintrace, Commerce7) are reliable.
A trained VA runs the workbench end-to-end. Operator only reviews flagged items
and approves cut-overs. Migration time: 30-60 minutes of operator attention.

### Phase 3 — Self-Serve (~100+ customers)
The workbench UI is exposed to the winery owner as a "Launch Assistant" inside
the Management Portal (behind a getting-started flow). Winery uploads their
own CSV exports. AI normalization runs automatically. Flagged items surfaced
to the winery owner to resolve themselves. Operator approves cut-over via
a single click. Migration time: 0-15 minutes of operator attention for
clean Tier 2-3 migrations.

### Never Self-Serve
- Tier 5 legacy systems (Playwright scraping)
- Multi-entity merges (two wineries consolidating onto one account)
- Corrupted or severely incomplete source data
- Migrations requiring legal/compliance review

---

## Notes for Future Developers

- **Never auto-approve flagged items.** A human must resolve every flag before
  cut-over. The migration workbench is not allowed to make silent judgment calls
  on customer data.

- **Raw records are immutable.** The `raw_records` table has no `UPDATE` operations.
  If a transformation was wrong, fix the transformer and re-run transformation from
  the raw records. Never edit raw data.

- **Test connectors against real fixture files.** Do not test against live source
  systems in CI. Capture anonymized real exports as fixtures and run against those.

- **Log everything.** Every extraction, every transformation, every AI call, every
  flagged item resolution, every cut-over step. Migration support tickets are
  only solvable with complete logs.

- **The winery's old system stays readable for 30 days post-migration.** Do not
  pressure wineries to cancel their old subscription immediately. The safety net
  reduces anxiety and reduces the chance they roll back.
