# VineSuite — Phase 3 Handoff Prompt

> Copy everything below the line into your first AI session to begin work on Phase 3: Lab & Fermentation.
> Phase 1: Foundation is complete (15/15 sub-tasks). Phase 2: Production Core is complete (14/14 sub-tasks). Test suite audited and remediated.

---

## Who You Are

You are continuing development on VineSuite, a winery SaaS platform. Phases 1 and 2 are complete — the foundation and core production module are built, tested, and passing CI. Your job is to execute Phase 3: Lab Analysis & Fermentation Tracking, which adds science-side features to the cellar management system.

You do not need to plan. The planning is done. You need to build, test, and record.

## Before You Write Any Code

Read these files in this order. Do not skip any of them.

1. `docs/WORKFLOW.md` — The development lifecycle: LOAD → BUILD → TEST → VERIFY → RECORD → UPDATE. This is your operating manual. Follow it exactly.

2. `docs/execution/phase-recaps/phase-2-production-core.md` — Compressed context for everything built in Phase 2. Read this instead of the full INFO file. Covers architecture decisions, patterns established, known debt, and critical Filament+tenancy fixes.

3. `docs/execution/phase-recaps/phase-1-foundation.md` — Phase 1 context. Skim for architecture decisions and patterns — most are already applied in Phase 2 code, but this is useful if you need to understand why something was done a certain way.

4. `docs/execution/tasks/03-lab-fermentation.md` — **Your task spec.** 7 sub-tasks: lab analysis model, threshold alerts, CSV import, fermentation rounds, fermentation curves, sensory notes, demo data. Work through them top-to-bottom.

5. `docs/guides/testing-and-logging.md` — Testing tiers and logging standards. Note the **PHP / Laravel Testing Gotchas** section at the bottom — it covers `forgetGuards()` for multi-user tests, `DatabaseMigrations` vs `RefreshDatabase`, and UUID pivot attach patterns.

6. `docs/execution/completed/testsuite-audit-phase-1-2.md` — Audit results and remediation. Shows what the test suite covers, what it doesn't, and two items deferred to Phase 3 (token ability endpoint enforcement middleware, Filament Livewire CRUD tests). You may want to address these.

**Load when relevant (not upfront):**
- `docs/references/event-log.md` — How EventLogger works. Load for any sub-task that writes events (all of them in this phase).
- `docs/references/multi-tenancy.md` — Tenant lifecycle, domain records, Filament integration gotchas.
- `docs/references/auth-rbac.md` — Auth, roles, rate limiting. Includes the Token Name Contract.
- `docs/guides/filament-tenancy.md` — The 3 critical fixes for Filament + stancl/tenancy. Load before creating any Filament resources.
- `docs/architecture.md` — Full architecture doc. Section 3 (Event Log) and Sections 5+ (Production) are most relevant.

## What Already Exists

### Phase 1 Foundation
- **Docker environment** — `docker compose up -d` starts all services
- **Laravel 12 API** — PHP 8.4, PostgreSQL 16, Redis, stancl/tenancy v3.9 (schema-per-tenant)
- **Authentication** — Sanctum tokens scoped per client type, 7 roles with ~55 permissions
- **Event Log** — `EventLogger::log()`, immutable `events` table with PostgreSQL trigger
- **Activity Logging** — `LogsActivity` trait, immutable `activity_logs` table
- **Billing** — Stripe Cashier on Tenant model, Free/Basic/Pro/Max tiers
- **Filament Portal** — On tenant subdomains, 7 navigation groups
- **API Envelope** — `{ "data": ..., "meta": {}, "errors": [] }` on all responses

### Phase 2 Production Core
- **8 Eloquent models** — Lot, Vessel, Barrel, WorkOrder, Addition, Transfer, BottlingRun, BlendTrial (plus pivot/component models)
- **17+ REST endpoints** — Full CRUD + specialized operations (complete, bulk create, calendar)
- **8 Filament resources** — CRUD interfaces with custom actions, calendar view, event timeline
- **Demo winery** — 38 lots across 4 vintages, 67 vessels, 65+ additions, 18 transfers, 30 work orders, 2 blend trials, 4 bottling runs
- **ProductionSeeder** — Called from DemoWinerySeeder. Phase 3 demo data should extend this or add a new `LabFermentationSeeder` called after it.

### Test Suite Status
```
Tests:    354 passed (1,466 assertions)
PHPStan:  0 errors (level 6)
Pint:     0 style issues
```

## Key Patterns to Follow

1. **All winery operations must write events** via `app(EventLogger::class)->log()`. Never bypass this. Never `Event::create()` directly.
2. **All API responses use `ApiResponse::*`** — `success()`, `created()`, `message()`, `error()`. No raw `response()->json()`.
3. **Tenancy tests use `DatabaseMigrations`**, not `RefreshDatabase`. Clean up schemas in `afterEach`.
4. **Multi-user tests need `app('auth')->forgetGuards()`** after switching users mid-test. See `docs/guides/testing-and-logging.md` gotchas section.
5. **UUID pivot tables need manual `'id' => (string) Str::uuid()`** in all `attach()` calls.
6. **Token creation uses `client_type|device_name`** format for rate limiting.
7. **Add `use LogsActivity;`** to any new tenant model that should be audited.
8. **Immutable tables use PostgreSQL triggers** — standard for append-only data (events, activity_logs).
9. **Structured logging** — `Log::info('message', ['tenant_id' => ..., 'user_id' => ...])`.
10. **Filament resources** go under the appropriate navigation group. Use `discoverResources()` auto-discovery.
11. **Filament + tenancy requires 3 fixes** — `asset_helper_tenancy: false`, Livewire route with tenant middleware, tenancy middleware before session. See `docs/guides/filament-tenancy.md`.

## Phase 3 Specifics

### Models You'll Create
- **LabAnalysis** — belongs to Lot. Records pH, TA, VA, SO2, etc. per lot over time. Immutable (append-only like additions).
- **LabThreshold** — Configurable per test type + variety. Checked automatically on each new entry.
- **FermentationRound** — belongs to Lot. Tracks primary and ML fermentation as separate rounds.
- **FermentationEntry** — belongs to FermentationRound. Daily Brix/temp/SO2 readings.
- **SensoryNote** — belongs to Lot + User. Tasting notes with configurable rating scale.

### Events You'll Write
| Event | Entity | When |
|-------|--------|------|
| `lab_analysis_entered` | lot | Lab analysis recorded |
| `fermentation_data_entered` | fermentation_round | Daily entry logged |
| `fermentation_completed` | fermentation_round | Round marked complete |

### Compliance Awareness
- VA (volatile acidity) has legal limits: **0.12 g/100ml** for table wine, **0.14** for dessert. Threshold alerts for VA are Tier 1 tests — if they fail to fire, a winery could unknowingly ship non-compliant wine.
- Lab analysis data feeds into TTB reporting (Phase 6). Structure records so they're queryable by lot + date range for report generation.

### CSV Import Resilience
Sub-Task 3 involves importing from ETS Labs and other external labs. Their CSV formats change without notice. Build parsers that handle column reordering, extra headers, and empty rows. Always show a preview before committing imports.

### Fermentation Curve Chart
Sub-Task 5 is a custom Livewire component. Use a JS charting library compatible with Livewire/Alpine.js (Chart.js is already available in the Filament stack). Dual-axis: Brix on left Y, temperature on right Y, date on X.

## Deferred Items from Audit (Optional)

The Phase 1-2 testsuite audit identified two items that could be addressed in Phase 3:

1. **Token ability endpoint enforcement** — Currently token abilities (`cellar_app`, `pos_app`, etc.) are assigned at login but not enforced via middleware. Adding Sanctum's `CheckForAnyAbility` middleware to relevant route groups would close this gap. The test would verify that a `cellar_app` token can't hit a billing endpoint.

2. **Filament Livewire CRUD tests** — No existing resources have Livewire render tests. This requires test infrastructure (domain-based tenancy setup for Livewire). If you build this for Phase 3 resources, backfilling Phase 2 resources is straightforward.

These are not blocking and can be deferred further if Phase 3 sub-tasks are time-boxed.

## Your First Sub-Task

Start with **Sub-Task 1** from `docs/execution/tasks/03-lab-fermentation.md`: Lab analysis model and data entry.

Before building, tell the human:
- What you're about to do (one sentence)
- What files you'll create or modify
- Whether there are any questions or decisions needing human input

Then build. Then test. Then ask for verification. Then write the INFO entry to `docs/execution/completed/03-lab-fermentation.info.md`. Then move to the next sub-task.

## Human Steps Required

**All sub-tasks:** Human runs the testsuite to verify:
```bash
make testsuite    # Pest + Pint + PHPStan
make fresh        # If you need to verify demo data seeding
```

For all other work, you can build and test autonomously.

## Critical Rules

1. **Follow the sub-task order.** They're sequenced for dependencies.
2. **Write the INFO file after every sub-task.** Append to `docs/execution/completed/03-lab-fermentation.info.md`.
3. **Update reference docs** when you establish new patterns (e.g., new event types in `references/event-log.md`).
4. **Test per the tier system.** VA threshold compliance is Tier 1. CSV parsing is Tier 2. Sensory note CRUD is Tier 2.
5. **Log structured, not interpolated.** Include `tenant_id` in every tenant-scoped log.
6. **The tech stack is locked.** Don't substitute anything.
7. **Events are the source of truth.** Every lab/fermentation operation writes an event via EventLogger.
8. **Don't break existing tests.** Run the full suite (354 tests), not just new tests.
9. **Plan tiers are `free|basic|pro|max`.** Use `$tenant->hasPlanAtLeast('pro')` for feature gating.
10. **New ideas go to `docs/ideas/`, not into scope.**

## Go

Read the files listed above. Then begin Sub-Task 1 of `03-lab-fermentation.md`.
