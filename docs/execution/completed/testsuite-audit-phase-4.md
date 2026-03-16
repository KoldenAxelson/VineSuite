# Testsuite Audit — Phase 4 (Inventory Management)

> Audited: 2026-03-15
> Scope: 11 test files, 265 tests, ~680 total suite
> Standard: `docs/guides/testing-and-logging.md` tier system

---

## Verdict

**Comprehensive on critical inventory math and event logging.** Every stock-modifying operation verifies StockLevel mutation + StockMovement ledger entry. Physical count approval triggers reconciliation with correct signed quantities. All 16 event types logged. Tenant isolation complete. Main gaps: concurrency testing (no race condition tests), physical count edge cases (negative variances, zero-variance lines), deferred Filament/token middleware tests.

---

## Tier 1 Audit (Must Have)

### Event Log Writes — PASS (Excellent)

All 16 inventory event types tested with `operation_type`, `event_source: 'inventory'`, full payload verification:

| Operation Type | Tested In | Payload Fields |
|---|---|---|
| `stock_received` / `stock_sold` / `stock_adjusted` | StockMovementTest, PhysicalCountTest | sku_id, sku_name, location_id, location_name, quantity, movement_type |
| `stock_transferred` | StockTransferTest | sku_id, sku_name, from/to_location_id/name, quantity |
| `stock_count_started` / `stock_counted` / `stock_count_cancelled` | PhysicalCountTest | location_id, location_name, line_count / adjustments_made / lines_recorded |
| `purchase_order_created` / `updated` / `received` | PurchaseOrderTest | po_number, vendor_name, line_count, total_amount |
| `equipment_created` / `updated` | EquipmentTest | name, equipment_type, serial_number |
| `dry_goods_created` / `updated` | DryGoodsTest | name, item_type, unit_of_measure |
| `raw_material_created` / `updated` | RawMaterialTest | name, category, unit_of_measure |

**Event source partitioning:** Tests verify `event_source: 'inventory'` auto-resolved from `operation_type` prefix.

### Inventory Math — PASS (Strong)

Signed-quantity arithmetic verified:
- Receive 50: on_hand += 50, available += 50, movement.quantity = +50
- Sell 10: on_hand -= 10, available -= 10, movement.quantity = -10
- Adjust ±: on_hand and available updated with correct sign
- Transfer 20: source on_hand -= 20, destination += 20, two movements (transferred_out=-20, transferred_in=+20)
- Physical count approval: variance applied as stock_adjusted movement
- Committed quantity: available = on_hand - committed

**Self-consistency check:** Sum all movements for SKU+location = current on_hand (fundamental ledger invariant).

### Tenant Isolation — PASS (Complete)

All 11 files verify schema isolation: SKU, location, stock ops, transfers, dry goods, raw materials, equipment, POs, physical counts, bulk wine, seeder data.

### Authentication — PASS

All endpoints reject unauthenticated requests (401).

---

## Tier 2 Audit (Should Have)

### API Endpoint Contracts — PASS

All files verify standard envelope format. HTTP codes: 200, 201, 403, 404, 422. Pagination meta verified on list endpoints.

### RBAC — PASS

| Operation | Allowed | Denied |
|---|---|---|
| SKU CRUD | winemaker (201) | read_only (403) |
| Location CRUD | admin (201) | read_only (403) |
| Stock receive/sell/adjust/transfer | winemaker (201) | read_only (403) |
| Dry goods CRUD | winemaker (201) | read_only (403) |
| Raw material CRUD | winemaker (201) | read_only (403) |
| Equipment CRUD + maintenance | admin (201) | read_only (403) |
| PO CRUD + receive | admin (201) | read_only (403) |
| Physical count lifecycle | winemaker (201) | read_only (403) |
| View/list (all) | all roles (200) | unauthenticated (401) |

### Validation — PASS

Required fields, invalid enums (location_type, movement_type, maintenance_type, item_type, category, PO status), negative quantities rejected, non-existent foreign keys, duplicate UPC barcode, quantity exceeding available stock.

### Purchase Order Lifecycle — PASS (Complete)

Draft → submitted → partial → received. Tests verify: line items update on receipt, inventory increases, status transitions, cancellation + event logging.

### Physical Count Workflow — PASS (with gaps)

Lifecycle: startCount → recordCounts → approve (variance reconciliation). Tests verify: system quantity snapshot, variance computation, stock_adjusted movements for non-zero variance, cancel event logging.

**Gaps:** See Gaps section below.

### Meilisearch Integration — PASS (Conditional)

CaseGoodsSkuTest includes search endpoint tests. Scout fallback when Meilisearch unavailable. Search by name, varietal, UPC tested.

### Bulk Wine Inventory — PASS

Verifies aggregation view pulls lot volume data correctly: total gallons, vessel counts, lot status filtering, tenant scoping.

---

## Gaps Identified

### Should Address in Phase 5 (Medium)

| Gap | Severity | Details |
|---|---|---|
| `lockForUpdate()` race condition | Medium | No test verifies concurrent stock operations are serialized. Could cause phantom inventory. |
| Physical count: negative variance | Medium | Tests cover positive variances (counted > system), not negative (counted < system). Signed quantity handles it, but no dedicated test. |
| Physical count: zero-variance lines | Low | No test for counted_quantity = system_quantity (should generate no stock_adjusted movement). |
| Movement reversal/correction | Medium | No test for correcting erroneous stock operation. Event log pattern supports `stock_adjusted` corrections. |
| Bulk operation stress test | Low | All tested with single-item operations. No batch PO receipt or concurrent transfers. |
| Insufficient stock transfer | Low | Transfer tests verify happy path, not rejection when source insufficient. |

### Deferred from Prior Audits

- **Token ability endpoint enforcement** — Carried from Phase 1-2. Implementation gap, not test gap.
- **Filament Livewire CRUD tests** — Carried from Phase 1-2. Test infrastructure investment.
- **CSV import partial failure** — Carried from Phase 3. Lab CSV rolls back entire batch on any row failure.

---

## Tests That Could Be Stronger

| File | Test | Issue |
|------|------|-------|
| StockMovementTest | "creates sold movement" | Verifies quantity sign but doesn't assert available stays non-negative. |
| PhysicalCountTest | "approves count with variances" | Checks adjustments made but doesn't verify exact signed quantity of each. |
| EquipmentTest | "creates maintenance log" | Tests happy path, doesn't verify next_due_date computed relative to performed_date. |
| InventorySeederTest | "seeds demo data" | Checks counts, doesn't verify event log entries match seeded data. |
| PurchaseOrderTest | "receives partial order" | Tests status transition, doesn't assert exact inventory delta. |

---

## What's Working Well

- **Signed-quantity ledger invariant tested.** Sum movements for SKU+location = on_hand. Most important test. Inventory untrustworthy if this fails.
- **Transfer pairing well-tested.** Every transfer creates exactly two movements (opposite signs). Verifies both exist and net effect correct.
- **Physical count approval triggers auditable reconciliation.** Full workflow: snapshot → count → variance → approval → stock_adjusted movements.
- **Self-contained event payloads verified everywhere.** Human-readable names (sku_name, location_name, vendor_name) + foreign keys.
- **PO lifecycle covers all transitions.** Draft → submitted → partial → received + cancellation, all with correct event logging.

---

## Metrics

| Metric | Phase 1-2 | Phase 3 | Phase 4 | Total |
|---|---|---|---|---|
| Test files | 19 | 7 | 11 | 37 |
| Tests | 354 | 124 | 265 | ~680+ |
| Tier 1 event types | 12 | 5 | 16 | 33 |
| Tier 1 tenant isolation files | 12 | 6 | 11 | 29 |
| PHPStan errors | 0 | 0 | 0 | 0 |
| Pint issues | 0 | 0 | 0 | 0 |

## Recommendation

Phase 4 ready for shipping. Most impactful Phase 5 additions: (1) concurrency test for `lockForUpdate()` — protects foundational inventory math that COGS depends on, (2) negative variance reconciliation — cost accounting needs confident variance data. Filament Livewire CRUD remains largest gap; plan as dedicated infrastructure work.
