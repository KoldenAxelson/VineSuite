# Winery SaaS Suite — Technical Architecture

> Condensed architecture overview. For the full original spec (881 lines covering KMP, POS, VineBook, widgets, payments, AI, infrastructure, and dev environment in detail), see `to-be-deleted/architecture-original.md`.

---

## 1. Product Overview

A multi-surface SaaS platform for small-to-mid-size wineries. All surfaces consume a single Laravel API.

| Surface | Type | Primary User | Connectivity |
|---|---|---|---|
| Management Portal | Web App (TALL) | Owner, Winemaker, Accountant | Always online |
| Cellar App | Native Mobile (KMP) | Cellar Hand, Winemaker | Offline-first |
| POS App | Native Tablet (KMP) | Tasting Room Staff | Offline-first |
| VineBook Directory | Static Site (Astro) | Wine Consumers | Always online |
| Embeddable Widgets | JS Web Components | Winery's existing website visitors | Always online |
| Platform API | Laravel JSON API | All of the above | — |

---

## 2. The Platform API

- **Framework:** Laravel 12 (PHP 8.4+)
- **Database:** PostgreSQL 16
- **Cache + Queue:** Redis (single instance early stage)
- **Queue Manager:** Laravel Horizon
- **WebSockets:** Laravel Reverb (self-hosted)
- **Search:** Meilisearch (self-hosted)
- **File Storage:** Cloudflare R2 (S3-compatible)
- **Email:** Resend via Laravel Mail
- **Auth:** Laravel Sanctum (API token auth, scoped token abilities for Pro-tier API access)
- **Admin Scaffolding:** Filament v3 (pinned — upgrade to v4 when stable)
- **PDF Generation:** DomPDF (`barryvdh/laravel-dompdf`)

### API Design
- REST JSON API, versioned from day one: `/api/v1/`
- Consistent envelope: `{ "data": {}, "meta": {}, "errors": [] }`
- Bearer token auth (Sanctum), scoped per client type
- Rate limiting per token via built-in throttle middleware

### Multi-Tenancy
- Schema-per-tenant via `stancl/tenancy`. Each winery gets an isolated PostgreSQL schema.
- Central schema holds: tenant registry, billing, VineBook directory data.
- Tenant identification via subdomain or API token header.
- Scaling ceiling: works well up to ~500 tenants. Beyond that, evaluate hybrid approach.
- See `references/multi-tenancy.md` for patterns and implementation details.

### Background Jobs (Horizon + Redis)
Queued work: club batch charges, TTB report generation, AI digests, email/SMS, shipping labels, QuickBooks/Xero sync, data imports, webhook dispatches, Meilisearch index sync.

Priority levels: `critical` (payments, auth) → `default` (orders, notifications) → `low` (reports, AI, sync).

---

## 3. The Event Log (Core Data Pattern)

**The most important architectural decision in the system.** All winery operations are recorded as immutable events, not just state updates.

> This is an append-only event log with materialized CRUD tables — not full event sourcing (no event replay, projector rebuilds, or CQRS).

### Why
- TTB reporting = simple aggregation of event stream
- Offline sync safety — mobile devices POST events, server aggregates, ordering by `performed_at`
- Full audit log is free — every lot's history is its event stream
- Undo/correction via correcting events (never mutate history)

### Events Table Schema (key columns)
`id` (UUID), `entity_type`, `entity_id`, `operation_type`, `payload` (JSONB), `performed_by`, `performed_at` (client timestamp), `synced_at` (server receipt), `device_id`, `idempotency_key` (UNIQUE).

### Current State
Traditional CRUD tables (lots, vessels, inventory) are **materialized views** of the event stream, kept in sync by event handlers. Direct mutation only for non-operational data (settings, profiles).

See `references/event-log.md` for EventLogger usage, event types, and patterns.

---

## 4. The Management Portal

- **Stack:** TALL (Tailwind + Alpine.js + Livewire + Laravel)
- **Admin:** Filament v3 for data tables, forms, filters, bulk actions, navigation
- **Custom Livewire components** for: visual tank map, fermentation charts, TTB report review, club processing flow
- **Real-time:** Laravel Reverb + Livewire Echo listeners for live dashboard updates
- **Deployment:** Same Laravel instance as the API (separate route groups, shared codebase)

Covers: production management, vineyard, inventory, COGS, TTB compliance, club management, CRM, reservations, wholesale, reporting, AI digests, settings/billing.

---

## 5. Future Surface Areas (Summarized)

These surfaces are specced but not yet built. Key tech decisions only — full specs in `to-be-deleted/architecture-original.md`.

**Cellar App + POS App:** Kotlin Multiplatform (KMP) shared core (sync engine, SQLDelight local DB, Ktor API client) + native UI per platform (Compose on Android, SwiftUI on iOS). Both offline-first via local event queue + server sync. POS uses Stripe Terminal native SDKs for offline card payments.

**VineBook Directory:** Astro static site on Cloudflare Pages. Seeded from TTB public data (~11,000 wineries), enriched via Yelp/Google APIs. Subscriber wineries get interactive Astro islands (shop, booking, club signup).

**Embeddable Widgets:** Web Components loaded from CDN, Shadow DOM isolation, framework-agnostic. Widget types: store, reservations, club-signup, member-portal.

---

## 6. Payments Architecture (Summary)

Currently Stripe-only via Laravel Cashier + Stripe Connect (platform fee auto-deducted). Future: `PaymentProcessor` interface to abstract BYO processors (Square, etc.) for Pro+ tiers. Cards tokenized at processor level (PCI SAQ-A). POS uses Stripe Terminal native SDKs with offline capture.

---

## 7. Integrations (Summary)

All integrations follow push (event → job → API call) or pull (webhook → validate → event) patterns.

Key integrations: Stripe (all tiers), QuickBooks/Xero (Pro+), Sovos ShipCompliant (Pro+), Mailchimp/Klaviyo (Pro/Max), FedEx/UPS (Pro+), Anthropic API for AI features (Max), ETS Labs CSV import.

Max tier gets configurable outbound webhooks with HMAC signatures and retry logic.

---

## 8. Infrastructure

**Hosting:** Hetzner Cloud + Laravel Forge. ~$50-60/month to start.
**CDN/DNS:** Cloudflare (free tier). **Static hosting:** Cloudflare Pages.
**Database:** Managed PostgreSQL. **Redis:** Upstash. **Search:** Meilisearch.
**Monitoring:** Telescope (dev) + Sentry (prod) + Better Uptime.
**CI/CD:** GitHub Actions → Forge webhook → zero-downtime deploy.

---

## 9. Security

- All traffic HTTPS via Cloudflare
- API tokens scoped per client and per permission set
- Tenant schema isolation prevents cross-tenant access
- PII encrypted at rest (Laravel `encrypted` cast)
- Payment card data never stored — processor tokens only (PCI SAQ-A)
- CORS locked per API key to registered winery domains
- Rate limiting on all public endpoints
- Dependabot, CSRF protection, parameterized queries

---

## 10. Key Architectural Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Backend | Laravel 12 (PHP 8.4+) | Founder expertise, excellent ecosystem |
| Database | PostgreSQL | JSONB for event payloads, strong reporting queries |
| Multi-tenancy | Schema-per-tenant | Clean isolation, easier compliance |
| Data pattern | Append-only event log + materialized state | Audit trail, offline sync, TTB reporting (not full event sourcing) |
| Web frontend | TALL stack (Livewire + Alpine) | Founder expertise, no unnecessary JS framework |
| Admin scaffolding | Filament v3 (pinned) | Accelerates table/form UI by ~60% |
| Mobile | KMP + native UI (Compose/SwiftUI) | Shared sync engine, truly native UX, both platforms |
| Directory | Astro | Content-first, island architecture, near-zero hosting |
| Widgets | Web Components | Framework-agnostic, Shadow DOM isolation |
| Payments | Stripe Connect + abstraction layer | Managed default + BYO option |
| AI | Anthropic API (background jobs only) | Pro-tier gate, never blocks core ops |
| Hosting | Hetzner + Forge | 3-4x cheaper than AWS at early stage |
