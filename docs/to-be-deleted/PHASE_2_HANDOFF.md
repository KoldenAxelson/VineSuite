# VineSuite — Phase 2 Handoff Prompt

> Copy everything below the line into your first AI session to begin work on Phase 2: Production Module + Portal.
> Phase 1: Foundation is complete. All 15 sub-tasks delivered and verified.

---

## Who You Are

You are continuing development on VineSuite, a winery SaaS platform. Phase 1 (Foundation) is complete — the platform skeleton is built, tested, and passing CI. Your job is to execute Phase 2: Production Module + Portal, which covers the core winemaking operations.

You do not need to plan. The planning is done. You need to build, test, and record.

## Before You Write Any Code

Read these files in this order. Do not skip any of them.

1. `docs/WORKFLOW.md` — The development lifecycle: LOAD → BUILD → TEST → VERIFY → RECORD → UPDATE. This is your operating manual. Follow it exactly. Note the **Ideas Triage** phase gate — this has already been completed for Phase 2 (see step 2).

2. `docs/ideas/TRIAGE.md` — The Phase 2 triage is already done. Three ideas were absorbed: `pricing-and-plan-tiers.md` (PlanFeatureService is a deliverable), `progressive-onboarding.md` (design constraint on Filament UI), `gradual-migration-path.md` (design constraint on API endpoints). Read the absorbed idea docs before building to understand the constraints.

3. `docs/execution/phase-recaps/phase-1-foundation.md` — Compressed context for everything built in Phase 1. Read this instead of the full INFO file. Covers architecture decisions, patterns established, known debt, and **post-Phase 1 amendments** (plan tier rename, free tier, downgrade helpers).

4. `docs/execution/tasks/02-production-core.md` — Task spec for Production Core. 14 sub-tasks: lots, vessels, barrels, work orders, additions, transfers, pressing, filtering, blending, bottling. Work through them top-to-bottom.

5. `docs/execution/tasks/03-lab-fermentation.md` — Task spec for Lab & Fermentation. 7 sub-tasks. Depends on Production Core (lots, vessels).

6. `docs/execution/tasks/04-inventory.md` — Task spec for Inventory. 11 sub-tasks. Depends on Production Core (bottling creates case goods).

7. `docs/execution/tasks/05-cost-accounting.md` — Task spec for Cost Accounting. 8 sub-tasks. Depends on Inventory and Production Core.

8. `docs/guides/testing-and-logging.md` — Testing tiers and logging standards. Every sub-task must follow these.

**Load when relevant (not upfront):**
- `docs/references/event-log.md` — How EventLogger works. Load for any sub-task that writes events.
- `docs/references/multi-tenancy.md` — Tenant lifecycle and testing patterns.
- `docs/references/auth-rbac.md` — Auth, roles, rate limiting. Includes the Token Name Contract.
- `docs/architecture.md` — Full architecture doc. Section 3 (Event Log) and Section 5+ (Production) are most relevant.
- `docs/ideas/pricing-and-plan-tiers.md` — Tier structure, volume limits, feature gating architecture. Load when building PlanFeatureService or any tier-gated resources.
- `docs/ideas/progressive-onboarding.md` — Load when building Filament navigation or resource visibility.

## What Already Exists

Phase 1 delivered:

- **Docker environment** — `docker compose up -d` starts all services (app, postgres, postgres-test, redis, meilisearch, mailpit, horizon)
- **Laravel 12 API** at `api/` — PHP 8.4, PostgreSQL 16, Redis, configured and running
- **Multi-tenancy** — `stancl/tenancy` v3.9, schema-per-tenant, `Tenant::create()` provisions in <10s
- **Authentication** — Sanctum tokens scoped per client type, 7 roles with ~55 permissions via `spatie/laravel-permission`
- **Event Log** — `EventLogger::log()` service, immutable `events` table with PostgreSQL trigger, batch sync endpoint at `POST /api/v1/events/sync`
- **Activity Logging** — `LogsActivity` trait on User, WineryProfile, TeamInvitation. Immutable `activity_logs` table
- **Team Invitations** — Full invite/accept/cancel flow
- **Winery Profile** — Auto-created on tenant provisioning, editable by owner/admin
- **Stripe Billing** — Cashier on Tenant model, Free/Basic/Pro/Max tiers, checkout/portal/plan-change endpoints, webhook handler. Plan hierarchy helpers: `planRank()`, `isDowngradeTo()`, `hasPlanAtLeast()`. Free tier is the default (no subscription required).
- **Filament Portal** — `/portal` with 7 navigation groups (Production, Inventory, Compliance, Sales, Club, CRM, Settings), UserResource and ActivityLogResource
- **API Envelope** — All responses: `{ "data": ..., "meta": {}, "errors": [] }`. ValidationException → 422 with field details. ForceJsonResponse middleware.
- **Rate Limiting** — Per token type: portal 120/min, mobile 60/min, widget 30/min. `X-RateLimit-Limit` and `X-RateLimit-Remaining` headers.
- **CI/CD** — GitHub Actions: Pint + PHPStan (level 6) + Pest. Deploy via manual workflow_dispatch.
- **Demo Winery** — "Paso Robles Cellars" on `pro` plan with 7 users (one per role), realistic profile data
- **Ideas Backlog** — 7 strategic docs in `docs/ideas/` covering pricing, onboarding, data portability, resilience, migration paths, support, and grape marketplace. Phase 2 triage completed in `docs/ideas/TRIAGE.md`.

### Test Suite Status
```
Tests:    141 passed (~500+ assertions)
PHPStan:  0 errors (level 6)
Pint:     0 style issues
```

### Key Patterns to Follow

1. **All winery operations must write events** via `app(EventLogger::class)->log()`. Never bypass this.
2. **All API responses use `ApiResponse::*`** — `success()`, `created()`, `message()`, `error()`. No raw `response()->json()`.
3. **Tenancy tests use `DatabaseMigrations`**, not `RefreshDatabase`. Clean up schemas in `afterEach`.
4. **Token creation uses `client_type|device_name`** format for rate limiting.
5. **Add `use LogsActivity;`** to any new tenant model that should be audited.
6. **Immutable tables use PostgreSQL triggers** — standard for append-only data.
7. **Structured logging** — `Log::info('message', ['tenant_id' => ..., 'user_id' => ...])`.
8. **Filament resources** go under the appropriate navigation group (Production, Inventory, etc.).

## Your First Sub-Task

Start with **Sub-Task 1** from `docs/execution/tasks/02-production-core.md`.

Before building, tell the human:
- What you're about to do (one sentence)
- What files you'll create or modify
- Whether there are any questions or decisions needing human input

Then build. Then test. Then ask for verification. Then write the INFO entry to `docs/execution/completed/02-production-core.info.md`. Then move to the next sub-task.

## Human Steps Required

**Sub-Task 1+ (Production migrations):** Human runs `docker compose exec app php artisan migrate` if schema changes are needed in the test container.

**All sub-tasks:** Human runs `docker compose exec app vendor/bin/pest` to verify tests pass. Human also runs Pint and PHPStan before committing.

For all other work, you can build and test autonomously.

## Critical Rules

1. **Follow the sub-task order.** They're sequenced for dependencies.
2. **Write the INFO file after every sub-task.** Append to `docs/execution/completed/02-production-core.info.md`.
3. **Update reference docs** when you establish new patterns (e.g., new event types, new Filament resource patterns).
4. **Test per the tier system.** Phase 2 is heavily Tier 1 (data integrity, event log writes, TTB-relevant operations).
5. **Log structured, not interpolated.** Include `tenant_id` in every tenant-scoped log.
6. **The tech stack is locked.** Don't substitute anything.
7. **Events are the source of truth.** Every winery operation (addition, transfer, blend, etc.) writes an event. Materialized CRUD tables are derived from events.
8. **Don't break existing tests.** Run the full suite, not just new tests.
9. **Plan tiers are `free|basic|pro|max`.** Use `$tenant->hasPlanAtLeast('pro')` for feature gating. Never hardcode plan names in conditionals — use the Tenant model helpers.
10. **New ideas go to `docs/ideas/`, not into scope.** Mid-phase ideas are captured but not acted on until the next triage checkpoint (see `docs/ideas/TRIAGE.md`).

## Phase 2 Scope

Phase 2 covers 4 task files with ~40 sub-tasks total:

| File | Sub-Tasks | Description |
|------|-----------|-------------|
| `02-production-core.md` | 14 | Lots, vessels, barrels, work orders, additions, transfers, pressing, filtering, blending, bottling |
| `03-lab-fermentation.md` | 7 | Lab analysis, threshold alerts, fermentation tracking, curves |
| `04-inventory.md` | 11 | Case goods SKUs, multi-location stock, dry goods, raw materials, physical counts |
| `05-cost-accounting.md` | 8 | Per-lot cost ledger, labor, overhead, blend/split rollthrough, COGS |

Work through each file's sub-tasks top-to-bottom. Complete all sub-tasks in `02-production-core.md` before starting `03-lab-fermentation.md`, and so on.

## Go

Read the files listed above. Then begin Sub-Task 1 of `02-production-core.md`.
