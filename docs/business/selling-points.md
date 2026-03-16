# Selling Points

A running list of competitive differentiators discovered during development. Captured while the insight is fresh — these are raw notes for whoever builds sales materials later, not polished copy.

---

## Out-of-the-Box Lab Thresholds (Phase 3)

**What:** VineSuite ships with 17 industry-standard lab alert thresholds pre-configured, including volatile acidity at the 27 CFR 4.21 legal limit (1.2 g/L for red, 1.4 g/L for white). When a winemaker enters a lab analysis, the system immediately flags any value outside safe ranges — no setup required.

**Why it matters:** Every competitor (InnoVint, Vinsight, etc.) requires manual threshold configuration before alerts work. A new winery signs up for InnoVint and gets zero alerts until someone manually enters every threshold. VineSuite works on day one.

**Nuance:** Advanced winemakers can override thresholds per-tenant. The defaults are a floor, not a ceiling. But 80% of small wineries will never touch them, and that's the point.

---

## Immutable Event Log for TTB Compliance (Phase 1-2)

**What:** Every cellar operation — chemical additions, transfers, racking, blending, bottling — writes an immutable event with a PostgreSQL trigger that physically prevents UPDATE or DELETE. TTB reporting becomes an aggregation query over the event stream, not manual data entry into a separate reporting form.

**Why it matters:** TTB audits require complete, tamper-proof records. Most winery software stores mutable records and relies on the winery not to accidentally (or intentionally) alter their history. VineSuite can prove data integrity at the database level.

**Nuance:** The event log also enables offline mobile sync with idempotency keys — a cellar hand can log additions from a phone in a cave with no signal, and the data syncs cleanly when they walk back to the crush pad.

---

## Self-Contained Event Payloads (Phase 3)

**What:** Every event in the log includes human-readable context (lot name, variety, taster name) alongside foreign key UUIDs. The event stream is readable without joins.

**Why it matters:** Data portability. If a winery ever leaves VineSuite, they can export their complete event log as a standalone CSV that makes sense to a human without any database. No vendor lock-in through data obscurity.

---

## Schema-Per-Tenant Isolation (Phase 1)

**What:** Each winery gets its own PostgreSQL schema. There's no `WHERE winery_id = ?` in any query — the schema boundary enforces isolation at the database level.

**Why it matters:** One misbehaving query can never leak Winery A's data to Winery B. This is a strong security story for wineries that handle club member payment data. It's also a simpler codebase — no global scopes, no accidental cross-tenant queries, no "forgot the tenant filter" bugs.

---

## Real-Time Fermentation Curves (Phase 3)

**What:** Winemakers log daily Brix and temperature readings during fermentation. VineSuite plots a live dual-axis chart (Brix curve + temperature line + target temperature reference) that updates as entries are added.

**Why it matters:** Most winemakers track fermentation on paper or in Excel spreadsheets taped to tanks. Having a clean visual curve alongside lab results and sensory notes in one place eliminates the "spreadsheet on the clipboard next to the tank" workflow.

---

*Add new entries here as they come up during development. Format: What / Why it matters / Nuance.*
