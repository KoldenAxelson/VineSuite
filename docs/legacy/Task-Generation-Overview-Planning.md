# Task-Generation-Overview-Planning.md
# Winery SaaS — Comprehensive Feature Overview
> This document is a master feature reference to be used for generating granular task files.
> Organized by module. Each feature is written as a discrete, implementable unit.
> Pricing tiers: [STARTER] [GROWTH] [PRO]

---

## 1. AUTHENTICATION & ACCOUNTS

- User registration with email + password
- Email verification on signup
- Password reset via email link
- OAuth login (Google)
- Multi-factor authentication (MFA) via TOTP app [GROWTH]
- Role-based access control (RBAC) system
  - Roles: Owner, Admin, Winemaker, Cellar Hand, Tasting Room Staff, Accountant, Read-Only
  - Per-role permission matrix for every module
- Session management with configurable timeout
- Invite-based team member onboarding (owner sends invite link)
- Per-user activity log (who did what, when)
- Account deactivation without data deletion
- Multi-winery / multi-brand support under one login [GROWTH]
  - Switch between brands via dropdown
  - Separate data namespacing per winery entity
  - Consolidated owner-level reporting across brands [PRO]

---

## 2. WINERY SETUP & ONBOARDING

- Winery profile: name, address, license numbers, TTB permit number
- Bond type tracking (Bonded Winery, Custom Crush Facility, etc.)
- Multiple facility/location setup (bonded winery, offsite storage, custom crush)
- Fiscal year configuration
- Unit preferences (gallons vs. liters, cases vs. bottles)
- Label/brand management (one winery can produce multiple labels)
- Grower/vendor contact book
- Data import wizard
  - Import from vintrace (CSV/export)
  - Import from InnoVint (CSV/export)
  - Import from spreadsheet templates (provided by app)
- Onboarding checklist UI — guided setup progress tracker
- Demo/sandbox mode with pre-populated winery data

---

## 3. VINEYARD MANAGEMENT

### 3.1 Block & Variety Mapping
- Vineyard block creation with: name, acreage, variety, clone, rootstock, row spacing, vine count, year planted
- Sub-block support
- Map/diagram upload or basic visual block layout builder
- Block status tracking (active, replanted, fallow, removed)
- Multi-vineyard support (estate + purchased fruit sources)
- Grower contract linkage per block

### 3.2 Seasonal Activity Logging
- Activity types: pruning, suckering, shoot thinning, leaf pulling, green harvest, canopy management, irrigation, cover crop, harvest
- Activity log entry: date, block, workers, hours, notes, inputs used
- Recurring activity scheduling
- Work order generation from vineyard activities (links to cellar work order system)
- Labor cost tracking per activity

### 3.3 Sampling & Analytics
- Brix/pH/TA/YAN sampling entry per block per date
- Multi-sample averaging
- Trend chart: Brix curve over the season per block
- Target range indicators (configurable per block/variety)
- Sampling history export

### 3.4 Spray & Chemical Application Log
- Application log: date, block, chemical/product, rate, total applied, applicator, equipment, PHI (pre-harvest interval)
- Chemical product library with REI/PHI defaults
- SDS document attachment per chemical
- Organic certification mode — flags non-approved inputs
- Application report generation (for audits, certifications)

### 3.5 Harvest Management
- Harvest event creation per block: date, picker crew, start/end time
- Actual vs. projected tonnage tracking
- Fruit condition notes (botrytis %, sorting notes)
- Grape intake form generation → auto-creates lot in cellar module
- Grower delivery receipts with tonnage, variety, block, Brix at pickup
- Grower payment calculation based on contract terms (price/ton, Brix adjustments)

---

## 4. CELLAR / PRODUCTION MANAGEMENT

### 4.1 Lot Management
- Lot creation: variety, vintage, source (block or purchased), volume (gallons), status
- Lot naming convention templates (customizable)
- Full lot history — every operation logged in chronological timeline
- Lot splitting (one lot → multiple child lots)
- Lot merging / blending (multiple lots → one new lot)
- Lot status: In Progress, Aging, Finished, Bottled, Sold, Archived
- Lot search and filter by variety, vintage, vessel, status

### 4.2 Vessel Management
- Vessel types: tank, barrel, flexitank, tote, demijohn, concrete egg, amphora
- Vessel register: name/ID, type, capacity, material, location, purchase date, notes
- Visual tank map / cellar layout (drag-and-drop vessel placement) [GROWTH]
- Current contents display per vessel (lot name, volume, fill %)
- Vessel status: In Use, Empty, Cleaning, Out of Service
- Barrel tracking: cooperage, toast level, oak type, age (years used), forest origin
- Barrel ID via barcode/QR label printing and scanning

### 4.3 Work Orders
- Work order creation: operation type, assigned lots, assigned vessels, due date, assignee(s)
- Operation types library (configurable): Pump Over, Punch Down, Rack, Add SO2, Fine, Filter, Transfer, Top, Sample, Barrel Down, Press, Inoculate, etc.
- Work order templates for recurring operations
- Mobile-first work order completion UI (cellar hand taps complete on phone)
- Bulk work order creation (same operation across multiple lots/vessels)
- Work order status: Pending, In Progress, Completed, Skipped
- Completion notes and actual vs. scheduled time tracking
- Work order calendar view (what's due today / this week)
- Offline mode: queue completions locally, sync when back online

### 4.4 Additions & Inventory Deduction
- Additions log: date, lot, addition type, product name, rate, total amount, reason
- Addition product library with default rates and units
- Auto-deduct addition from dry goods / raw materials inventory
- SO2 tracking with running total per lot (free SO2 analysis inputs)
- Nutritional additions (DAP, Fermaid, etc.) with yeast-available nitrogen (YAN) targets
- Fining agent log with trial notation support

### 4.5 Fermentation Tracking
- Fermentation rounds per lot: inoculation date, yeast strain, target temp, nutrients schedule
- Daily data entry: date, temp, Brix/density, free SO2, notes
- Fermentation curve chart (temp + Brix over time)
- Dry-down prediction (estimated days to dryness based on curve) [PRO AI]
- Malolactic fermentation tracking: inoculation, completion, confirmation date

### 4.6 Blending
- Blend trial creation: select source lots, input percentages, calculate volumes
- Multiple trial versions per blend (save and compare)
- Blend sensory/tasting notes per trial
- Finalize blend → creates new lot, deducts volumes from source lots
- Projected blend composition (variety %, vintage %, appellation %)
- TTB labeling compliance check on blend composition

### 4.7 Barrel Operations
- Fill barrels from lot (select lot → select barrels → record gallons per barrel)
- Barrel grouping / sets
- Topping log: date, source wine, volume added per barrel
- Racking: move wine from barrels → tank or new barrels, log lees weight
- Barrel sample and analysis entry
- Barrel notes / tasting notes per barrel
- Barrel rotation / stirring log
- Barrel disposal: sell, return to cooperage, retire
- Time-in-oak tracking (months) per barrel per lot

### 4.8 Transfers & Racking
- Transfer log: from vessel → to vessel, date, volume, pump used
- Transfer type: gravity, pump, filter, press
- Volume reconciliation (record variance/loss)
- Transfer triggers inventory update automatically

### 4.9 Pressing
- Press log: lot, press type, press cycle data (pressure profile optional), press fractions (free run, light press, heavy press)
- Yield calculation (juice yield % from fruit weight)
- Pomace disposal record

### 4.10 Filtering & Fining
- Filter log: date, lot, filter type (plate, DE, sterile), filter media used, flow rate, volume processed
- Pre/post filter analysis comparison
- Fining trial notes (bench trial → final treatment)

### 4.11 Bottling
- Bottling run creation: lot(s), date, line, format (750ml, 375ml, 1.5L, etc.)
- Components consumed: bottles, corks/screw caps, capsules, labels (front, back, neck)
- Yield tracking: bottles filled, waste %, breakage
- Lot volume deduction on bottling completion
- Case goods inventory creation on bottling completion (auto-creates SKU cases/bottles)
- Bottling notes: fill level, dissolved oxygen, final SO2
- Lot seal / archive after bottling

### 4.12 Lab Analysis
- Analysis entry: date, lot, test type, value, method, analyst
- Standard tests: pH, TA, VA, free SO2, total SO2, residual sugar, alcohol, malic acid, glucose/fructose, turbidity, color
- Analysis history per lot (table + chart view)
- Threshold alerts (e.g., VA approaching legal limit)
- External lab import: CSV import from ETS Labs, OenoFoss, Wine Scan, etc.
- Sensory/tasting notes with rating (1–5 or 100pt) per lot per date

---

## 5. INVENTORY MANAGEMENT

### 5.1 Bulk Wine Inventory
- Real-time gallons by lot, by vessel, by location
- Volume reconciliation tools (book vs. physical)
- Bulk wine sales: transfer gallons to buyer, generate invoice
- Bulk wine purchases: receive gallons into a lot
- Bulk wine aging schedule (projected bottling dates)

### 5.2 Case Goods Inventory
- SKU registry: wine name, vintage, varietal, format, case size, UPC/barcode
- Stock levels: on-hand, committed (orders placed not yet shipped), available
- Multi-location stock: tasting room floor, back stock, offsite warehouse, 3PL
- Stock transfers between locations
- Receive finished goods (from bottling or purchased finished wine)
- Physical inventory count tool (scan or manual entry, variance report)
- Reorder point alerts

### 5.3 Dry Goods & Packaging Materials
- Item types: bottles, corks, screw caps, capsules, labels, cartons, dividers, tissue
- Stock levels with unit of measure (each, sleeve, pallet)
- Receive PO: vendor, date, quantity, cost per unit
- Auto-deduct on bottling run completion
- Reorder alerts
- Vendor linkage per item

### 5.4 Raw Materials & Cellar Supplies
- Items: wine additives (SO2, yeast, nutrients, fining agents, acids, enzymes, oak alternatives)
- Stock levels with unit (grams, kg, liters, each)
- Auto-deduct on additions log entry
- Expiration date tracking
- Reorder alerts
- Cost per unit (used in COGS)

### 5.5 Equipment & Asset Tracking
- Equipment register: name, type, serial number, purchase date, value
- Maintenance log per piece of equipment
- Cleaning & sanitation log (CIP records)
- Calibration records (for lab equipment, scales, flow meters)

---

## 6. COST ACCOUNTING & COGS

- Per-lot cost ledger: fruit cost, material additions cost, labor cost, overhead allocation
- Cost accumulation through all operations (transfers, blending, bottling)
- Cost rolls through blends proportionally
- Per-bottle / per-case COGS calculation at bottling
- Overhead allocation methods: by volume, by case, by labor hours (configurable)
- Labor cost entry per work order (hours × rate)
- Standard vs. actual costing toggle
- COGS report by lot, by SKU, by vintage, by variety
- Margin report: selling price vs. COGS by SKU and channel
- Variance analysis (standard vs. actual)
- Export COGS data for accountant / QuickBooks journal entry
- QuickBooks Online integration (two-way sync) [GROWTH]
- Xero integration (two-way sync) [GROWTH]

---

## 7. TTB & REGULATORY COMPLIANCE

### 7.1 TTB Reporting
- Auto-generated TTB Form 5120.17 (Report of Wine Premises Operations)
  - Part I: Summary of wine premises operations
  - Part II: Wine produced
  - Part III: Wine received in bond
  - Part IV: Wine removed from bond
  - Part V: Losses
- Monthly report generation from production activity data
- Report review UI (winemaker can verify before submission)
- Export as PDF for filing
- Historical report archive

### 7.2 Bond & Permit Tracking
- Store TTB permit number, bond amount, bond expiration
- State license tracking per state (number, type, expiration)
- License renewal reminders (configurable lead time)
- Document vault: upload and store all permits, licenses, certificates

### 7.3 DTC Shipping Compliance
- State-by-state compliance rules database (which states allow DTC wine shipping, limits per month/year)
- Auto-block orders to non-compliant states at checkout
- Per-customer annual DTC shipment tracking (alert when approaching state limit)
- Sovos ShipCompliant integration [GROWTH]
- COLA (Certificate of Label Approval) record storage per SKU

### 7.4 FDA Traceability
- Lot traceability records: full chain from grape intake → lot → blend → bottle → order
- One-step-back / one-step-forward lot trace
- Traceability report generation for audit or recall scenario
- Supplier/grower records linkage to lots

### 7.5 Organic / Sustainable Certification Support
- Certification type tracking: USDA Organic, Demeter Biodynamic, SIP Certified, CCOF, etc.
- Non-approved input flagging in additions log
- Certification audit trail report

---

## 8. TASTING ROOM — POINT OF SALE

### 8.1 POS Core
- Native tablet app (KMP shared core + Compose/SwiftUI) — iOS + Android
- **Offline-first:** full card payment capture when wifi drops via Stripe Terminal native SDKs + local SQLite event queue (same sync engine as Cellar App). "Keeps taking cards when your wifi goes down."
- Product catalog: wines by bottle, flight/tasting menu items, merchandise, food, gift cards
- Quick-add buttons (configurable favorites)
- Open tabs per table / party
- Tab transfer between staff
- Split payments (split by item, split by amount, split by person)
- Payment methods: credit card (Stripe default or BYO processor), cash, gift card, house account
- Apple Pay / Google Pay (via Stripe Terminal contactless)
- Tip prompts (configurable %)
- Receipts: email, native print (Star Micronics / Epson), or skip
- Offline transaction queue with sync indicator

### 8.2 Tasting Experiences
- Tasting menu builder: define flights (wine pairings, pour sizes, prices)
- Fee waived on purchase (configurable threshold)
- Reservation check-in at POS (see booked party details)
- Member benefit redemption at POS (complimentary tastings, discounts)
- Seated vs. walk-in tracking

### 8.3 Merchandise & Non-Wine Items
- Merchandise SKU catalog (glasses, apparel, accessories, books, olive oil, etc.)
- Inventory tracking for merch items
- Bundled product support (wine + merch gift set)

### 8.4 Gift Cards
- Issue gift cards (physical card or digital code)
- Redemption at POS or online store
- Balance tracking
- Gift card liability reporting

### 8.5 Discounts & Promotions
- Discount types: % off, $ off, free item, BOGO
- Discount scope: per item, per order, per category
- Club member automatic discount
- Promo code entry at POS
- Manager override for ad-hoc discounts (requires manager PIN or role)

### 8.6 Staff Management at POS
- Cashier login via PIN at shared device
- Per-staff sales reporting
- Tip pooling vs. tip assignment tracking
- Open/close shift with cash drawer reconciliation

### 8.7 QR Code Ordering (Optional)
- QR code generated per table
- Guest scans, browses menu, adds to tab
- Staff confirms and processes at POS
- No-app required for guest (web-based)

---

## 9. eCOMMERCE

### 9.1 Online Store
- Hosted storefront (subdomain: shop.winery.com or custom domain via CNAME)
- Customizable theme (colors, logo, fonts, hero image)
- Product pages: wine name, vintage, varietal, tasting notes, tech sheet, food pairing, price, in-stock indicator
- Product gallery (multiple images per SKU)
- Age gate (21+ confirmation)
- State compliance layer: auto-hide products / block checkout to non-compliant states
- Mobile-responsive design
- SEO: page titles, meta descriptions, URL slugs

### 9.2 Cart & Checkout
- Cart with quantity controls and notes field
- Guest checkout + optional account creation
- Saved addresses and credit card (tokenized via processor)
- Shipping address validation
- Shipping method selection (FedEx / UPS / LSO rate shopping)
- Order summary with tax calculation
- Promo/discount code field
- Apple Pay / Google Pay express checkout
- Order confirmation email with tracking

### 9.3 Allocation & Futures
- Allocation list: define which customers are eligible for limited wines
- Allocation cart: eligible customers get access window, quantity cap per customer
- Futures / pre-release: accept orders + deposits before wine is bottled
- Waitlist management

### 9.4 Upsell & Merchandising
- "Frequently bought together" manual pairing
- Add-to-cart recommendations
- Bundle builder (e.g., mixed 6-pack with discount)
- Cart carrot ("Add $X more to qualify for free shipping")

### 9.5 Shipping & Fulfillment
- Print shipping labels (FedEx, UPS via API)
- Packing slip generation
- Pack and ship workflow (pick list → verify → pack → label → mark shipped)
- Tracking number capture and customer notification email
- Return/damage processing
- Adult signature required flag (configurable by state)
- 3PL / fulfillment house integration (optional) [PRO]

### 9.6 Order Management
- Order list with status filters: New, Processing, Shipped, Delivered, Cancelled, Refunded
- Order detail: items, customer, payment, shipping, notes
- Manual order creation (phone orders)
- Order editing before fulfillment
- Partial and full refunds
- Reorder for customer with one click

---

## 10. WINE CLUB & SUBSCRIPTIONS

### 10.1 Club Configuration
- Multiple club tiers (unlimited)
- Per-tier: name, description, shipment frequency, min bottles, price range, perks
- Club types: winery-curated, customer-choice, hybrid
- Allocation/access perks (early access, discounts, complimentary tastings)
- Pause/skip rules (how many skips allowed per year)

### 10.2 Member Management
- Member record: linked customer profile, club tier, join date, ship-to address, payment method, preferences
- Membership status: Active, Paused, Cancelled, Pending
- Join via: POS, online signup form, QR code
- Member portal: self-service address update, CC update, skip shipment, customize order, cancel
- Member notes (internal CRM notes)
- Membership history (all past shipments, payments, status changes)

### 10.3 Club Processing
- Processing calendar with configurable run dates
- Pre-run report: members to charge, estimated revenue, failed card prediction
- Shipment customization window: notify members, let them swap wines before charge
- Batch charge execution with real-time progress
- Failed payment handling: auto-retry schedule, email notification to member, manual retry
- Declined card queue with staff action UI
- Post-run report: total charged, failed count, revenue, cancelled during window

### 10.4 Shipment Management
- Shipment creation per run: wine allocation per tier, quantity per member
- Packing list generation
- Shipping label batch print
- Shipment notes / inserts (custom card per tier)
- Tracking number batch upload
- Member notification email with tracking

### 10.5 Subscription (Non-Traditional) Mode
- Customer sets their own cadence (every 1/2/3 months)
- Customer selects their own wines within tier parameters
- Subscription pause / resume by customer in portal
- Dunning management (retry logic for failed payments)

---

## 11. CRM & CUSTOMER MANAGEMENT

### 11.1 Customer Profiles
- Unified profile: all touchpoints (tasting room, club, eCommerce, reservations)
- Fields: name, email, phone, DOB (for age), address(es), preferences, notes, tags
- Purchase history across all channels with total lifetime value
- Club membership status and history
- Reservation and visit history
- Communication history (emails sent, responses)
- Document/waiver attachments per customer

### 11.2 Segmentation
- Filter customers by: channel, purchase history, club tier, location, last visit date, LTV, wine preference, tags
- Save segments as named lists
- Segment size preview before action

### 11.3 Email Marketing
- Basic built-in email: compose, send to segment, schedule
- Template library (newsletter, club announcement, event invite, re-engagement)
- Drag-and-drop email builder
- Open / click tracking
- Unsubscribe management (CAN-SPAM compliant)
- Mailchimp integration (sync segments + contacts) [GROWTH]
- Klaviyo integration [GROWTH]
- Klaviyo flow triggers (e.g., trigger post-visit flow after tasting room visit) [PRO]

### 11.4 Loyalty & Engagement
- Points-based loyalty system (optional, configurable) [GROWTH]
- Birthday recognition (automated birthday email + offer)
- Win-back campaign triggers (customer inactive > X days)
- Customer anniversary emails (join date)
- Net Promoter Score (NPS) survey send + tracking [PRO]

---

## 12. RESERVATIONS & EVENTS

### 12.1 Reservation System
- Tasting experience types: define name, duration, capacity, price, description, images
- Public-facing booking widget (embeds on winery website or hosted page)
- Real-time availability calendar
- Party size selection with capacity enforcement
- Deposit / prepay option (full or partial)
- Confirmation and reminder emails (24h, 2h before)
- Cancellation policy enforcement (deadline, refund rules)
- Waitlist for fully-booked slots
- Member benefit: complimentary reservations auto-applied for eligible members
- Reservation notes (special occasion, dietary notes, accessibility needs)
- Walk-in addition to same system for unified tracking

### 12.2 Event Management
- Event creation: name, date/time, location, description, capacity, ticket price tiers
- Ticketing: single-tier and multi-tier (general, VIP, member)
- Promo codes for events
- Event-specific landing page (hosted)
- QR code check-in app (staff scans ticket QR at door)
- Attendee list export
- Post-event email to attendees
- Integration with POS for day-of F&B sales

### 12.3 Calendar & Staff View
- Unified calendar: reservations + events + winery closures
- Capacity dashboard (today's bookings at a glance)
- Block off times / blackout dates
- Staff assignment to experiences

---

## 13. WHOLESALE & DISTRIBUTION

- Wholesale customer account type (separate from retail customers)
- Price list management: multiple tiered price lists (wholesale, on-premise, off-premise, by-the-glass)
- Wholesale order entry (phone/email orders entered by staff)
- Distributor portal (invite distributor to place orders) [PRO]
- Sales rep assignment per account
- Credit terms per account (Net 30, Net 60, COD)
- Accounts receivable aging report
- State licensing verification per wholesale account
- Depletion report tracking (how much distributor sold through) [PRO]
- Sales goal tracking by rep by territory [PRO]

---

## 14. PAYMENTS

### 14.1 Default Processor (Stripe)
- Stripe Connect integration (app collects platform fee, winery gets remainder)
- Platform fee: configurable % (your markup)
- Card-present: Stripe Terminal (chip/tap reader)
- Card-not-present: tokenized via Stripe.js
- ACH / bank transfer for large wholesale invoices [GROWTH]
- Automatic payout schedule to winery bank account

### 14.2 BYO Processor [GROWTH]
- API key hookup for:
  - Stripe (direct, no platform fee)
  - Square
  - Braintree / PayPal
- Guided setup wizard (enter keys, run test transaction, go live)
- Processor-agnostic abstraction layer in backend
- BYO users pay flat SaaS fee only (no transaction %)

### 14.3 Payment Operations
- Surcharging / cash discount program (configurable, state-compliance aware)
- Apple Pay / Google Pay (web and in-person)
- Card-on-file for club processing and repeat customers
- Partial payments and split payments
- Refunds (full and partial)
- Dispute / chargeback management UI (view, respond, track)
- Payment reconciliation report

---

## 15. REPORTING & ANALYTICS

### 15.1 Sales Reports
- Sales by channel (tasting room, club, eCommerce, wholesale, events)
- Sales by SKU (revenue, units, % of total)
- Sales by time period (day, week, month, quarter, vintage year)
- Average order value by channel
- Top customers by revenue
- Discount and promo usage report
- Refund and return report

### 15.2 Production Reports
- Harvest yield by block and variety
- Lot volume tracking over time (waterfall chart)
- Production timeline per lot
- Material usage summary (additives consumed, cost)
- Bottling yield report (gallons in → bottles out, loss %)
- Work order completion rate by staff
- Lab analysis summary by lot

### 15.3 Inventory Reports
- Current stock on hand by SKU and location
- Inventory movement history
- Slow-moving inventory (no sales in X days)
- Dry goods reorder report
- Variance report (physical count vs. book)
- Bulk wine aging report

### 15.4 Club & Subscription Reports
- Active member count by tier
- Member churn rate (monthly, quarterly)
- Club revenue per run
- Failed payment rate and recovery rate
- Average member LTV
- New member acquisition by source
- Member retention cohort analysis [PRO]

### 15.5 Financial Reports
- Revenue by channel and SKU (P&L summary)
- COGS by lot, vintage, variety
- Gross margin by SKU and channel
- Accounts receivable aging
- Gift card liability balance
- Cash flow summary (integrates with QuickBooks / Xero)

### 15.6 Compliance Reports
- TTB 5120.17 (monthly auto-generated)
- DTC shipment volume by state (for compliance tracking)
- Lot traceability chain (full audit)
- Chemical application log (for organic/sustainable certs)
- License and permit expiration calendar

### 15.7 AI-Powered Reports [PRO]
- Weekly business digest: natural language summary of top trends, anomalies, and action items
- Demand forecasting: project SKU sell-through based on sales velocity + seasonality
- Harvest timing suggestions: correlate brix/pH curves with historical outcomes per block
- Fermentation dry-down prediction: estimate days to dryness from current curve
- Club churn risk scoring: flag members statistically likely to cancel before they do
- Margin optimization suggestions: flag underpriced SKUs vs. COGS

---

## 16. NOTIFICATIONS & AUTOMATION

- In-app notifications center
- Email notification templates (configurable per event type)
- SMS notifications via Twilio [GROWTH]
  - Club charge notification to member
  - Order shipped with tracking link
  - Reservation reminder (24h, 2h)
  - Failed payment alert to member
- Internal staff alerts:
  - Low inventory threshold crossed
  - Work order overdue
  - License/permit expiring in X days
  - Failed club charge requires attention
  - New wholesale order received
- Automation rules builder (if/then) [PRO]
  - e.g., "If customer visits tasting room → send follow-up email 24h later"
  - e.g., "If club member skips 2 consecutive shipments → trigger win-back sequence"
  - e.g., "If lot VA exceeds 0.9 g/L → alert winemaker"

---

## 17. NATIVE MOBILE APPS

Both apps are built on a shared Kotlin Multiplatform (KMP) core (sync engine, SQLDelight local database, Ktor API client, event queue). Native UI per platform: Jetpack Compose (Android), SwiftUI (iOS).

### 17.1 Cellar App (Phone)
- iOS app (App Store) + Android app (Google Play) — exploits InnoVint's iOS-only gap
- Offline-first architecture: full functionality without connectivity
  - All writes go to local SQLite immediately, queued for sync
  - Background sync on reconnect with conflict resolution
  - Additive operations (e.g., SO2 additions) all apply; destructive operations (transfers, racks) validate volume on server
  - NTP clock drift check on app launch
- Core cellar workflows:
  - Complete work orders from cellar floor
  - Log additions and transfers
  - View lot details and analysis history
  - Barrel scan via native camera APIs (CameraX / AVFoundation)
  - Lab analysis data entry
  - Fermentation data entry
- Push notifications
- Biometric login (FaceID / fingerprint)

### 17.2 POS App (Tablet)
- iOS app (App Store) + Android app (Google Play) — tablet-optimized layout
- Offline-first architecture: keeps taking card payments when wifi drops
  - Same KMP sync engine as Cellar App
  - Stripe Terminal native SDKs handle offline card capture in reader hardware
  - Cash and non-card transactions queued in local SQLite event log
- Core POS workflows:
  - Product catalog browsing and cart building
  - Tab management (open, transfer, close)
  - Split payments (by item, amount, or person)
  - Stripe Terminal card-present payments (chip, tap, Apple Pay, Google Pay)
  - Cash payments with drawer tracking
  - Wine club signup with card-on-file capture
  - Reservation check-in
  - Tasting fee waiver on purchase threshold
  - Member discount auto-application
  - Receipt email / print (native Star Micronics / Epson SDKs) / skip
  - End-of-day cash drawer reconciliation
  - Shift open/close
  - QR check-in for events
- Push notifications
- Biometric login / PIN login for shared devices

---

## 18. INTEGRATIONS

### Built-in (Included)
- QuickBooks Online (two-way sync: invoices, COGS, chart of accounts) [GROWTH]
- Xero (two-way sync) [GROWTH]
- Stripe (payments)
- FedEx API (shipping labels + rate shopping)
- UPS API (shipping labels + rate shopping)
- Mailchimp (contact sync + segment export) [GROWTH]

### Partner / Optional
- Klaviyo (advanced email/SMS marketing) [PRO]
- Sovos ShipCompliant (DTC compliance, auto-file) [GROWTH]
- Square Terminal (BYO processor option)
- Vivino (marketplace listing sync) [PRO]
- ETS Labs (analysis import)
- OenoFoss / Wine Scan (analysis import)
- AgCode / vineyard sensors (IoT data import) [PRO]
- TankNET (tank monitoring) [PRO]

### Developer / API [PRO]
- Public REST API with Sanctum scoped tokens (no OAuth/Passport dependency)
- Webhooks (configurable events: order placed, club charge, lot created, etc.)
- API documentation (auto-generated, Swagger/OpenAPI)
- Sandbox environment for testing

---

## 19. ADMIN & PLATFORM INFRASTRUCTURE

- Winery-level audit log: every data change logged (who, what, when, old value, new value)
- Data export: CSV export on every table / module
- Full data export / backup (GDPR-style "export my data")
- Account deletion with data wipe option
- GDPR / CCPA compliance tools: data subject requests, consent tracking
- Custom fields on key records (lots, customers, vessels) for winery-specific data [GROWTH]
- Document vault: attach PDFs/images to lots, customers, equipment, licenses
- In-app help center with searchable articles
- In-app onboarding tours per module (first-time user guided walkthrough)
- Support ticket submission from within app
- Feature request / feedback board (public roadmap visibility) [ALL TIERS]
- Status page (uptime / incident tracking)
- SOC 2 Type II compliance target [infrastructure milestone]
- Data encryption at rest and in transit
- Automated daily backups with point-in-time restore

---

## 20. PRICING TIER SUMMARY

### STARTER — ~$99/month
- Cellar/production management (lots, vessels, work orders, additions)
- Basic inventory (bulk wine + case goods)
- TTB 5120.17 auto-generation
- Basic reporting
- 2 users included
- Cellar App — native mobile (iOS + Android, offline-first)

### GROWTH — ~$199/month
- Everything in Starter
- Tasting room POS — native tablet app (iOS + Android, offline-first with offline card payments)
- Wine club management (unlimited tiers)
- eCommerce storefront
- Basic CRM and email
- Reservations
- QuickBooks / Xero integration
- Vineyard module
- Sovos ShipCompliant integration
- 10 users included
- BYO payment processor option

### PRO — ~$349/month
- Everything in Growth
- AI weekly digest + demand forecasting + churn prediction
- Multi-brand / multi-winery under one login
- Wholesale / distributor portal
- Advanced automation rules
- API access + webhooks
- Klaviyo / advanced marketing integrations
- Consolidated multi-brand reporting
- Custom fields
- Unlimited users
- Priority support

---

## 21. NOTES FOR TASK FILE GENERATION

When breaking this document into task files, suggested structure:
- One task file per top-level module (e.g., `tasks/04-cellar-production.md`)
- Each task file contains: goal, sub-tasks (atomic/implementable), dependencies on other modules, and suggested data models
- Cross-module dependencies to flag:
  - Cellar lots ← Inventory (case goods auto-created at bottling)
  - Additions ← Dry goods inventory (auto-deduct)
  - Work orders ← Labor costs ← COGS
  - Grape intake ← Vineyard harvest events
  - POS sales ← Case goods inventory (deduct on sale)
  - Club processing ← eCommerce order creation pipeline
  - All financial events ← QuickBooks / Xero sync
  - All lot movements ← TTB report data accumulation
  - KMP shared core ← both Cellar App and POS App (must be built before either native app)
- Prioritized build order (see `architecture.md` Section 15 for the definitive phased sequence):
  - Phase 1: Foundation (Laravel, tenancy, auth, event log, CI/CD)
  - Phase 2: Production module + portal (lots, vessels, work orders, additions, lab, fermentation, inventory)
  - Phase 3: TTB compliance (5120.17 auto-generation, verification test suite)
  - Phase 4: KMP shared core (sync engine, SQLDelight, Ktor client, conflict resolution)
  - Phase 5: Cellar App native UI (Compose + SwiftUI, phone layout)
  - **→ SELL IT HERE — Starter tier at $99/month**
  - Phase 6: POS App native UI (Compose + SwiftUI, tablet layout, Stripe Terminal)
  - Phase 7: Growth tier features (club, eCommerce, reservations, widgets, CRM, accounting integrations, vineyard)
  - Phase 8: Pro tier + VineBook (AI features, multi-brand, wholesale, public API, directory site, automation rules)
- Key technical decisions (see `architecture.md` for full rationale):
  - Backend: Laravel 12 (PHP 8.4+), PostgreSQL, Redis
  - Data pattern: Append-only event log + materialized state tables
  - Native apps: Kotlin Multiplatform shared core + Compose (Android) / SwiftUI (iOS)
  - POS: Native offline-first (not PWA) — offline card payments are a selling point
  - Auth: Sanctum throughout (including Pro-tier API — no Passport/OAuth dependency)
  - Admin UI: Filament v3 (pinned until v4 stabilizes)
  - Multi-tenancy: Schema-per-tenant via stancl/tenancy
