# Selling Points

Raw competitive differentiators discovered during development. For whoever builds sales materials later.

---

## Out-of-the-Box Lab Thresholds (Phase 3)

VineSuite ships with 17 industry-standard lab alert thresholds pre-configured, including VA at the 27 CFR 4.21 legal limit. Enter a lab analysis, get instant flags. No setup required.

**vs competitors:** InnoVint, Vinsight, etc. all require manual threshold config before alerts work. VineSuite works on day one. Advanced winemakers can override per-tenant — the defaults are a floor, not a ceiling.

---

## Immutable Event Log for TTB (Phase 1-2)

Every cellar operation writes an immutable event with a PostgreSQL trigger preventing UPDATE/DELETE. TTB reporting = aggregation query over the event stream, not manual data entry.

**vs competitors:** Most store mutable records and hope nobody alters history. VineSuite can prove data integrity at the DB level. Bonus: the same event log enables offline mobile sync with idempotency keys.

---

## Self-Contained Event Payloads (Phase 3)

Every event includes human-readable context (lot name, variety, taster name) alongside FK UUIDs. The event stream is readable without joins.

**Why it sells:** Data portability. Export your event log as a standalone CSV that makes sense to a human. No vendor lock-in through data obscurity.

---

## Schema-Per-Tenant Isolation (Phase 1)

Each winery gets its own PostgreSQL schema. No `WHERE winery_id = ?` anywhere. Schema boundary enforces isolation at the DB level.

**Why it sells:** One bad query can never leak Winery A's data to Winery B. Strong security story for wineries handling club member payment data.

---

## Real-Time Fermentation Curves (Phase 3)

Live dual-axis chart (Brix + temperature + target reference) updates as winemakers log daily readings.

**Why it sells:** Replaces the spreadsheet taped to the tank. Clean visual curve alongside lab results and sensory notes in one place.

---

*Add new entries here. Format: What / vs competitors or Why it sells / optional nuance.*
