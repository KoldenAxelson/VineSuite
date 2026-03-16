# Test Groups

> Last updated: 2026-03-15
> Relevant source: `Makefile`, `api/tests/`
> Introduced: Phase 3 retrospective (preparing for Phase 4+ scale)

---

## Why Test Groups

At 478 tests and ~250 seconds for a full run, the test suite is approaching the point where running everything on every local iteration slows development. Test groups let developers run subsets of related tests without skipping coverage.

Groups are also the foundation for parallel CI: each group becomes a matrix job, cutting wall-clock time roughly in proportion to the number of groups.

---

## How Groups Work

Pest supports the `group()` method on test files. We assign one group per module (not per file). A test file belongs to exactly one group.

```php
// tests/Feature/Lab/LabAnalysisTest.php
describe('Lab Analysis', function () {
    // ...
})->group('lab');
```

Every `describe()` block at the top level of a test file should have a `->group('...')` call.

---

## Group Registry

| Group | Module | Test Files | Approximate Count |
|---|---|---|---|
| `foundation` | Foundation (Phase 1) | Auth, tenant, RBAC, event log core | ~141 |
| `production` | Production Core (Phase 2) | Lots, vessels, transfers, additions, work orders, blending, bottling | ~213 |
| `lab` | Lab & Fermentation (Phase 3) | Lab analyses, fermentation, sensory, thresholds, demo data | ~124 |
| `inventory` | Inventory (Phase 4) | SKUs, stock levels/movements/transfers, dry goods, raw materials, equipment, POs, physical counts, bulk wine, seeder | ~200+ |
| `accounting` | Cost Accounting (Phase 5) | TBD | — |

**Convention:** Group names are lowercase, singular, one-word. They map 1:1 to build phases.

---

## Makefile Usage

### Run a single group locally

```bash
make test G=lab           # Run only the 'lab' group
make test G=production    # Run only the 'production' group
```

### Run multiple groups

```bash
make test G="lab production"   # Run lab + production groups
```

### Full suite (unchanged)

```bash
make testsuite            # Runs ALL tests regardless of group
```

### Filtered within a group

```bash
make test G=lab F=LabAnalysis  # Group 'lab', filter 'LabAnalysis'
```

---

## CI Strategy (Future)

The goal is a two-tier CI pipeline:

**Fast gate (blocks merge):**
- Pint (code style)
- PHPStan (static analysis)
- Pest with `--group=foundation` (core infrastructure tests only)

**Full matrix (required, runs in parallel):**
- One job per group, all run concurrently
- Each job reports independently
- PR is green only when all matrix jobs pass

This means a fast gate completes in ~60 seconds (Pint + PHPStan + foundation tests), giving developers rapid feedback. The full matrix runs concurrently and finishes in roughly the time of the slowest group, not the sum of all groups.

**We don't need to build this now.** The Makefile `G=` support and group annotations are the groundwork. Wiring up the GitHub Actions matrix is trivial once groups are in place.

---

## Adding Groups to Existing Tests

When touching a test file for any reason, add the group annotation if it's missing. Don't do a bulk migration — let it happen organically as files are modified.

For new test files, always include the group from the start:

```php
describe('Inventory Stock Movements', function () {
    // ...
})->group('inventory');
```

---

## Rules

1. Every test file belongs to exactly one group
2. Group names match build phases (foundation, production, lab, inventory, accounting)
3. Cross-cutting tests (e.g., a test that verifies tenant isolation for lab data) go in the module group, not foundation
4. The full `make testsuite` always runs all groups — groups are an acceleration tool, not a way to skip tests
5. CI matrix must run all groups — no group is optional in CI

---

## History
- 2026-03-15: Created during Phase 3 retrospective. Makefile `G=` support added. Group annotations to be applied organically starting Phase 4.
- 2026-03-15: Phase 4 complete. All 11 inventory test files annotated with `->group('inventory')`. ~200+ tests in inventory group.
