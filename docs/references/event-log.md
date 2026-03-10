# Event Log

> Last updated: —
> Relevant source: `api/app/Services/EventLogger.php` (not yet created)
> Architecture doc: Section 3

---

## What This Is
The append-only event log is the foundational data pattern for the entire platform. Every winery operation (addition, transfer, blend, sale, etc.) writes an immutable event record. Materialized CRUD tables (lots, vessels, inventory) are derived from these events via handlers. This is NOT full event sourcing — no replay, no projectors, no CQRS. It's a pragmatic audit log that enables TTB reporting, offline sync safety, and AI features.

## How It Works
*To be written during Phase 1, Sub-Task 5 (Event Log Infrastructure).*

## Key Files
*To be populated as files are created.*

## Usage Patterns
*To be written with code examples once EventLogger service exists.*

## Gotchas
- Events table is immutable — no UPDATE, no DELETE, ever
- Corrections are new events (e.g., `addition_corrected`), not edits to old events
- `idempotency_key` prevents duplicate submissions from offline retry — always generate client-side
- `performed_at` is the client timestamp (may be from an offline device); `synced_at` is server receipt time
- For volume-sensitive operations, the server validates physical possibility on receipt

## History
- To be populated as work progresses
