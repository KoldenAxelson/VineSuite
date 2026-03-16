# architecture.md
# Winery SaaS Suite — Technical Architecture
> To be read alongside `Task-Generation-Overview-Planning.md`.
> This document covers the full technical blueprint: apps, stack decisions, data architecture, sync strategy, integrations, and infrastructure.

---

## 1. PRODUCT OVERVIEW

This is not a single application. It is a **platform with multiple surface areas** — each surface tailored to a specific user, device, and job. All surfaces are consumers of a single Laravel API (the brain). No surface owns data. The API owns data.

The product consists of:

| Surface | Type | Primary User | Connectivity |
|---|---|---|---|
| Management Portal | Web App (TALL) | Owner, Winemaker, Accountant | Always online |
| Cellar App | Native Mobile (KMP) | Cellar Hand, Winemaker | Offline-first |
| POS App | Native Tablet (KMP) | Tasting Room Staff | Offline-first |
| VineBook Directory | Static Site (Astro) | Wine Consumers | Always online |
| Embeddable Widgets | JS Widgets | Winery's existing website visitors | Always online |
| Platform API | Laravel JSON API | All of the above | — |

---

## 2. THE PLATFORM API (THE BRAIN)

### 2.1 Technology
- **Framework:** Laravel 12 (PHP 8.4+)
- **Database:** PostgreSQL 16
- **Cache + Queue Backend:** Redis (single instance early stage; separate into dedicated cache and queue instances when scaling past ~100 tenants to prevent long-running queue jobs from evicting cache keys)
- **Queue Manager:** Laravel Horizon
- **WebSockets:** Laravel Reverb (self-hosted)
- **Search:** Meilisearch (self-hosted, fast full-text for lots, customers, SKUs)
- **File Storage:** Cloudflare R2 (S3-compatible, cheaper than AWS)
- **Email:** Resend (transactional) via Laravel Mail
- **SMS:** Twilio via Laravel Notifications
- **PDF Generation:** DomPDF (`barryvdh/laravel-dompdf`) for structured documents (TTB reports, invoices, receipts); Browsershot reserved for complex visual reports only
- **Auth:** Laravel Sanctum (API token auth for all clients, including Pro-tier API access via scoped token abilities)

### 2.2 API Design
- REST JSON API throughout
- API versioned from day one: `/api/v1/`
- All responses follow a consistent envelope:
  ```json
  {
    "data": {},
    "meta": {},
    "errors": []
  }
  ```
- Authentication via Bearer tokens (Sanctum)
- Scoped tokens per client type (Management Portal, Cellar App, POS, Widgets)
- Rate limiting per token via Laravel's built-in throttle middleware
- Public API (Pro tier) uses Sanctum token abilities with scoped permissions (no separate Passport dependency — reduces maintenance surface for a small number of Pro-tier integrators)

### 2.3 Multi-Tenancy
- **Strategy:** Schema-per-tenant via `stancl/tenancy`
- Each winery gets an isolated PostgreSQL schema
- Central schema holds: tenant registry, billing, VineBook directory data
- Tenant identification via subdomain (`wineryslug.yoursaas.com`) or API token header
- Benefits: clean data isolation, easier GDPR compliance, per-tenant backup/restore, no `WHERE winery_id = ?` on every query
- **Scaling ceiling note:** Schema-per-tenant works well up to ~500 tenants. Beyond that, migration runs (N schemas × M migrations) and database tooling (backups, monitoring, query debugging) become operationally painful. If tenant count exceeds this range, evaluate a hybrid approach (row-level tenancy for high-volume tables, schema isolation for sensitive data) or sharded PostgreSQL instances

### 2.4 Background Jobs (Laravel Horizon + Redis)
All long-running or asynchronous work is queued:
- Club processing batch charges
- TTB report auto-generation (monthly scheduled job)
- AI digest generation (weekly scheduled job per tenant)
- Email and SMS sends
- Shipping label generation
- QuickBooks / Xero sync
- Data import processing (onboarding)
- Webhook dispatches (Pro tier)
- Meilisearch index sync

Queue priority levels:
- `critical` — payment processing, auth
- `default` — orders, notifications
- `low` — reports, AI jobs, sync jobs

---

## 3. THE EVENT LOG (CORE DATA PATTERN)

This is the most important architectural decision in the entire system. **All winery operations are recorded as immutable events, not just state updates.**

> **Terminology note:** This is an append-only event log with materialized CRUD tables — not full event sourcing (no event replay, projector rebuilds, or CQRS). This is a pragmatic choice that gives us the audit trail, offline sync safety, and TTB aggregation benefits without the complexity overhead of a full event-sourced system.

### 3.1 Why the Event Log Pattern
- TTB reporting is inherently an aggregation of operations over time — the event log makes this a simple query
- Cellar offline sync becomes safe — mobile devices POST events, server aggregates them, ordering conflicts are resolved by `performed_at` timestamp
- Full audit log is free — every lot's history is its event stream
- Undo / correction is possible — append a correcting event rather than mutate history
- AI features are powered by event streams — trend analysis, pattern detection

### 3.2 Events Table Schema
```sql
CREATE TABLE events (
  id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  entity_type     VARCHAR(50) NOT NULL,  -- 'lot', 'vessel', 'barrel', 'inventory_item', 'order'
  entity_id       UUID NOT NULL,
  operation_type  VARCHAR(50) NOT NULL,  -- 'addition', 'transfer', 'rack', 'bottle', 'blend', 'sale'
  payload         JSONB NOT NULL,        -- all operation-specific data
  performed_by    UUID REFERENCES users(id),
  performed_at    TIMESTAMPTZ NOT NULL,  -- client timestamp (accurate for offline entries)
  synced_at       TIMESTAMPTZ,           -- server receipt timestamp (NULL until synced)
  device_id       VARCHAR(100),          -- identifies which client submitted (for conflict detection)
  idempotency_key VARCHAR(100) UNIQUE,   -- prevents duplicate event submission on retry
  created_at      TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_events_entity ON events(entity_type, entity_id);
CREATE INDEX idx_events_operation ON events(operation_type);
CREATE INDEX idx_events_performed_at ON events(performed_at);
```

### 3.3 Current State
Traditional CRUD tables (lots, vessels, inventory) represent **materialized views** of the event stream. They are kept in sync by event handlers. Direct mutation of these tables only happens for non-operational data (winery settings, user profiles, etc.).

### 3.4 Event Types Reference
```
Vineyard:     harvest_logged, spray_applied, sample_recorded, activity_logged
Cellar:       lot_created, lot_split, addition_made, transfer_executed,
              rack_completed, blend_finalized, barrel_filled, barrel_topped,
              barrel_racked, pressing_logged, fermentation_data_entered,
              bottling_completed, lab_analysis_entered
Inventory:    stock_received, stock_adjusted, stock_transferred, stock_counted
Sales:        order_placed, order_fulfilled, order_refunded, payment_captured,
              payment_failed, club_charge_processed, club_member_joined,
              club_member_cancelled, tasting_completed
Compliance:   ttb_report_generated, license_renewed, shipment_compliance_checked
```

---

## 4. THE MANAGEMENT PORTAL

### 4.1 Technology
- **Stack:** TALL (Tailwind CSS + Alpine.js + Laravel Livewire + Laravel)
- **Admin Scaffolding:** Filament v3 (tables, forms, filters, actions — saves significant build time). Pin to v3.x until v4 stabilizes; major Filament upgrades historically require non-trivial migration work
- **Rendering:** Server-side (Livewire), SEO not a concern here
- **Real-time:** Laravel Reverb + Livewire's `wire:poll` / Echo listeners for live dashboard updates
- **Deployment:** Same Laravel instance as the API (separate route groups, shared codebase)

### 4.2 What Lives Here
Everything in the back-of-house from `Task-Generation-Overview-Planning.md`:
- Full production management UI (lots, vessels, work orders, additions, bottling)
- Vineyard management
- Inventory management
- COGS and cost accounting
- TTB compliance and report generation
- Club processing and member management
- CRM and email marketing
- Reservations and event management
- Wholesale management
- Reporting and analytics dashboards
- AI digest and forecasting views (Pro)
- Settings, integrations, user management, billing

### 4.3 Real-time Features (Reverb)
- Work order completion by cellar hand → management portal updates live (no refresh)
- POS sale → inventory counts update live in portal
- Club processing progress bar during batch charge runs
- Fermentation dashboard showing live data as cellar app syncs

### 4.4 Filament Usage
Use Filament for: data tables, record forms, filter systems, bulk actions, navigation.
Build custom Livewire components for: the visual tank map, fermentation charts, TTB report review UI, club processing flow, POS-style interfaces.

---

## 5. THE CELLAR APP

### 5.1 Technology
- **Architecture:** Kotlin Multiplatform (KMP) shared core + native UI per platform
- **Shared Core (Kotlin):** Sync engine, local SQLite database (SQLDelight), API client (Ktor), business logic, event queue — written once, runs on both platforms
- **iOS UI:** SwiftUI (phone-optimized screens)
- **Android UI:** Jetpack Compose (phone-optimized screens)
- **Platforms:** iOS + Android (exploits InnoVint's iOS-only gap with truly native experience on both)
- **Local Database:** SQLite via SQLDelight (type-safe, multiplatform — the sync engine and POS app share this layer)
- **Sync Strategy:** Event log pattern (see Section 3)
- **Auth:** Sanctum token stored in platform keychain (iOS Keychain / Android EncryptedSharedPreferences)
- **Barcode/QR Scanning:** Native camera APIs (CameraX on Android, AVFoundation on iOS — no plugin abstraction layer to debug)
- **Offline Detection:** Native network monitoring (NWPathMonitor on iOS, ConnectivityManager on Android)

### 5.2 Offline Sync Architecture (Shared KMP Core)
```
┌─────────────────────────────────┐
│   Native UI (Compose/SwiftUI)   │
│                                 │
│  User performs operation        │
│         ↓                       │
│  ┌───────────────────────────┐  │
│  │    KMP Shared Core        │  │
│  │                           │  │
│  │  Write to local SQLite    │  │
│  │  (immediate UI feedback)  │  │
│  │         ↓                 │  │
│  │  Queue event in local     │  │
│  │  outbox table             │  │
│  │         ↓                 │  │
│  │  Background sync process  │  │
│  │  checks connectivity      │  │
│  │         ↓                 │  │
│  │  Online? POST event batch │  │
│  │  to /api/v1/events/sync   │  │
│  │         ↓                 │  │
│  │  Server confirms receipt  │  │
│  │  → mark events as synced  │  │
│  │  → pull latest state      │  │
│  └───────────────────────────┘  │
└─────────────────────────────────┘

This sync core is shared with the POS app (Section 6).
```

**Idempotency:** Every event has a client-generated UUID `idempotency_key`. If the same event is posted twice (e.g., retry after timeout), the server ignores the duplicate. Safe to retry aggressively.

**Conflict resolution:** Last-write-wins by `performed_at` timestamp for most operations. For volume-sensitive operations (gallons in a vessel), the server validates that the resulting state is physically possible (can't rack more gallons than a vessel contains) and returns a conflict error for manual resolution.

> **Clock drift caveat:** `performed_at` is a client timestamp. If two cellar hands have slightly drifted device clocks, ordering may be incorrect. Mitigations: (1) sync device time on app launch via NTP check and warn if drift exceeds 30 seconds, (2) for additive operations (e.g., "add 50g SO2 to lot X"), apply all concurrent offline adds rather than last-write-wins — two independent additions should both apply, (3) for destructive operations (transfer, rack), use the volume validation as a safety net and surface conflicts for manual resolution.

**Sync frequency:**
- Foreground: sync immediately on any operation when online
- Background: sync every 5 minutes when app is backgrounded and online
- On reconnect: immediate sync of entire outbox

### 5.3 What the Cellar App Does (and Only This)
- View and complete work orders
- Log additions (auto-deduct from local inventory cache)
- Record transfers between vessels
- Barrel operations (fill, top, rack, sample) with QR scan
- Lab analysis entry
- Fermentation data entry (temp, Brix, density)
- View lot history and current vessel contents
- View today's schedule / calendar
- Receive push notifications for new work orders

**Does NOT do:** Reporting, club management, payments, settings. Those are portal concerns.

### 5.4 Data Available Offline
The app syncs a local cache of:
- All active lots and their current state
- All vessels and current contents
- All pending and in-progress work orders
- Product/addition library
- Barrel registry
- User's own profile and permissions

Full historical data (years of lab analysis, complete event history) stays on the server — too large to cache locally. The app fetches historical data on-demand when online.

---

## 6. THE POS APP

### 6.1 Technology
- **Architecture:** Kotlin Multiplatform (KMP) shared core + native UI per platform — same shared core as the Cellar App (sync engine, local DB, API client, business logic)
- **iOS UI:** SwiftUI (tablet-optimized layout — product grid, cart, checkout flow)
- **Android UI:** Jetpack Compose (tablet-optimized layout)
- **Device:** iPad or Android tablet (installed from App Store / Play Store)
- **Payments:** Stripe Terminal native SDKs (iOS + Android) — tighter reader management, better error handling, and more reliable offline transaction queuing than the JS SDK
- **Local Database:** SQLite via SQLDelight (shared with Cellar App — same sync engine, same event queue pattern)
- **Offline:** Full native offline with local SQLite event queue + Stripe Terminal's native offline payment capture (up to ~$5,000 in offline transactions per reader)
- **Receipt Printing:** Native Bluetooth/USB printer SDKs (Star Micronics, Epson) — more reliable than WebUSB

### 6.2 Why Native and Not PWA
- **Offline reliability is the selling point.** Rural tasting rooms (Paso backcountry, Adelaide Hills, etc.) lose connectivity 5-6 times a year. Each outage costs hundreds in comped food and lost wine sales. "Our POS keeps taking cards when your wifi drops" is the single-sentence pitch.
- Stripe Terminal native SDKs provide tighter reader hardware integration, better error codes, and higher offline transaction limits than the JS SDK
- Native offline with SQLite + event queue is battle-tested and deterministic — no service worker lifecycle edge cases
- The KMP shared core is already built for the Cellar App — the POS reuses the sync engine, local DB layer, and API client. The marginal cost of going native for POS is low.
- Native printer integration is more reliable than WebUSB (which has inconsistent browser support)
- App Store distribution gives the POS a professional presence and managed update channel

### 6.3 POS Offline Behavior
- **Product catalog:** Cached in local SQLite on app launch. Works fully offline with no degradation.
- **Card payments:** Stripe Terminal native SDK queues offline payments in the reader hardware and syncs automatically when connectivity returns. Reader can store significant transaction volume offline.
- **Cash payments:** Recorded to local SQLite event queue, synced via the shared KMP sync engine when online
- **Club signups during offline:** Queued in local event log, card-on-file capture deferred until connectivity returns (member record created immediately, payment method flagged as pending)
- **Inventory deduction:** Optimistic local deduction, reconciled with server on sync

### 6.4 What the POS Does (and Only This)
- Product catalog browsing and cart building
- Tab management (open, transfer, close)
- Split payments
- Stripe Terminal card-present payments
- Cash payments with drawer tracking
- Wine club signup with card-on-file capture
- Reservation check-in
- Tasting fee waiver on purchase threshold
- Member discount auto-application on card swipe
- Receipt email / print / skip
- End-of-day cash drawer reconciliation
- Shift open/close

---

## 7. VINEBOOK DIRECTORY

### 7.1 Technology
- **Framework:** Astro (static site generator with island architecture)
- **Hosting:** Cloudflare Pages (free tier handles massive scale, global CDN)
- **Data Source:** Build-time fetch from Laravel API (static pages) + runtime API calls (subscriber islands)
- **Styling:** Tailwind CSS
- **Search:** Algolia or Meilisearch-powered instant search widget (Astro island)

### 7.2 Page Architecture

**Non-subscriber winery page** (fully static, CDN cached):
```
vinebook.com/wineries/[slug]
├── Rendered at build time from Laravel API
├── Data: TTB data + Yelp API + Google Places API
├── No JavaScript islands
├── Redeployed on a schedule (nightly build) to catch updates
└── Cloudflare cache TTL: 24 hours
```

**Subscriber winery page** (hybrid static + islands):
```
vinebook.com/wineries/[slug]  (same URL, different template)
├── Static shell: winery info, photos, Yelp reviews (CDN cached)
└── Astro Islands (hydrated client-side → Laravel API calls):
    ├── <ShopWidget>         → /api/v1/public/wineries/{slug}/products
    ├── <BookingWidget>      → /api/v1/public/wineries/{slug}/availability
    ├── <ClubSignupWidget>   → /api/v1/public/wineries/{slug}/club
    └── <MemberPortalWidget> → /api/v1/public/wineries/{slug}/member (auth required)
```

**Regional landing pages** (fully static, SEO targets):
```
vinebook.com/regions/paso-robles
vinebook.com/regions/napa-valley
vinebook.com/regions/sonoma
vinebook.com/varieties/cabernet-sauvignon
vinebook.com/varieties/zinfandel
```

### 7.3 Data Population Strategy
1. **Seed script:** Import all ~11,000 US bonded wineries from TTB public permit database
2. **Enrichment job (scheduled, staggered):**
   - Yelp Fusion API: hours, rating, review count, photos, categories
   - Google Places API: address normalization, additional photos, website URL
   - Wine-Searcher API (where available): wines listed, vintages, prices
   - **Caching strategy:** Enrichment data is cached in the central database and refreshed on a rolling schedule (not per-user-view). Each winery's external data refreshes every 30 days. API calls are staggered across the month (~370 wineries/day) to stay within free-tier rate limits (Yelp Fusion: 5,000 calls/day). Google Places calls are batched and budgeted (~$50-100/month at scale). Stale data is served until refresh completes — external enrichment never blocks page renders.
3. **Subscriber data:** Served live from Laravel API via Astro islands, never cached in the static build
4. **Claim flow:** Winery owner claims stub → verifies via TTB permit number or business email → gets enhanced free profile → upsell to suite

### 7.4 SEO Strategy
- One page per winery (11,000 pages, each targeting branded search)
- Regional pages targeting "wineries in [region]" queries
- Variety pages targeting "[variety] wineries" queries
- Auto-generated `sitemap.xml` submitted to Google Search Console on each deploy
- `robots.txt` allows full crawl
- Structured data (JSON-LD) on every page: `LocalBusiness`, `Winery`, `Product` schemas
- Page speed: static HTML from Cloudflare edge = near-instant TTFB = strong Core Web Vitals

---

## 8. EMBEDDABLE WIDGETS

### 8.1 Concept
A single JavaScript snippet that winery subscribers paste into any website (Squarespace, WordPress, Wix, custom HTML). The snippet loads the appropriate widget based on a `data-widget` attribute.

```html
<!-- Store Widget -->
<script src="https://cdn.yoursaas.com/widgets/v1.js"
        data-winery="paso-robles-cellars"
        data-widget="store"
        data-theme='{"primary":"#8B1A1A","font":"Playfair Display"}'>
</script>

<!-- Reservations Widget -->
<script src="https://cdn.yoursaas.com/widgets/v1.js"
        data-winery="paso-robles-cellars"
        data-widget="reservations">
</script>
```

### 8.2 Widget Types
| Widget | Function | API Endpoint |
|---|---|---|
| `store` | Browse wines, add to cart, Stripe checkout | `/api/v1/public/{slug}/store` |
| `reservations` | Booking calendar, experience selection, payment | `/api/v1/public/{slug}/reservations` |
| `club-signup` | Club tier selector, card capture, member creation | `/api/v1/public/{slug}/club/join` |
| `member-portal` | Member self-service (auth required) | `/api/v1/public/{slug}/member` |

### 8.3 Widget Architecture
- Widgets are **Web Components** (native browser standard, no framework dependency, no conflicts with host site's JS)
- Loaded from Cloudflare CDN (`cdn.yoursaas.com`)
- Render into a Shadow DOM (fully isolated from host site CSS — no style conflicts)
- Communicate with Laravel API directly via authenticated public endpoints
- Scoped public API keys per winery (set in Management Portal, embedded in the script tag)
- Theming via `data-theme` JSON attribute (primary color, font) — winery makes it feel native to their site
- **Rate limiting:** Widget public API endpoints have per-key, per-origin throttling (e.g., 60 requests/minute per API key per origin domain). Prevents abuse from competitors or bots without impacting legitimate customer traffic. Rate limit headers returned on every response (`X-RateLimit-Remaining`)

### 8.4 What Widgets Are NOT
- Not the full management portal in an iframe
- Not dependent on VineBook
- They work on any winery website regardless of whether the winery is on VineBook

---

## 9. PAYMENTS ARCHITECTURE

### 9.1 Two-Mode Payment System

**Mode A: Managed (Default)**
- Winery signs up → Stripe Connect onboarding (takes 5 minutes)
- All payments flow through your Stripe platform account
- Your platform fee (configured %) auto-deducted on each transaction
- Winery receives net amount on their Stripe payout schedule
- Winery sees transactions in their Stripe Express dashboard
- No extra setup required — works out of the box

**Mode B: BYO Processor (Growth + Pro)**
- Winery connects their own Stripe account (or Square, Braintree) via API keys in settings
- A `PaymentProcessor` service interface in Laravel abstracts the processor:
  ```php
  interface PaymentProcessor {
    public function charge(PaymentIntent $intent): ChargeResult;
    public function refund(string $chargeId, int $amount): RefundResult;
    public function saveCard(Customer $customer, string $token): StoredCard;
    public function chargeStoredCard(StoredCard $card, int $amount): ChargeResult;
  }
  ```
- Concrete implementations: `StripeProcessor`, `SquareProcessor`, `BraintreeProcessor`
- Winery pays flat SaaS subscription only — no transaction fee taken
- Hardware (card readers) must be compatible with chosen processor

### 9.2 Card-on-File (Wine Club)
- Cards tokenized and stored at processor level — PCI compliant, never stored in your DB
- Your DB stores: processor reference token, last 4 digits, expiry, card type
- Club charge job iterates members → retrieves token → charges via processor abstraction
- Failed charge → enqueued for retry (3 attempts over 7 days) → member notification

### 9.3 Stripe Terminal (POS)
- Stripe Terminal native SDKs (iOS + Android) in the POS app
- Supports: BBPOS WisePOS E, Stripe Reader S700 (both recommended to winery)
- Offline mode: reader stores offline transactions locally, syncs on reconnect
- EMV chip, contactless (Apple Pay, Google Pay), swipe

---

## 10. INTEGRATIONS ARCHITECTURE

### 10.1 Integration Patterns

All third-party integrations follow one of two patterns:

**Push pattern (outbound):** Laravel event listener fires → job queued → integration service called
Example: Order placed → `OrderPlaced` event → `SyncToQuickBooksJob` queued → QuickBooks API called

**Pull pattern (inbound):** Webhook received from third party → validated → event dispatched → handled
Example: Stripe webhook `payment_intent.succeeded` → validated → `PaymentCaptured` event → inventory deducted

### 10.2 Integration Registry

| Integration | Tier | Pattern | Purpose |
|---|---|---|---|
| Stripe | All | Both | Payments, subscriptions, Terminal |
| QuickBooks Online | Growth | Push | COGS sync, invoice sync, chart of accounts |
| Xero | Growth | Push | Same as QuickBooks (alternative) |
| Sovos ShipCompliant | Growth | Push + Pull | DTC compliance filing, state rules |
| Mailchimp | Growth | Push | Contact sync, segment export |
| Klaviyo | Pro | Push + Pull | Advanced email/SMS, flow triggers |
| FedEx API | Growth | Push | Shipping labels, rate shopping |
| UPS API | Growth | Push | Shipping labels, rate shopping |
| Twilio | Growth | Push | SMS notifications |
| Yelp Fusion API | VineBook | Pull | Directory enrichment |
| Google Places API | VineBook | Pull | Directory enrichment |
| Resend | All | Push | Transactional email |
| Anthropic API | Pro | Push | AI digest, forecasting (background job) |
| ETS Labs | Optional | Pull | Lab analysis CSV import |
| Vivino | Pro | Push | Product listing sync |

### 10.3 Webhook Infrastructure (Pro Tier)
- Winery configures webhook endpoints in Management Portal
- Events selectable per webhook (order placed, club charge, lot created, etc.)
- HMAC signature on every outbound webhook payload (same pattern as Stripe)
- Delivery log with retry history visible in portal
- Failed webhooks retried with exponential backoff (5 attempts)

---

## 11. AI FEATURES ARCHITECTURE

### 11.1 Principle
AI features are **background jobs, never real-time.** No winery operation blocks on an AI call. Token costs are controlled by running AI jobs on a schedule, not on-demand per user action.

### 11.2 Implementation Pattern
```
Scheduled job fires (e.g., Sunday night, weekly digest)
    ↓
Job queries tenant's event log + aggregated data
    ↓
Builds structured context prompt (not raw DB dumps — summarized stats)
    ↓
POST to Anthropic API (claude-sonnet-4-6 — cost/quality balance)
    ↓
Parse response
    ↓
Store result in `ai_digests` table
    ↓
Push notification to owner: "Your weekly digest is ready"
    ↓
Owner opens portal → reads pre-generated digest
```

### 11.3 AI Features (Pro Tier)
- **Weekly Business Digest:** Natural language summary of sales trends, inventory movements, club health, anomalies. Generated Sunday night, ready Monday morning.
- **Demand Forecasting:** Estimates per-SKU sell-through for next 90 days based on sales velocity and seasonal patterns. Runs monthly.
- **Harvest Timing Suggestions:** Correlates historical Brix/pH curves with picking dates and resulting wine quality scores. Runs during growing season.
- **Fermentation Dry-Down Prediction:** Given current fermentation curve, predicts days to dryness. Runs daily during active ferments for Pro tenants.
- **Club Churn Risk Scoring:** Scores each active member's churn probability based on engagement patterns. Runs weekly. Flags high-risk members in CRM.
- **Margin Optimization Flags:** Identifies SKUs where selling price is within 20% of COGS — suggests price review.

### 11.4 Cost Control
- AI jobs only run for Pro tier tenants
- Context prompts are pre-aggregated stats (not raw rows) — keeps token count low
- Results cached for 7 days — re-running within the week returns cached result
- Per-tenant weekly token budget enforced — if a tenant's digest would exceed budget, prompt is trimmed
- AI job failures are silent to the user (digest just doesn't appear that week) — never blocks core functionality

---

## 12. INFRASTRUCTURE & DEPLOYMENT

### 12.1 Stack
```
Servers:          Hetzner Cloud (significantly cheaper than AWS for equivalent specs)
                  OR DigitalOcean if Hetzner's US regions are too limited
Server Management: Laravel Forge (provisions servers, manages deployments, SSL, cron)
CDN / DNS:        Cloudflare (free tier covers everything needed at early stage)
Static Hosting:   Cloudflare Pages (VineBook — free, global CDN)
Widget CDN:       Cloudflare R2 + CDN (widget.js served globally)
Database:         PostgreSQL on managed DB (Hetzner Managed Databases or Supabase)
Redis:            Upstash (managed, serverless Redis — cheap at low scale, scales up)
Search:           Meilisearch on its own small server (via Forge)
Monitoring:       Laravel Telescope (dev) + Sentry (prod errors) + Better Uptime
Log Management:   Laravel Pail (dev) + Papertrail or Logtail (prod)
```

### 12.2 Server Architecture (Early Stage)
```
server-01 (Hetzner CX32 ~$16/mo)
  ├── Laravel API + Management Portal
  ├── Laravel Horizon (queue worker)
  └── Laravel Reverb (WebSockets)

server-02 (Hetzner CX22 ~$8/mo)  
  └── Meilisearch

Hetzner Managed DB (~$20/mo)
  └── PostgreSQL (primary + replica)

Upstash Redis (~$0-10/mo at low scale)

Cloudflare Pages (free)
  └── VineBook (Astro static site)

App Stores
  ├── Cellar App (iOS App Store + Google Play)
  └── POS App (iOS App Store + Google Play)
```

Total infrastructure: ~$50-60/month to start. Scales horizontally when needed — Forge makes adding servers and load balancing straightforward.

### 12.3 Testing Strategy

TTB compliance is safety-critical code — testing is not optional.

- **Unit tests (PHPUnit):** All business logic — event handlers, volume calculations, TTB aggregation, cost rollups, blend composition calculations. Target 90%+ coverage on the compliance and event log modules.
- **Integration tests (PHPUnit):** Full request lifecycle tests for API endpoints — auth, tenant isolation, event creation → materialized state update → TTB report generation. Test against real PostgreSQL (not SQLite).
- **KMP shared core tests (Kotlin):** Unit tests for the sync engine, event queue, conflict resolution, and API client. These run on JVM — fast, no emulator needed. This is the most critical native test suite since both apps depend on it.
- **Native UI tests:** Android instrumented tests (Compose) + iOS XCTest (SwiftUI) for core flows — work order completion, POS checkout, barrel scan. Run on CI emulators/simulators.
- **Load testing (k6 or Artillery):** Simulate busy tasting room Saturday — concurrent POS transactions, cellar sync, and portal usage. Run before harvest season annually.
- **TTB verification tests:** Compare auto-generated TTB reports against known-good reports from real winery data (anonymized fixtures). This is the single most important test suite in the system.

### 12.4 Deployment Pipeline
```
Developer pushes to main branch
    ↓
GitHub Actions runs:
  - PHPUnit test suite (unit + integration)
  - Laravel Pint (code style)
  - PHPStan (static analysis)
  - KMP shared core tests (JVM)
  - Android build + instrumented tests (Cellar + POS)
  - iOS build + XCTest (Cellar + POS)
  - Astro build (VineBook)
    ↓
Tests pass → Forge webhook triggers API deployment
    ↓
Zero-downtime deploy (Laravel Envoyer pattern via Forge)
  - New release symlinked
  - Migrations run
  - Cache cleared
  - Horizon restarted
  - Old release kept for 5 deploys (rollback available)
    ↓
VineBook: Cloudflare Pages auto-deploys on Astro build success
Cellar + POS apps: Fastlane → TestFlight (iOS) + Google Play Internal Track (Android)
```

### 12.5 Multi-Tenancy Deployment Notes
- Each tenant's PostgreSQL schema is migrated independently via `stancl/tenancy` artisan commands
- New tenant provisioning is a queued job (create schema → run migrations → seed defaults → send welcome email) — takes ~10 seconds, runs async
- Tenant data never shares tables with other tenants (schema isolation)
- Central `tenants` table in the public schema: tenant ID, slug, plan, Stripe customer ID, created_at

### 12.6 Backups
- PostgreSQL: automated daily snapshots via Hetzner Managed DB (retained 14 days)
- R2 file storage: Cloudflare R2 versioning enabled
- Per-tenant data export: winery owner can trigger full data export (JSON + CSV) from Management Portal at any time — satisfies GDPR data portability

---

## 13. DEVELOPMENT ENVIRONMENT

### 13.0 Development Machine
- **Hardware:** Mac Mini M2
- **Implications:** Apple Silicon runs Docker via Rosetta or native ARM images (all images in our Compose stack have ARM builds — Postgres, Redis, Meilisearch, PHP all ship `linux/arm64`). Xcode runs natively for iOS/SwiftUI development. Android Studio + Android emulator run natively on Apple Silicon. KMP/Gradle builds benefit from the M2's unified memory and performance cores. This machine handles the full stack (Docker + Android Studio + Xcode simultaneously) comfortably.

### 13.1 Local Development (Docker Compose)
This is where 90% of development happens. Zero cloud cost.

```yaml
# docker-compose.yml (simplified)
services:
  app:
    build: ./api
    ports:
      - "0.0.0.0:8000:80"    # 0.0.0.0 so phones on local network can reach it
    volumes:
      - ./api:/var/www/html
    depends_on:
      - postgres
      - redis
      - meilisearch

  postgres:
    image: postgres:16
    ports:
      - "5432:5432"
    environment:
      POSTGRES_DB: vinesuite
      POSTGRES_USER: vinesuite
      POSTGRES_PASSWORD: secret
    volumes:
      - pgdata:/var/lib/postgresql/data

  redis:
    image: redis:7-alpine
    ports:
      - "6379:6379"

  meilisearch:
    image: getmeili/meilisearch:v1
    ports:
      - "7700:7700"
    volumes:
      - msdata:/meili_data

  mailpit:
    image: axllent/mailpit
    ports:
      - "8025:8025"     # Web UI to view caught emails
      - "1025:1025"     # SMTP

  horizon:
    build: ./api
    command: php artisan horizon
    volumes:
      - ./api:/var/www/html
    depends_on:
      - redis

volumes:
  pgdata:
  msdata:
```

**Start everything:** `docker compose up -d`
**Run migrations:** `docker compose exec app php artisan migrate`
**Run tests:** `docker compose exec app php artisan test`
**View caught emails:** `http://localhost:8025`

### 13.2 Native App Development
Both run locally alongside Docker — no cloud dependency.

- **Android (Cellar + POS):** Android Studio → open `shared/` as the KMP module root → run on emulator or physical device via USB. Point API base URL at your machine's local IP (e.g., `http://192.168.1.XXX:8000`).
- **iOS (Cellar + POS):** Xcode → open the iOS project that depends on the shared KMP framework → run on simulator or physical device. Same local IP for API.
- **KMP shared core tests:** Run directly from Android Studio or command line (`./gradlew :shared:jvmTest`) — executes on JVM, no emulator needed, fast feedback.

### 13.3 Testing on Physical Devices

**On the same wifi as your dev machine:**
Phone/tablet hits your Docker Laravel instance directly via local IP. No tunneling, no external server. Just make sure Docker binds to `0.0.0.0` (shown above) so it listens on all network interfaces.

```
Your laptop (192.168.1.100)
  └── Docker: Laravel on port 8000
        ↑
Your phone on same wifi
  └── App API base URL: http://192.168.1.100:8000
```

**Off-network (testing cellular, demoing at a winery):**
Use a Cloudflare tunnel or ngrok pointed at your local Docker instance. Gives you a public HTTPS URL your phone can reach from anywhere. Free tier on either.

```bash
# Cloudflare tunnel (free, no account needed for quick tunnels)
cloudflared tunnel --url http://localhost:8000

# ngrok (free tier)
ngrok http 8000
```

This gives you a URL like `https://abc123.trycloudflare.com` that your TestFlight app can hit from a cellar with zero wifi — over cellular — while your Docker stack runs on your laptop at the office.

### 13.4 HTTPS for Local Development
Stripe Terminal and Sanctum both prefer HTTPS. Two options:

- **Quick and dirty (recommended for early dev):** Allow HTTP in your local Sanctum config and Stripe test mode. Toggle `SANCTUM_STATEFUL_DOMAINS` and `SESSION_DOMAIN` for local IP. Stripe Terminal test mode works over HTTP.
- **Proper local HTTPS:** Use `mkcert` to generate locally-trusted certificates. Install the root CA on your dev devices. Configure Nginx in the Docker container to serve over HTTPS with the mkcert cert. More setup, but matches production behavior exactly.

Use the quick option until you're testing Stripe Terminal on real hardware. Switch to mkcert when you need it.

### 13.5 Staging Environment (When You're Ready to Demo)
Skip Lightsail — go straight to Forge when you need a persistent staging server.

- **Server:** Hetzner CX22 (~$8/month) or CX32 (~$16/month), provisioned via Forge
- **Setup:** Same Docker Compose stack, or let Forge provision Laravel natively (Forge handles Nginx, PHP, Redis, PostgreSQL, SSL — same as production)
- **Domain:** `staging.yoursaas.com` with Cloudflare DNS
- **Purpose:** Stable URL for TestFlight/Play Store Internal Track builds, winery demos, and testing the native apps against a real server
- **When to create it:** When you're ready to put the Cellar App in a real cellar worker's hands (Phase 5 milestone). Not before.

### 13.6 Environment Parity
Keep local Docker as close to production as possible:
- Same PostgreSQL version (16)
- Same Redis version (7)
- Same PHP version (8.4)
- Same Meilisearch version
- Use `.env.local` for Docker-specific overrides, `.env.staging` for staging, `.env.production` for prod — never share credentials across environments
- Seed data: maintain a `DatabaseSeeder` that creates a demo winery tenant with realistic lots, vessels, work orders, and inventory. This is what you run in Docker for development and what you demo to winemakers.

---

## 14. SECURITY

- All traffic HTTPS via Cloudflare (automatic SSL)
- API tokens scoped per client and per permission set
- Tenant schema isolation — a compromised token cannot cross tenant boundaries
- PII encrypted at rest (customer emails, phone numbers, card-last-4 via Laravel's `encrypted` cast)
- Payment card data never stored — processor tokens only (PCI SAQ-A compliant)
- CORS locked to known origins per API key (widget API keys only accept requests from registered winery domains)
- Rate limiting on all public endpoints (login, widget API calls, checkout)
- Automated dependency scanning via GitHub Dependabot
- Laravel's CSRF protection on all web forms
- SQL injection protection via Eloquent ORM (no raw queries except for reporting aggregations, which are parameterized)

---

## 15. DEVELOPMENT SEQUENCE

Build order optimized for earliest revenue and validated learning. Each phase ends with a sellable milestone or a validated decision point. Do not start the next phase until the current one is generating revenue or confirmed by real winery feedback.

### Phase 1 — Foundation (~2 weeks)
Everything downstream depends on this. No feature work until it's solid.

1. Laravel 12 project setup with multi-tenancy (`stancl/tenancy`)
2. PostgreSQL schema design (core tables + event log table)
3. Authentication (Sanctum, roles, permissions, RBAC)
4. Tenant provisioning flow (queued job: create schema → migrate → seed defaults)
5. Stripe billing integration (SaaS subscription checkout + webhook handling)
6. Basic Management Portal shell (Filament, navigation, layout, tenant switching)
7. CI/CD pipeline (GitHub Actions → Forge → zero-downtime deploy)

### Phase 2 — Production Module + Portal (~6-8 weeks)
The core product that justifies Starter tier. Build it, demo it, iterate with local winemakers.

8. Lot management (CRUD + event log writes for every operation)
9. Vessel management (tanks, barrels, current contents tracking)
10. Work orders (create, assign, complete — the daily cellar workflow)
11. Additions log (SO2, nutrients, fining — with auto-deduct from supplies inventory)
12. Lab analysis entry (pH, TA, VA, Brix, SO2 — table + chart views per lot)
13. Fermentation tracking (daily data entry, fermentation curve chart)
14. Basic inventory (bulk wine gallons by lot/vessel + case goods SKUs)

**Milestone: Demo to 2-3 winemakers in Paso. Validate data model against their real workflow.**

### Phase 3 — TTB Compliance (~2-3 weeks)
This is why wineries pay. Ship it right or don't ship it.

15. TTB Form 5120.17 auto-generation from event log data
16. Report review UI (winemaker verifies numbers before filing)
17. PDF export for filing
18. Historical report archive
19. TTB verification test suite (compare against known-good real reports)

**Milestone: Generate a correct 5120.17 from a friendly winery's real data. If numbers match, you have a product.**

### Phase 4 — KMP Shared Core (~4-6 weeks)
The hardest engineering in the project. Dedicated focus, no distractions.

20. SQLDelight database schema (local mirror of lots, vessels, work orders, additions, barrel registry)
21. Event queue (local outbox table, idempotency keys, retry logic)
22. Sync engine (POST event batch → server confirm → pull latest state → mark synced)
23. Ktor API client (auth, token refresh, batch sync endpoint)
24. Conflict resolution logic (additive ops apply all, destructive ops validate volume, clock drift NTP check)
25. Exhaustive JVM test suite for the shared core — this is the foundation both apps stand on

### Phase 5 — Cellar App (~3-4 weeks)
Native UI on top of the shared core. Keep it minimal — just enough for a cellar hand to get through a shift.

26. Cellar App — Android (Jetpack Compose, phone layout)
27. Cellar App — iOS (SwiftUI, phone layout)
28. Core screens: work order list → complete work order, log addition, record transfer, barrel QR scan, lab data entry
29. Offline testing: airplane mode in a real cellar, complete 10 work orders, reconnect, verify sync

**Milestone: Put it in the hands of an actual cellar worker. Watch them use it. Fix what's awkward.**

### — SELL IT HERE —
**Portal + Cellar App + TTB reports = shippable Starter product at $99/month.**
Get 5 paying customers before writing another line of feature code. Everything after this should be funded by revenue or validated by customer requests.

### Phase 6 — POS App (~3-4 weeks)
Reuses the KMP shared core. Marginal effort is tablet UI + Stripe Terminal integration.

30. POS App — Android (Jetpack Compose, tablet layout)
31. POS App — iOS (SwiftUI, tablet layout)
32. Product grid, cart, tab management, split payments
33. Stripe Terminal native SDK integration (reader discovery, card-present payments, offline capture)
34. Cash payments with drawer tracking
35. Receipt printing (Star Micronics / Epson native SDKs)
36. End-of-day reconciliation, shift open/close
37. Offline stress test: disconnect wifi, process 10 card + cash transactions, reconnect, verify everything syncs and settles

**Milestone: This unlocks Growth tier. "Keeps taking cards when your wifi drops" is the lead pitch.**

### Phase 7 — Growth Tier Features (~8-12 weeks, prioritized by customer demand)
Build these in the order your paying customers are asking for them.

38. Wine club management + batch processing (the biggest pain point after TTB)
39. eCommerce storefront (Livewire, hosted at shop.winery.com)
40. Reservations system + public booking widget
41. Embeddable widgets (store, reservations, club signup, member portal)
42. CRM basics (unified customer profiles, segmentation, purchase history)
43. QuickBooks / Xero integration (two-way sync)
44. Vineyard module (blocks, sampling, spray logs, harvest)

### Phase 8 — Pro Tier + VineBook (build when Growth revenue justifies it)

45. AI features (weekly digest, demand forecasting, churn scoring — Anthropic API background jobs)
46. Multi-brand / multi-winery support
47. Wholesale / distributor portal
48. Public API + webhooks (Sanctum scoped tokens, OpenAPI docs)
49. VineBook directory (Astro, TTB seed data, Yelp/Google enrichment, claim flow)
50. Advanced automation rules (if/then engine)
51. Migration Workbench refinement → self-serve onboarding path

---

## 16. KEY ARCHITECTURAL DECISIONS SUMMARY

| Decision | Choice | Rationale |
|---|---|---|
| Backend | Laravel 12 (PHP 8.4+) | Developer's existing expertise, excellent ecosystem, queue/job/websocket support built-in |
| Database | PostgreSQL | JSONB for event payloads, better reporting query performance, row-level security |
| Multi-tenancy | Schema-per-tenant | Clean isolation, easier compliance, no cross-tenant query risk |
| Data pattern | Append-only event log + materialized state | Free audit log, offline sync safety, TTB reporting as aggregation (not full event sourcing — no replay/CQRS) |
| Web frontend | TALL stack (Livewire + Alpine) | Developer's expertise, no unnecessary JS framework overhead |
| Admin scaffolding | Filament v3 (pinned) | Accelerates complex table/form UI by 60%; upgrade to v4 when stable |
| Mobile (Cellar) | KMP + native UI (Compose/SwiftUI) | Shared Kotlin core for sync engine + DB, truly native UX on both platforms, exploits InnoVint iOS-only gap, native camera APIs for barrel QR scanning |
| Mobile (POS) | KMP + native UI (Compose/SwiftUI) | Reuses cellar app's shared core (sync, DB, API client), native Stripe Terminal SDKs for reliable offline payments, native printer support, "keeps taking cards when wifi drops" selling point |
| Directory site | Astro | Content-first, island architecture perfect for static + dynamic hybrid, near-zero hosting cost on Cloudflare Pages |
| Widgets | Web Components | Framework-agnostic, Shadow DOM isolation, works on any winery website |
| Payments | Stripe Connect + abstraction layer | Managed default lowers friction, BYO option removes transaction fee objection |
| AI | Anthropic API — claude-sonnet-4-6 (background jobs) | Pro-tier gate controls token costs, never blocks core operations |
| Hosting | Hetzner + Forge | 3-4x cheaper than AWS at early stage, Forge removes DevOps complexity |
| CDN | Cloudflare | Free tier is genuinely excellent, handles DDoS, caching, SSL |
