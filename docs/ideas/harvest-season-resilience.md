# Harvest Season Resilience & Load Testing

> Status: Idea — needs consideration before Phase 5 launch
> Created: 2026-03-10
> Competitor lesson: Commerce7 (weekend outages, POS lockups during peak traffic)

---

## The Problem
Commerce7's most damaging complaint is system outages and POS lockups during peak business hours — Saturdays in tasting rooms, wine club pickup parties, harvest season. These are exactly the moments when the software matters most. A POS that crashes during a Saturday afternoon rush means lost sales, frustrated staff, and a winemaker who's back on Square by Monday.

The current task plan has no explicit load testing, stress testing, or resilience validation task. The architecture is sound (event-sourcing, offline-first mobile apps, schema-per-tenant isolation), but "architecturally sound" and "tested under realistic load" are different things.

## The Risk Window
Harvest season (August–October in California) is when:
- Cellar hands are logging 10x more operations per day (fermentation punch-downs every 4 hours, press runs, transfers)
- Tasting room traffic peaks on weekends (wine club pickup parties can see 200+ customers in a day)
- Event sync volume spikes (offline cellar app syncing batches of operations)
- The owner is too busy to troubleshoot — if it breaks, they switch tools and don't come back

The 00-index.md already says "Don't ship in July" — but it doesn't say "stress test before June."

## Proposed Approach

**Load simulation before first harvest:**
- Simulate 50 concurrent cellar app syncs (batch event uploads)
- Simulate 10 concurrent POS terminals running transactions
- Simulate 500 club members hitting the store simultaneously (club release day)
- Run against a tenant with realistic data volume (the demo seeder data from Task 2 Sub-Task 14)

**Offline-first validation:**
- Kill network mid-sync on cellar app, verify no data loss
- Kill network mid-transaction on POS, verify transaction completes locally and syncs later
- Simulate spotty rural WiFi (high latency, packet loss) during normal operations
- Verify the POS never shows a loading spinner to a customer for more than 2 seconds

**Database stress:**
- 200 barrels topped in one session (bulk barrel operations — Task 2 Sub-Task 12 calls this out as high-volume)
- Event log write throughput under concurrent cellar hand operations
- Schema-per-tenant query isolation under load (one busy tenant shouldn't slow another)

**Graceful degradation:**
- What happens when Redis is down? (Cache miss → DB fallback, not crash)
- What happens when the queue backs up? (Operations still succeed, sync delays but doesn't block)
- What happens when PostgreSQL hits connection limits? (Queue, don't reject)

## Architecture Compatibility
The current architecture handles most of this well by design:
- Event-sourcing means writes are append-only (no lock contention)
- Schema-per-tenant means busy tenants don't interfere with each other
- Offline-first mobile apps mean the POS works regardless of server state

The gap is *proving* it works under pressure, not redesigning it to work.

## When to Address
Before the first winery goes through harvest on VineSuite. Practically, this means load testing should happen during Phase 5 (Cellar App) development, before the "SELL IT HERE" milestone.

Not a full task file — a sub-task or two added to Task 8 (Cellar App) and Task 9 (POS App) for stress testing and offline resilience validation.

## Open Questions
- What's the realistic upper bound for concurrent users per tenant? (Most wineries: 3–8 staff. Larger operations: 15–20.)
- Should we use a load testing framework (k6, Artillery) or hand-roll test scripts?
- Is there a way to simulate harvest-level event volume using the demo seeder as a base?
