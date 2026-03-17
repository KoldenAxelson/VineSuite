# Test Groups

> Module-level grouping for 870+ tests (~600s full run). Enables fast local iteration and parallel CI.

---

## How It Works

Pest supports `group()` method. One group per module. Every top-level `describe()` block has `->group('...')`:

```php
describe('Lab Analysis', function () {
    // ...
})->group('lab');
```

---

## Groups

| Group | Module | Files | Count | Phases |
|---|---|---|---|---|
| `foundation` | Auth, tenant, RBAC, event log, sync (push + pull) | ~11 | ~151+ | 1 |
| `production` | Lots, vessels, transfers, additions, work orders, blending, bottling | ~15 | ~213 | 2 |
| `lab` | Lab analyses, fermentation, sensory, demo data | ~8 | ~124 | 3 |
| `inventory` | SKUs, stock movements, dry goods, equipment, POs, counts, bulk wine | ~11 | ~200+ | 4 |
| `accounting` | Cost entries, labor costs, overhead allocation, cost rollthrough, bottling COGS | 5 | ~30+ | 5 |
| `compliance` | TTB report generation, wine type classification, event source mapping, DTC, certifications, label compliance, lot traceability | ~5 | ~62+ | 6 |

---

## Makefile Usage

```bash
make test G=lab                    # Run lab group
make test G=production             # Run production group
make test G="lab production"       # Run multiple groups
make testsuite                     # Run all tests
make test G=lab F=LabAnalysis      # Group + filter
```

---

## Local Development

At 870+ tests, full suite takes ~600s. Use groups to iterate faster:

```bash
# Make a change to fermentation code
make test G=lab        # 30s, 124 tests
# It works, push it
# CI runs full matrix in parallel
```

---

## CI Strategy (Future)

**Fast gate (blocks merge):** Pint + PHPStan + `--group=foundation` (~60s)

**Full matrix (required, parallel):** One job per group, all concurrent. PR green only when all pass.

---

## Rules

1. Every test file belongs to exactly one group
2. Group names = build phases (foundation, production, lab, inventory, accounting)
3. Add groups when touching test files
4. New test files always include group from start
5. Full `make testsuite` always runs all groups — groups are acceleration tools only
6. CI matrix must run all groups — no group is optional

---

*Introduced Phase 3 retrospective. Applied organically starting Phase 4.*
