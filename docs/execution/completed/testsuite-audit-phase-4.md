# Testsuite Audit — Phase 4 (Inventory Management)

> Audited: 2026-03-15
> Scope: 11 test files in `tests/Feature/Inventory/`, 265 tests, ~680 total suite
> Standard: `docs/guides/testing-and-logging.md` tier system
> Prior audit: `testsuite-audit-phase-3.md`

---

## Verdict

The Phase 4 test suite is **comprehensive on the critical inventory math and event logging paths** — every stock-modifying operation (receive, sell, adjust, transfer) verifies both the StockLevel mutation and the corresponding StockMovement ledger entry, physical count approval triggers reconciliation movements with correct signed quantities, and the event log captures all 16 operation types with full payload verification. Tenant isolation is present in every file. The main gaps are in concurrency testing (no `lockForUpdate()` race condition tests), physical count edge cases (negative variances, zero-variance lines), and the continued deferral of Filament Livewire CRUD tests and token ability enforcement from earlier audits.

---

## Tier 1 Audit (Must Have)

### Event Log Writes — PASS (Excellent)

All 16 inventory event types have dedicated tests verifying the event was written with correct `operation_type`, `event_source: 'inventory'`, and payload structure:

| Operation Type | Tested In | Payload Fields Verified |
|---|---|---|
| `stock_received` | StockMovementTest | sku_id, sku_name, location_id, location_name, quantity, movement_type |
| `stock_sold` | StockMovementTest | sku_id, sku_name, location_id, location_name, quantity, movement_type |
| `stock_adjusted` | StockMovementTest, PhysicalCountTest | sku_id, sku_name, location_id, location_name, quantity, reference_type, reference_id |
| `stock_transferred` | StockTransferTest | sku_id, sku_name, from_location_id, from_location_name, to_location_id, to_location_name, quantity |
| `stock_count_started` | PhysicalCountTest | location_id, location_name, line_count |
| `stock_counted` | PhysicalCountTest | location_id, location_name, total_lines, adjustments_made |
| `stock_count_cancelled` | PhysicalCountTest | location_id, location_name, lines_recorded |
| `purchase_order_created` | PurchaseOrderTest | po_number, vendor_name, line_count, total_amount |
| `purchase_order_updated` | PurchaseOrderTest | po_number, vendor_name, changes |
| `purchase_order_received` | PurchaseOrderTest | po_number, vendor_name, lines_received, new_status |
| `equipment_created` | EquipmentTest | name, equipment_type, serial_number |
| `equipment_updated` | EquipmentTest | name, changes |
| `dry_goods_created` | DryGoodsTest | name, item_type, unit_of_measure |
| `dry_goods_updated` | DryGoodsTest | name, changes |
| `raw_material_created` | RawMaterialTest | name, category, unit_of_measure |
| `raw_material_updated` | RawMaterialTest | name, changes |

**Event source partitioning:** Tests verify that all inventory events have `event_source: 'inventory'` auto-resolved from the operation_type prefix via `EventLogger::resolveSource()`.

### Inventory Math — PASS (Strong)

Stock operations are tested with signed-quantity arithmetic:

| Operation | Math Verified |
|---|---|
| Receive 50 cases | on_hand += 50, available += 50, movement.quantity = +50 |
| Sell 10 cases | on_hand -= 10, available -= 10, movement.quantity = -10 |
| Adjust +5 | on_hand += 5, available += 5, movement.quantity = +5 |
| Adjust -3 | on_hand -= 3, available -= 3, movement.quantity = -3 |
| Transfer 20 | source on_hand -= 20, destination on_hand += 20, two movements (transferred_out=-20, transferred_in=+20) |
| Physical count approval | variance applied as stock_adjusted movement, on_hand updated to match counted_quantity |
| Committed quantity | available = on_hand - committed tested |

**Self-consistency check:** StockMovementTest verifies that summing all movements for a SKU+location yields the current on_hand — the fundamental ledger invariant.

### Tenant Isolation — PASS (Complete)

Every test file verifies schema isolation:

| Test File | Isolation Test |
|---|---|
| CaseGoodsSkuTest | Cross-tenant SKU access returns 404 |
| LocationStockLevelTest | Cross-tenant location access returns 404 |
| StockMovementTest | Cross-tenant stock operation rejected |
| StockTransferTest | Cross-tenant transfer rejected |
| DryGoodsTest | Cross-tenant dry goods access returns 404 |
| RawMaterialTest | Cross-tenant raw material access returns 404 |
| EquipmentTest | Cross-tenant equipment access returns 404 |
| PurchaseOrderTest | Cross-tenant PO access returns 404 |
| PhysicalCountTest | Cross-tenant count access returns 404 |
| BulkWineInventoryTest | Cross-tenant bulk wine data filtered |
| InventorySeederTest | Seeder data scoped to correct tenant |

### Authentication — PASS

All API endpoints reject unauthenticated requests with 401 status. Tested across all 11 files.

---

## Tier 2 Audit (Should Have)

### API Endpoint Contracts — PASS

All test files verify the standard API envelope format (`data`, `meta`, `errors`). HTTP status codes tested: 200, 201, 403, 404, 422. Pagination meta verified (total, current_page, per_page, last_page) on list endpoints.

### RBAC — PASS

| Operation | Allowed Roles Tested | Denied Roles Tested |
|---|---|---|
| SKU CRUD | winemaker (201) | read_only (403) |
| Location CRUD | admin (201) | read_only (403) |
| Stock receive/sell/adjust | winemaker (201) | read_only (403) |
| Stock transfer | winemaker (201) | read_only (403) |
| Dry goods CRUD | winemaker (201) | read_only (403) |
| Raw material CRUD | winemaker (201) | read_only (403) |
| Equipment CRUD | admin (201) | read_only (403) |
| Maintenance log CRUD | winemaker (201) | read_only (403) |
| PO CRUD + receive | admin (201) | read_only (403) |
| Physical count lifecycle | winemaker (201) | read_only (403) |
| View/list (all resources) | all roles (200) | unauthenticated (401) |

### Validation — PASS

Validation tests cover: missing required fields (wine_name, vintage, format, case_size for SKUs), invalid enum values (location_type, movement_type, maintenance_type, item_type, category, PO status), negative quantities rejected, non-existent foreign keys (sku_id, location_id, lot_id), duplicate UPC barcode prevention, quantity exceeding available stock.

### Purchase Order Lifecycle — PASS (Complete)

Full PO lifecycle tested: draft → submitted → partial (after partial receive) → received (after full receive). Tests verify:
- Line item quantities update on receipt
- Inventory quantities increase when receiving dry goods and raw materials
- Partial receiving transitions PO to `partial` status
- Full receiving transitions to `received` status
- PO cancellation tested with correct event logging

### Physical Count Workflow — PASS (with gaps)

Lifecycle tested: startCount → recordCounts → approve (with variance reconciliation). Tests verify:
- System quantities snapshot correctly at count start
- Variance computation (counted - system)
- Approval generates `stock_adjusted` movements for each non-zero variance
- Cancel event logging (added in Sub-Task 12 fix)

**Gaps:** See Gaps Identified section below.

### Meilisearch Integration — PASS (Conditional)

CaseGoodsSkuTest includes search endpoint tests with Meilisearch. Uses Scout fallback when Meilisearch is unavailable. Search by wine name, varietal, and UPC tested.

### Bulk Wine Inventory — PASS

BulkWineInventoryTest verifies the aggregation view pulls lot volume data from the production module: total gallons, vessel counts, lot status filtering, and correct scoping to the tenant.

---

## Gaps Identified

### Should Address in Phase 5 (Medium Priority)

| Gap | Severity | Details |
|---|---|---|
| `lockForUpdate()` race condition | Medium | No test verifies that concurrent stock operations are serialized correctly. `InventoryService` uses `lockForUpdate()` but this is not tested under concurrent conditions. A race condition bug here could cause phantom inventory. |
| Physical count: negative variance reconciliation | Medium | Tests cover positive variances (counted > system) but do not explicitly test negative variances (counted < system → negative adjustment movement). The code handles it via signed quantities, but no dedicated test. |
| Physical count: zero-variance lines | Low | No test verifies that lines where counted_quantity equals system_quantity generate no stock_adjusted movement. If this logic fails, the event log gets polluted with no-op adjustments. |
| Movement reversal/correction | Medium | No test for correcting an erroneous stock operation (e.g., received quantity was wrong). The event log pattern supports `stock_adjusted` corrections but no workflow test exists. |
| Bulk operation stress test | Low | All stock operations are tested with single-item operations. No test verifies batch receiving (e.g., 100 line items in a single PO receipt) or concurrent transfers across multiple locations. |
| Insufficient stock transfer rejection | Low | Transfer tests verify the happy path but do not explicitly test the case where source location has insufficient stock to complete the transfer. |

### Deferred from Prior Audits (Still Outstanding)

| Item | Status | Notes |
|---|---|---|
| Token ability endpoint enforcement | Not addressed | Carried from Phase 1-2 audit. Token abilities assigned at login but not enforced via middleware. Requires implementation + tests together. |
| Filament Livewire CRUD tests | Not addressed | Carried from Phase 1-2 audit. Requires subdomain test harness for Livewire rendering. No Phase 4 resources are tested through Filament. |
| CSV import partial failure handling | Not addressed | Carried from Phase 3. Lab CSV import rolls back entire batch on any row failure. |

These remain non-blocking. Token enforcement is an implementation gap, not a test gap. Filament CRUD is a test infrastructure investment that should be addressed as a dedicated effort.

---

## Tests That Could Be Stronger

| File | Test | Issue |
|---|---|---|
| StockMovementTest | "creates sold movement" | Verifies quantity sign but doesn't assert that available never goes negative in the assertion chain. |
| PhysicalCountTest | "approves count with variances" | Checks that adjustments were made but doesn't verify the exact signed quantity of each adjustment movement. |
| EquipmentTest | "creates maintenance log" | Tests the happy path but doesn't verify that future next_due_date is computed/stored correctly relative to performed_date. |
| InventorySeederTest | "seeds demo data" | Checks record counts but doesn't verify event log entries match the seeded data count (unlike the production seeder test pattern). |
| PurchaseOrderTest | "receives partial order" | Tests status transition but doesn't assert the exact inventory delta on the receiving end. |

These are minor — the tests work, they just could assert more precisely.

---

## What's Working Well

**Signed-quantity ledger invariant is tested.** The test that sums all movements for a SKU+location and compares to on_hand is the most important inventory test. If this invariant fails, the entire inventory system is untrustworthy.

**Transfer pairing is well-tested.** Every transfer creates exactly two movements (transferred_out and transferred_in) with opposite signs. Tests verify both movements exist and that the net effect on each location is correct.

**Physical count approval triggers auditable reconciliation.** The full workflow test — snapshot → count → variance computation → approval → stock_adjusted movements — covers the complete physical inventory cycle. The `stock_count_cancelled` event logging (added in Sub-Task 12) means no lifecycle state change goes unrecorded.

**Self-contained event payloads verified everywhere.** Following the Phase 3 pattern, every event test checks for human-readable names (sku_name, location_name, vendor_name) alongside foreign keys, ensuring the event stream remains portable.

**PO lifecycle covers all status transitions.** Draft → submitted → partial → received, plus cancellation, are all tested with correct event logging at each transition.

---

## Metrics

| Metric | Phase 1-2 | Phase 3 | Phase 4 | Total |
|---|---|---|---|---|
| Test files | 19 | 7 | 11 | 37 |
| Tests | 354 | 124 | 265 | ~680+ |
| Tier 1 event types tested | 12 | 5 | 16 | 33 |
| Tier 1 tenant isolation files | 12 | 6 | 11 | 29 |
| PHPStan errors | 0 | 0 | 0 | 0 |
| Pint issues | 0 | 0 | 0 | 0 |

## Recommendation

The Phase 4 test suite is ready for shipping. The most impactful additions for Phase 5 would be: (1) a concurrency test for `lockForUpdate()` to catch race conditions in stock operations — this protects the foundational inventory math that COGS calculations will depend on, and (2) negative variance reconciliation in physical counts — Phase 5's cost accounting needs confident variance data. The Filament Livewire CRUD tests remain the largest systematic gap carried from Phase 1-2 and should be planned as dedicated infrastructure work.
