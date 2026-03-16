# Custom Crush & Alternating Proprietorship Portal

> Status: Idea — partially addressed by Task 21 sub-task 3, but fundamentally under-scoped
> Created: 2026-03-16
> Source: Research gap analysis (Tin City ecosystem), user feedback
> Priority: Medium-High — Paso Robles beachhead wedge with near-zero competitive pressure

---

## The Problem

Task 21 sub-task 3 models custom crush as "lots tagged with a client name inside a single tenant, with read-only client access." This covers the simplest case — a facility that processes grapes for clients and invoices them. But the Tin City / Paso Robles AP ecosystem is structurally different and more complex.

### What Alternating Proprietorship Actually Looks Like

An Alternating Proprietorship (AP) arrangement means multiple independent bonded wine premises operate at different times within the same physical facility. Each AP holder:

- Has their own TTB Basic Permit (a separate bond)
- Files their own TTB 5120.17 report
- Has their own state ABC license (California Type 02)
- Owns their own inventory (bulk and case goods) even though it sits in shared tanks/barrels
- Makes their own winemaking decisions (additions, blending, bottling)
- Is legally responsible for their own compliance
- Often has their own DTC sales, wine club, and tasting room events (even if they pour at a shared tasting room)

The facility operator (e.g., Pacific Wine Services, Fortress Custom Crush) provides:

- Physical infrastructure (tanks, barrels, bottling line, cold storage)
- Often a shared winemaker or consulting winemaker
- Equipment scheduling (crush pad, press, bottling line)
- Utilities, sanitation, insurance
- Sometimes shared tasting room space

With 40+ producers sharing infrastructure in Tin City alone, and similar ecosystems in urban winery districts nationwide (Lompoc Wine Ghetto, Warehouse Row in Santa Barbara, etc.), this is not a niche edge case. It's a growing segment of the wine industry, and it's completely underserved by software.

### Why Task 21 Sub-Task 3 Doesn't Cover This

Task 21's `CrushClient` model treats the facility as a single tenant that "tags" lots with a client. The client gets filtered read-only access to "their" lots within the facility's tenant schema. This breaks down because:

1. **TTB compliance is per-permit-holder.** If Client A has their own bond, their 5120.17 report must reflect only their operations. Aggregating from "tagged lots" within the facility's tenant is fragile and audit-risky. Each AP holder needs their own compliance boundary.

2. **Clients need operational access, not read-only.** An AP holder's winemaker logs additions, makes blending decisions, approves bottling runs. They're not passively watching — they're actively operating within the facility. Read-only access is insufficient.

3. **Inventory ownership is legally distinct.** When Client A's 500 gallons of Syrah sit in Tank 7 alongside Client B's 300 gallons of Grenache (in a different tank), the facility doesn't own either. Each gallon must be attributable to its bond holder for TTB purposes. If Client A leaves the facility, their inventory goes with them — it's their property.

4. **The facility operator needs a god-view.** Pacific Wine Services needs to see all clients' operations to schedule shared resources (press time, bottling line, cold storage), track facility-level inventory (how full is Tank 7?), and invoice for services. No individual client should see any other client's data.

5. **Billing is service-based, not product-based.** The facility bills clients for: storage (per barrel/month, per tank/month), processing (per ton crushed, per case bottled), materials (additions, dry goods consumed), labor, and equipment usage. This is a service invoice tied to operations, not a product sale.

---

## Proposed Architecture

### The Core Insight: AP Holders ARE Tenants

Each AP holder should be a full VineSuite tenant with their own schema, their own lot lifecycle, their own compliance, and their own DTC/club capabilities. The facility is also a tenant, but with a special "facility operator" role that grants cross-tenant visibility and resource management.

This maps cleanly to the existing schema-per-tenant architecture. What's new is the relationship layer between facility and client tenants.

### Data Models (Central Schema)

- **FacilityRelationship** — `id` (UUID), `facility_tenant_id`, `client_tenant_id`, `status` (active/suspended/terminated), `contract_start`, `contract_end`, `billing_terms` (JSON — rate card), `created_at`, `updated_at`

- **SharedResource** — `id` (UUID), `facility_tenant_id`, `resource_type` (tank/barrel/press/bottling_line/cold_storage/crush_pad), `resource_name`, `capacity` (nullable), `unit` (gallons/cases/tons), `hourly_rate` (nullable), `daily_rate` (nullable), `monthly_rate` (nullable), `is_active`, `created_at`, `updated_at`

- **ResourceReservation** — `id` (UUID), `shared_resource_id`, `client_tenant_id`, `reserved_from`, `reserved_to`, `purpose` (crush/press/bottling/storage/other), `status` (requested/confirmed/in_progress/completed/cancelled), `notes`, `created_at`, `updated_at`

- **FacilityServiceLog** — `id` (UUID), `facility_tenant_id`, `client_tenant_id`, `service_type` (storage/processing/materials/labor/equipment), `description`, `quantity`, `unit_rate`, `total_amount`, `performed_date`, `reference_event_id` (nullable — links to client's event log for traceability), `invoiced` (boolean), `invoice_id` (nullable), `created_at`

### How It Works

**Client tenant experience:** An AP holder signs up for VineSuite as a normal winery. They get their own portal, their own lots, their own compliance, their own everything. When they join a facility, a `FacilityRelationship` links their tenant to the facility's tenant. From the client's perspective, nothing changes about how they use VineSuite — they manage lots, log additions, run blending trials, generate TTB reports. The only difference is that their physical vessels (tanks, barrels) are shared resources owned by the facility.

**Facility operator experience:** The operator logs into their own facility tenant and sees a "Clients" dashboard showing all active AP holders. They can:
- View a calendar of shared resource usage across all clients
- Schedule bottling line time, press time, crush pad access
- See facility-wide tank/barrel utilization (which client is in which vessel, how full)
- Log service charges against client operations
- Generate invoices per client per billing period
- Monitor compliance status across clients (who's filed their TTB report, who hasn't)

**Shared vessel tracking:** This is the trickiest part. A tank in the facility is a `SharedResource` in the central schema, but when Client A uses it, the wine inside is a `Lot` in Client A's tenant schema. The vessel assignment (lot → shared resource) must be visible to both the client and the facility. This is a cross-tenant reference that needs careful handling — the client's lot record references a shared resource ID, and the facility's resource schedule shows which client's lot is currently in each vessel.

### Billing Integration

The facility logs services as `FacilityServiceLog` entries against client tenants. These can be:
- Auto-generated from operations (bottling run completed for Client A → storage charge + bottling charge + dry goods charge)
- Manually entered (monthly tank rental, consulting winemaker hours)
- Invoiced in batches (monthly invoice per client, itemized by service type)

Invoices can be generated as PDFs or synced to the facility's QuickBooks via Task 16.

---

## Competitive Landscape

No existing winery software handles this well:

- **InnoVint:** Can create separate "organizations" but no facility operator view across them. No shared resource scheduling. No cross-org billing.
- **vintrace:** Has some multi-client capabilities from its enterprise roots but it's expensive and not designed for the small facility / indie AP model.
- **360Winery:** Claims multi-brand but unclear if it supports true AP compliance separation.
- **Spreadsheets:** This is what most Tin City facilities actually use. The facility operator maintains a master spreadsheet of who's in which tank, when the bottling line is reserved, and what to bill each client.

The competitive pressure here is essentially zero. No one is building this.

---

## Why This Is a Paso Robles Wedge

Tin City alone has 40+ producers in a concentrated few blocks. Paso Robles has additional custom crush and AP facilities outside Tin City. If VineSuite can become the default platform for even one or two facility operators (Pacific Wine Services, Fortress, Tin City Cider), the network effect is immediate: every AP holder at that facility encounters VineSuite as their production platform, and many will want their own full tenant for DTC, wine club, and compliance. One facility operator signing up could bring 10-40 new tenant signups.

This is a beachhead within the beachhead.

---

## Relationship to Task 21

Task 21 sub-task 3 (`CrushClient` model) should be reconsidered. Two options:

**Option A — Replace sub-task 3.** Remove the `CrushClient` tag-lots-with-a-name approach entirely. Replace it with the tenant-relationship model described here. This is cleaner architecturally but adds significant scope to Task 21.

**Option B — Keep sub-task 3 as the simple case, build this as a separate task.** Sub-task 3 serves the "we process some grapes for a friend" scenario. The full AP portal serves the "we operate a facility with 20 clients" scenario. Different scale, different needs. Sub-task 3 ships in Phase 8 as planned. The AP portal becomes Task 26 or a Phase 9 feature, built when a real facility operator is ready to pilot.

**Recommendation: Option B.** Sub-task 3 is quick to build and covers 80% of casual custom crush arrangements. The full AP portal is a significant feature that deserves its own task and should be driven by a real facility operator's needs, not speculative design.

---

## When to Build

Phase 8+ or when a facility operator in Paso Robles expresses real interest. The central-schema `FacilityRelationship` and `SharedResource` models should be sketched during Phase 1 or 2 to reserve the design space, but no code should be written until the core winery product is revenue-generating and a real facility has committed to piloting.

The one thing to do now: confirm that the schema-per-tenant model doesn't have any assumptions that would prevent cross-tenant resource references later. (It shouldn't — the central schema is specifically for cross-tenant concerns.)

## Tier Placement

- **Facility operator:** Pro tier (the facility needs consolidated views, billing, and resource scheduling)
- **AP holder clients:** Can be any tier (they're normal winery tenants). Their interaction with the facility is via the central schema relationship, not via their plan tier.
