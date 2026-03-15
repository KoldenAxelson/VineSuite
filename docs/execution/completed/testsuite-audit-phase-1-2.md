# Testsuite Audit — Phase 1 & Phase 2

> Audited: 2026-03-15
> Remediated: 2026-03-15
> Scope: All 26 test files, 352 tests → 354 tests after fixes, 1,466 assertions
> Standard: `docs/guides/testing-and-logging.md` tier system

---

## Verdict

The test suite is **solid for shipping** but has **systematic gaps** that should be addressed before Phase 3 adds complexity. The production tests are notably stronger than the foundation tests — domain logic (volume math, TTB composition, event emission) is well covered. The gaps are mostly in RBAC edge cases, pagination validation, and a handful of tests that don't actually test what their names claim.

---

## Tier 1 Audit (Must Have — "blocks the PR if missing")

### Event Log Writes — PASS (Strong)
Every production operation test verifies event emission with correct `entity_type`, `operation_type`, and payload structure. EventLoggerTest (13 tests) is the strongest file in the suite — idempotency, immutability triggers, tenant isolation, and timestamp handling all verified.

**One gap:** No test verifies that events are written when operations are performed through Filament resources (only API endpoints are tested). This matters because Filament resources call the same services, but if someone wires them differently in the future, the event log could silently break.

### Financial Calculations — N/A
No financial features built yet (Phase 5: Cost Accounting). Not a gap.

### Inventory Math — PASS (Strong)
Volume calculations are tested thoroughly: TransferTest verifies source/target volume updates with variance, BottlingRunTest verifies lot volume deduction on completion, BlendTrialTest verifies source lot volume deduction on finalization, LotSplitTest verifies parent volume deduction. PressLogTest validates yield percentage calculation.

### Authentication & Authorization — PASS (with gaps)
Login/logout/token flow tested. Role gates tested across all 12 production test files. Tenant isolation tested in every file via direct model queries.

**Gaps identified:**

| Gap | Severity | Details |
|-----|----------|---------|
| RBAC owner permission test is count-only | Medium | RbacTest "owner has all permissions" checks permission *count* equals total, not that specific critical permissions are assigned. If a permission is accidentally renamed, this test still passes. |
| Token abilities not enforced at endpoint level | Medium | RbacTest tests `$token->can('ability')` in isolation but never verifies that an endpoint actually rejects a request when the token lacks the right ability. |
| Deactivated user test is weak | Low | AuthenticationTest checks 422 status but doesn't verify the rejection reason is deactivation specifically (vs. any validation error). |
| No test for password reset flow | Low | Controllers exist per Phase 1 INFO but no test coverage. |

### Sync Engine — PASS
EventSyncTest (12 tests) covers batch acceptance, idempotency, timestamp validation, user linking, mixed batches. Solid.

**One gap:** No test for partial batch failure (some events valid, some invalid in the same batch).

---

## Tier 2 Audit (Should Have — "expected in PR")

### API Endpoint Contracts — PASS (with systematic gap)
All endpoints tested for correct HTTP status codes and response structure. ApiResponseEnvelopeTest validates the envelope format for success, error, validation, 401, 403, and 404.

**Systematic gap: Pagination logic never validated.** Every list endpoint test creates N records, asserts count = N, and checks `meta` structure exists — but never tests actual page boundaries (`?page=2`), `per_page` parameter, or that `meta.total` / `meta.current_page` values are correct. This is 12+ tests across all production files that claim to test "pagination" but only test "listing."

### Service Layer Business Logic — PASS (Strong)
BlendTrialTest validates TTB 75% threshold for label variety. TransferTest validates "can't transfer more than available." BottlingRunTest validates "can't bottle more than lot volume." LotSplitTest validates "children can't exceed parent volume." AdditionTest validates SO2 running total accumulation. These are the tests a winemaker would care about.

### Filament Resource CRUD — FAIL (Not tested)
The testing guide explicitly lists this as Tier 2: "Filament resource CRUD — create, read, update, soft-delete for each admin resource (use Livewire test helpers)." PortalTest only checks configuration (panel name, navigation groups, access control) — no actual Livewire rendering or CRUD operation tests. This is the largest gap in the suite.

### Notification Dispatch — PARTIAL
TeamInvitationTest verifies `TeamInvitationMail` is dispatched. No other notification tests exist, but no other notifications are built yet.

---

## Tier 3 Assessment (Nice to Have)

Appropriately skipped per the guide. No tests for simple accessors, factory definitions, or migration structure. The one exception: VesselTest *does* test computed `fill_percent` accessor (75% from 1500/2000 gal), which is correct since it contains math — per the guide, "if a simple accessor has a conditional, that's Tier 2."

---

## Tests That Don't Test What They Claim

These are the most important findings — tests that give false confidence.

| File | Test Name | Problem |
|------|-----------|---------|
| ActivityLogTest | "does not break the application if activity logging fails" | Creates a user normally — never actually triggers a logging failure. Tests resilience without testing failure. |
| AuthenticationTest | "rejects login for deactivated users" | Only asserts HTTP 422 — doesn't verify the error message is about deactivation. Any validation error would pass this test. |
| WorkOrderTest | "read-only users cannot create or complete work orders" | Only tests create denial (403). Never tests that complete is also denied. |
| LotTest | "allows cellar hands to view but not create lots" | Only tests create denial (403). Never verifies view access works. |
| ApiResponseEnvelopeTest | "does not apply envelope to non-API routes" | Has conditional logic: checks Content-Type before asserting structure. If the route returns HTML, the test silently passes without actually verifying the non-envelope behavior. |
| BillingTest | "tenant model has Billable trait" | Only checks `method_exists()` — verifies the trait is applied but not that any billing behavior works. |
| BillingTest | "tenant has plan helper methods" | Same issue — method existence check, not functionality. |
| BillingTest | "webhook endpoint exists and is reachable" | Only checks `status !== 404`. Verifies route exists but zero webhook functionality tested. |

---

## Missing Tests (Prioritized)

### Should Add Before Phase 3

1. **Filament resource CRUD tests** — At minimum: create a lot, edit a lot, list lots, complete a work order, finalize a blend trial through Filament's Livewire test API. This is Tier 2 per the guide and currently zero coverage.

2. **Pagination boundary tests** — Pick one endpoint (e.g., lots), test `?page=2&per_page=5` returns correct slice, meta values, and page count. One test covers the pattern for all endpoints.

3. **Partial batch sync failure** — Post a batch where event 1 is valid and event 2 fails validation. Verify event 1 is still accepted and event 2 reported as failed.

4. **Token ability enforcement at endpoint level** — Create a `cellar_app` token with limited abilities, hit an endpoint that requires an ability the token doesn't have, verify 403.

5. **Fix the 8 misleading tests** listed above — either rename them to match what they actually test, or add the missing assertions.

### Can Wait

6. **Password reset flow** — Low impact, no winery depends on this yet.
7. **Concurrent request handling** — Important for production but not blocking Phase 3 development.
8. **Large batch stress test** — EventSync has a 100-event max but it's validated in code, not tested.
9. **Rate limit reset behavior** — Headers are tested but reset timing isn't.

---

## What's Working Well

The suite does a lot right. Specifically:

**Domain logic is genuinely tested, not just CRUD.** The BlendTrialTest TTB 75% threshold test, the TransferTest volume-with-variance math, the BottlingRunTest auto-status-change-on-zero-volume — these are the tests that would catch a real winery-affecting bug. Most test suites at this stage would just test "can create, can list, can delete." This one tests "can't transfer 300 gallons from a vessel holding 200" and "finalizing a blend deducts the correct volume from each source lot." That's the right instinct.

**Event emission is tested everywhere.** Every production operation test verifies the event was written with the correct payload. This is critical for TTB compliance (Phase 6) and means the audit trail is guaranteed to be intact.

**Tenant isolation is tested at the data level.** Every production file has a cross-tenant test that uses direct model queries to verify schema isolation — not just "returns 404" but "the record literally doesn't exist in this tenant's schema."

**Immutability is tested at the database level.** EventLoggerTest and ActivityLogTest both verify that PostgreSQL triggers block UPDATE and DELETE. This is tested against real PostgreSQL, not mocked — exactly right.

---

## Remediation Applied (2026-03-15)

### Fixed: 8 Misleading Tests
All 8 tests identified above were strengthened with real assertions:

1. **ActivityLogTest** — "does not break the application if activity logging fails": Now renames the `activity_logs` table to force a real failure, verifies user creation still succeeds, and confirms no activity log was written.
2. **AuthenticationTest** — "rejects login for deactivated users with deactivation message": Now asserts error message contains "deactivated", not just 422 status.
3. **WorkOrderTest** — "read-only users cannot create or complete work orders": Now tests BOTH create denial (403) AND complete denial (403) for read_only users.
4. **LotTest** — "allows cellar hands to view but not create lots": Now verifies cellar hands CAN list and view a specific lot (200 OK) before testing create denial (403).
5. **ApiResponseEnvelopeTest** — "does not apply envelope to non-API routes": Conditional logic replaced with explicit Content-Type assertion for both JSON and non-JSON paths.
6. **BillingTest** — "tenant model has Billable trait with working Stripe methods": Replaced `method_exists()` with actual method calls (`hasStripeId()`, `subscribed()`, `subscription()`).
7. **BillingTest** — "tenant plan helper methods return correct values for basic plan": Replaced `method_exists()` with return-value assertions for all plan helpers, plus free plan `hasActiveAccess()` coverage.
8. **BillingTest** — "webhook endpoint is registered and reachable": Now asserts exact 200 status (route exists and accepts requests; in test env without `STRIPE_WEBHOOK_SECRET`, Cashier doesn't enforce signatures).
9. **RbacTest** — "owner has all permissions including critical ones": Added explicit checks for 5 critical permissions by name (`users.create`, `settings.update`, `billing.read`, `lots.create`, `work-orders.create`).

### Added: Pagination Boundary Test
- **LotTest** — "paginates lots correctly across pages": Creates 7 lots, requests `?per_page=5&page=1` (5 results), then `?page=2` (2 results). Verifies `meta.total`, `meta.last_page`, `meta.current_page`, and that page IDs don't overlap.

### Added: Partial Batch Sync Failure Test
- **EventSyncTest** — "handles partial batch failure gracefully via EventProcessor": Sends 3 events to the EventProcessor service directly — 2 valid, 1 with null entity_id. Verifies `accepted=2`, `failed=1`, `skipped=0`, and that only 2 events exist in the DB.

### Deferred to Phase 3
- **Token ability endpoint enforcement**: No `CheckAbilities`/`CheckForAnyAbility` middleware exists yet. Token abilities are assigned at login but not enforced at the route level. This is an implementation gap, not a test gap — adding the middleware and tests together is the right approach.
- **Filament resource CRUD tests**: Requires a subdomain test harness with `InitializeTenancyByDomain` active for Livewire rendering. This is a significant test infrastructure investment that should be planned as a Phase 3 sub-task, not a quick audit fix.

## Recommendation

The 8 misleading tests are fixed, pagination is tested, and partial batch failure handling is verified. The remaining Phase 3 items (token ability enforcement middleware + tests, Filament Livewire CRUD tests) require implementation changes and test infrastructure, respectively. The suite is ready for Phase 3.
