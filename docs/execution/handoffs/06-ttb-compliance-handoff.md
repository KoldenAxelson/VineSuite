# Phase 6 Handoff — TTB & Regulatory Compliance

> Phases 1–5 complete. 779+ tests passing, PHPStan level 6 (zero errors), Pint (zero style issues).

## Read Before Coding

1. `docs/README.md` — doc routing table
2. `docs/CONVENTIONS.md` — code patterns (treat as law)
3. `docs/WORKFLOW.md` — dev lifecycle
4. `docs/execution/tasks/06-ttb-compliance.md` — your task spec (note the "Ideas to Evaluate" block — `label-compliance-engine.md` was flagged for potential absorption)

## What's Relevant From Previous Phases

**Phase 1 (Foundation):** EventLogger is the sole event write path. Immutable events with entity_type/entity_id/operation_type. `event_source` column auto-resolves from operation_type prefix. Auth uses Sanctum + role-based middleware. Filament v3 portal with tenant subdomains.

**Phase 2 (Production Core):** All production operations that generate TTB-reportable events live here — lot creation (`lot_created`), transfers (`lot_volume_adjusted`), blending (`blend_finalized`), additions (`addition_created`), bottling (`bottling_completed`). BlendTrialComponent tracks source lots with volume_gallons and percentage. BottlingRun has volume_bottled_gallons, bottles_filled, waste_percent. The event payloads are self-contained (include lot name, variety alongside FK UUIDs). Phase recap: `phase-recaps/phase-2-production-core.md`.

**Phase 3 (Lab & Fermentation):** Lab analyses store alcohol %, pH, TA, SO2 — the alcohol reading is critical for TTB wine type classification (table <14%, dessert 14-24%). FermentationRound tracks brix-to-alcohol conversion. Access lab data via `LabAnalysis::where('lot_id', $lot->id)->where('parameter', 'alcohol')`.

**Phase 4 (Inventory):** CaseGoodsSku is the bottled wine product. Stock movements track removals from bond (sold, transferred_out). `event_source = 'inventory'` for stock operations. InventoryService handles all stock mutations.

**Phase 5 (Cost Accounting):** LotCostEntry tracks costs per lot. CostAccumulationService is the cost write path. `event_source = 'accounting'` for cost/cogs events. The `cost_per_bottle` on CaseGoodsSku is populated at bottling.

## Carry-Over Debt

- **Auto-deduction from bottling not wired:** BottlingService doesn't auto-deduct dry goods from InventoryService when packaging is consumed. This means stock levels for packaging materials may be inaccurate. Doesn't block TTB work, but the "removals from bond" calculation for Part IV should rely on event log data (bottling_completed events), not stock movements, since stock movements for bottling don't exist yet.
- **No scheduled job infrastructure yet:** Task 06 sub-task 2 calls for a monthly report generation job. Laravel's scheduler is configured but no jobs exist yet. You'll be establishing the job pattern for the project.

## Phase-Specific Notes

### Event Log is Your Primary Data Source
TTB reports aggregate from the event log, not from model tables. This is by design — the event log is immutable and auditable. Query events by `operation_type`, filter by `performed_at` date range for the reporting month. Use `EventLogger::getByOperationType()` and `EventLogger::getEntityStream()`.

### Wine Type Classification Depends on Lab Data
TTB Form 5120.17 categorizes wine as table (≤14% alc), dessert (14-24%), sparkling, or special natural. You'll need to look up `LabAnalysis` records for the lot's alcohol percentage. If no lab data exists for a lot, default to table wine (most common) and flag the line item for manual review.

### TTB Event Prefix
Use `ttb_` prefix for new events (e.g., `ttb_report_generated`, `ttb_report_reviewed`). This will auto-resolve to a new event source. You may need to add `'ttb_' => 'compliance'` to `config/event-sources.php`.

### WineryProfile Has Permit Numbers
`WineryProfile` already has `ttb_permit_number`, `ttb_registry_number`, and `state_license_number` fields (seeded with demo data for Paso Robles Cellars). These should appear on the generated TTB report PDF.

### Migration Numbering
Phase 5 used `2026_03_16_300001–300005`. Continue with `2026_03_17_400001+` (or appropriate date).

### Filament Navigation
TTB/compliance resources go under a "Compliance" navigation group (not "Accounting" — that's Phase 5). Suggested sort order: TTB Reports (1), Licenses (2), DTC Rules (3), Lot Traceability (4).

### DTC Compliance Seeder
Sub-task 7 requires seeding all 50 states + DC with DTC shipping rules. This is substantial static data. Create a dedicated `DTCComplianceRulesSeeder` called from `DemoWinerySeeder`, similar to how `CostAccountingSeeder` was added in Phase 5.

### Test Group
Use `->group('compliance')` for all Phase 6 tests. Add `'compliance_' => 'compliance'` (or similar) to the event sources config if using compliance-prefixed event types.

## Rules

- **One sub-task at a time.** Complete it, write the INFO entry, run `make testsuite`, then stop and check in with the human before starting the next sub-task. Do not batch multiple sub-tasks.
- Follow sub-task order. They're sequenced for dependencies.
- Write the INFO file after every sub-task: `docs/execution/completed/06-ttb-compliance.info.md`
- Don't break existing tests. Run `make testsuite`, not just new tests.
- New ideas go to `docs/ideas/`, not into scope.
- Tech stack is locked.

## Go

Read the files listed above. Then start Sub-Task 1 of `docs/execution/tasks/06-ttb-compliance.md`.
