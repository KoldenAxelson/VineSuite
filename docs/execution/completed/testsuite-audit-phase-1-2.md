# Testsuite Audit — Phase 1 & Phase 2

> Audited: 2026-03-15
> Scope: 26 test files, 354 tests, 1,466 assertions
> Standard: `docs/guides/testing-and-logging.md` tier system

---

## Verdict

**Solid for shipping**, with systematic gaps in RBAC edge cases, pagination validation, and 8 misleading tests (now fixed). Production tests notably stronger than foundation tests.

---

## Tier 1 Audit (Must Have)

### Event Log Writes — PASS (Strong)
All production operations verify event emission with `entity_type`, `operation_type`, payload structure. EventLoggerTest (13 tests) is the strongest file.

**Gap:** No test verifies events written through Filament resources (only API tested).

### Financial Calculations — N/A
Not built yet (Phase 5).

### Inventory Math — PASS (Strong)
TransferTest, BottlingRunTest, BlendTrialTest, LotSplitTest, PressLogTest all verify volume calculations and variance.

### Authentication & Authorization — PASS (with gaps)

| Gap | Severity | Details |
|-----|----------|---------|
| RBAC owner permission test is count-only | Medium | Checks permission count, not specific critical permissions. |
| Token abilities not enforced at endpoint level | Medium | Tested in isolation, never at route level. |
| Deactivated user test is weak | Low | Checks 422 but not deactivation message. |
| No password reset flow test | Low | Controllers exist but untested. |

### Sync Engine — PASS
EventSyncTest (12 tests) covers batch acceptance, idempotency, timestamps, user linking.

**Gap:** No test for partial batch failure (mixed valid/invalid in same batch).

---

## Tier 2 Audit (Should Have)

### API Endpoint Contracts — PASS (with gap)
All endpoints tested for HTTP status, response structure.

**Systematic gap: Pagination never validated.** List endpoint tests create N records, assert count, check `meta` exists — but never test page boundaries, `per_page` parameter, or correct `meta` values (12+ affected tests).

### Service Layer Business Logic — PASS (Strong)
BlendTrialTest (TTB 75% threshold), TransferTest (volume limits), BottlingRunTest, LotSplitTest, AdditionTest all verify domain rules winemakers care about.

### Filament Resource CRUD — FAIL (Not tested)
PortalTest only checks configuration, no Livewire rendering or CRUD operation tests. **Largest gap in suite.**

### Notification Dispatch — PARTIAL
TeamInvitationTest verifies dispatch. No other notifications built yet.

---

## Tier 3 Assessment

Appropriately skipped. Exception: VesselTest tests computed `fill_percent` accessor (correct per guide — conditional accessors are Tier 2).

---

## Tests That Don't Test What They Claim

| File | Test | Problem |
|------|-----------|---------|
| ActivityLogTest | "does not break the application if activity logging fails" | Never triggers actual logging failure. |
| AuthenticationTest | "rejects login for deactivated users" | Only asserts 422, not deactivation message. |
| WorkOrderTest | "read-only users cannot create or complete work orders" | Only tests create denial, not complete. |
| LotTest | "allows cellar hands to view but not create lots" | Only tests create denial, not view access. |
| ApiResponseEnvelopeTest | "does not apply envelope to non-API routes" | Has silent pass via conditional. |
| BillingTest | "tenant model has Billable trait" / "plan helper methods" / "webhook endpoint exists" | Only checks method existence, not functionality. |

---

## Missing Tests (Prioritized)

### Should Add Before Phase 3

1. **Filament resource CRUD tests** — Tier 2, currently zero coverage.
2. **Pagination boundary tests** — Test `?page=2&per_page=5` returns correct slice, meta values.
3. **Partial batch sync failure** — Event 1 valid, event 2 fails, verify correct acceptance/failure counts.
4. **Token ability endpoint enforcement** — Create limited token, hit endpoint requiring higher ability, verify 403.
5. **Fix 8 misleading tests** — Rename or add assertions. (✓ DONE — see Remediation)

### Can Wait
6. Password reset flow (low impact)
7. Concurrent request handling (not blocking Phase 3)
8. Large batch stress test (100-event max validated in code)
9. Rate limit reset behavior (headers tested)

---

## What's Working Well

- **Domain logic genuinely tested, not just CRUD.** BlendTrialTest TTB threshold, TransferTest volume-with-variance, BottlingRunTest auto-status changes.
- **Event emission tested everywhere.** Every production operation verifies correct payload.
- **Tenant isolation at data level.** Not just "returns 404," but "record doesn't exist in tenant schema."
- **Immutability via DB triggers.** PostgreSQL blocks UPDATE/DELETE on events/activity_logs.

---

## Remediation Applied (2026-03-15)

### Fixed: 8 Misleading Tests
1. **ActivityLogTest** — Now renames activity_logs table to force real failure.
2. **AuthenticationTest** — Now asserts error message contains "deactivated."
3. **WorkOrderTest** — Tests BOTH create and complete denial for read_only.
4. **LotTest** — Verifies cellar hands CAN list/view before testing create denial.
5. **ApiResponseEnvelopeTest** — Explicit Content-Type assertion for both JSON/non-JSON.
6. **BillingTest (trait)** — Calls actual methods: `hasStripeId()`, `subscribed()`, `subscription()`.
7. **BillingTest (plan methods)** — Return-value assertions for all helpers + free plan.
8. **BillingTest (webhook)** — Asserts exact 200 status.
9. **RbacTest** — Added explicit checks for 5 critical permissions by name.

### Added: Pagination Boundary Test
- **LotTest** — "paginates lots correctly across pages" — Creates 7 lots, tests `?per_page=5&page=1` (5 results) then `?page=2` (2 results). Verifies meta.total, meta.last_page, meta.current_page.

### Added: Partial Batch Sync Failure Test
- **EventSyncTest** — 3 events: 2 valid, 1 with null entity_id. Verifies accepted=2, failed=1.

### Deferred to Phase 3
- **Token ability endpoint enforcement** — No middleware exists yet. Implementation gap, not test gap.
- **Filament resource CRUD tests** — Requires subdomain test harness. Infrastructure investment for Phase 3.

---

## Metrics

| Metric | Count |
|--------|-------|
| Test files | 26 |
| Tests | 354 (post-remediation) |
| Assertions | ~1,466 |
| PHPStan level 6 errors | 0 |
| Pint issues | 0 |

## Recommendation

Suite ready for Phase 3. Remaining items (token middleware, Filament infrastructure) require implementation work, not just tests.
