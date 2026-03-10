# Multi-Tenancy

> Last updated: —
> Relevant source: `api/config/tenancy.php`, tenant models (not yet created)
> Architecture doc: Section 2.3

---

## What This Is
Schema-per-tenant isolation via `stancl/tenancy`. Each winery gets its own PostgreSQL schema. Central schema holds: tenant registry, billing, VineBook directory data. Tenant identification via subdomain or API token header.

## How It Works
*To be written during Phase 1, Sub-Task 2 (Multi-Tenancy Setup).*

## Key Files
*To be populated as files are created.*

## Gotchas
- Every query automatically scopes to the current tenant's schema — no `WHERE winery_id = ?` needed
- Central data (tenants table, billing, VineBook) lives in the `public` schema
- Scaling ceiling: ~500 tenants before migration runs become painful. Documented in architecture.md Section 2.3.
- Tenant provisioning is async (queued job, ~10 seconds)
- Never raw-query across tenant schemas — use the tenancy package's central connection for cross-tenant work

## History
- To be populated as work progresses
