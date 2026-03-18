# Third-Party Restaurant POS Integration — Winery-Restaurant Hybrid Support

**Created:** 2026-03-17
**Status:** ⏳ Deferred → Phase 10 Growth Features (Task 16 or standalone integration task)
**Priority:** Medium — high value for winery-restaurant hybrids, natural fit once POS and inventory are live
**Estimated Effort:** 2-3 weeks (abstraction layer + Toast adapter + club membership flow)

---

## Strategic Context

Many wineries operate hybrid tasting-room-and-restaurant venues (e.g., CASS Winery in Paso Robles). These operations currently run Toast for restaurant POS and a separate system (InnoVint, spreadsheets) for wine production. The two systems have zero awareness of each other — the restaurant wine list doesn't know what's in cellar inventory, and the production side can't see by-the-glass pour data for TTB reporting or COGS.

No competitor in VineSuite's market offers Toast integration. This is a clean differentiator for the winery-restaurant segment, which represents a significant portion of DTC-focused wineries in tourist-heavy AVAs like Paso Robles, Napa, and Willamette Valley.

---

## Abstraction Layer — RestaurantPosAdapter

Toast is the recommended default, but the integration should be built behind a provider-agnostic abstraction so wineries using Square, Clover, or Lightspeed aren't locked out. The philosophy mirrors the IoT spec's "blessed hardware list" approach: promote Toast as the tested, recommended integration, but accept anything that speaks the protocol.

### Interface Design

```php
interface RestaurantPosAdapter
{
    /** Normalize an inbound webhook payload into a common OrderReceived shape. */
    public function normalizeOrder(array $payload): NormalizedOrder;

    /** Push inventory availability for a SKU to the external POS menu. */
    public function pushAvailability(string $externalItemId, int $quantity): void;

    /** Pull menu items for initial mapping setup. */
    public function pullMenuItems(): Collection;

    /** Verify webhook signature for authenticity. */
    public function verifyWebhookSignature(Request $request): bool;
}
```

**Concrete adapters:** `ToastPosAdapter` ships first and is the only officially supported adapter at launch. Additional adapters (Square, Clover, Lightspeed) are built when customer demand justifies — the interface guarantees they plug in without refactoring the event pipeline.

**Webhook routing:** Generic endpoint `POST /api/v1/integrations/{provider}/webhook` dispatches to the correct adapter via a factory. The provider slug is registered in a `restaurant_pos_connections` table per tenant.

**BYO adapter path (future):** A documented webhook format and adapter registration system means technically capable wineries or consultants could write custom adapters for niche POS systems. Not officially supported, but not blocked — same as the IoT BYO integration philosophy.

**Support tradeoff:** Each new adapter is 1-2 weeks of implementation plus ongoing support for vendor-specific API quirks. Build only when demand is clear — 10+ wineries requesting the same POS is the threshold. The abstraction costs almost nothing (one interface, one factory class). Each concrete adapter is the real investment.

### Provider Connection Table

```sql
CREATE TABLE restaurant_pos_connections (
    id               UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id        UUID NOT NULL,
    provider         VARCHAR(50) NOT NULL,       -- 'toast', 'square', 'clover', 'lightspeed'
    provider_location_id VARCHAR(255),            -- External location ID
    credentials      JSONB NOT NULL,              -- Encrypted OAuth tokens, API keys
    webhook_secret   VARCHAR(255),                -- For signature verification
    is_active        BOOLEAN DEFAULT true,
    last_synced_at   TIMESTAMPTZ,
    created_at       TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE(tenant_id, provider)
);
```

---

## Data Flow (Provider-Agnostic)

### Inbound: External POS → VineSuite (order data)

The external POS fires real-time webhooks on order events. VineSuite receives these, dispatches to the correct adapter, normalizes into a common event shape, and feeds the event log as a sales channel alongside tasting room POS, wine club, and ecommerce.

```
External POS → Webhook (order.completed)
  → POST /api/v1/integrations/{provider}/webhook
    → RestaurantPosAdapterFactory::resolve($provider)
      → adapter->normalizeOrder($payload) → NormalizedOrder
        → Event log (append-only)
        → Inventory: decrement by-the-glass / bottle stock
        → COGS: attribute cost to lot
        → TTB: count as removal for 5120.17
        → Revenue reporting: unified with all other channels
        → CRM: capture guest email for club nurture flow
```

**Key data from Toast orders:**
- Menu item → mapped to VineSuite SKU / lot via a menu mapping table
- Quantity (glasses, bottles, flights)
- Revenue (price, tax, tip)
- Revenue center (restaurant vs. bar vs. patio — useful for per-location reporting)
- Timestamp (for time-of-day sales analysis)

### Outbound: VineSuite → Toast (inventory availability)

VineSuite knows real-time inventory levels for every wine in cellar. Push availability updates to Toast's menu API so the restaurant wine list stays in sync automatically.

```
VineSuite inventory change event
  → Check if affected SKU is mapped to a Toast menu item
    → PATCH Toast Menu API: update availability / 86 items at zero stock
```

**Scenarios this handles:**
- Last case of a vintage sold through wine club → automatically 86'd on restaurant menu
- New vintage released → automatically available on restaurant menu
- Winemaker pulls wine from by-the-glass for a private event → restaurant menu updates

### Menu Mapping Table

```sql
CREATE TABLE toast_menu_mappings (
    id               UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id        UUID NOT NULL,
    toast_menu_item_id VARCHAR(255) NOT NULL,  -- Toast's GUID
    toast_item_name  VARCHAR(255),              -- Human-readable, synced from Toast
    sku_id           UUID,                      -- FK to case_goods_skus
    lot_id           UUID,                      -- FK to lots (for by-the-glass pours)
    serving_type     VARCHAR(20),               -- 'bottle', 'glass', 'flight', 'taste'
    volume_per_serving DECIMAL(8,4),            -- Gallons per serving (for TTB gallon tracking)
    is_active        BOOLEAN DEFAULT true,
    created_at       TIMESTAMPTZ DEFAULT NOW(),
    updated_at       TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE(tenant_id, toast_menu_item_id)
);
```

The `volume_per_serving` field is critical — TTB tracks removals in gallons, so a 6oz glass pour needs to convert to gallons for 5120.17 reporting. Standard: 6oz = 0.046875 gallons. This lets VineSuite automatically account for by-the-glass pours in TTB volume calculations, which is something winery-restaurants currently track manually or don't track at all.

---

## Toast Adapter Details (First Implementation)

**Authentication:** OAuth2 client credentials flow. Client ID + Client Secret from Toast Developer Portal. Tokens are per-restaurant-location.

**Key APIs consumed:**

| API | Direction | Purpose |
|-----|-----------|---------|
| Orders API (webhooks) | Toast → VineSuite | Real-time order events for sales tracking and inventory |
| Menu API | VineSuite → Toast | Push wine availability updates, sync menu item metadata |
| Revenue Centers API | Toast → VineSuite | Map sales to locations (restaurant, bar, patio, tasting room) |
| Restaurant Info API | Toast → VineSuite | Initial setup — pull location details, tax rates |
| Loyalty API | Bidirectional | Phase 10+: push club membership status, pull loyalty data |

**Webhook events to subscribe:**
- `order.completed` — primary sales data (includes guest email if digital receipt enabled)
- `order.voided` — reverse inventory deductions
- `order.refunded` — partial/full refund handling
- `menu.item.updated` — catch external menu changes

**Rate limits:** Toast API has standard rate limiting. Outbound menu updates should be batched (debounce inventory changes, push every 5 minutes rather than per-transaction).

**Partnership tier:** Toast has an Integration Partner Program with a formal application process. Listing in the Toast Integrations marketplace provides discovery to Toast's 120,000+ restaurant customer base. Worth applying once the integration is functional.

### Future Adapter Candidates

| POS | Winery Prevalence | API Quality | Notes |
|-----|-------------------|-------------|-------|
| **Toast** | High (winery-restaurants) | Strong REST + webhooks | First adapter. Best fit for winery-restaurant hybrids. |
| **Square** | High (tasting rooms) | Strong REST + webhooks | Many tasting rooms already use Square. Good second adapter candidate. |
| **Clover** | Medium | REST API available | Some tasting rooms use Clover. Build on demand. |
| **Lightspeed** | Low-medium | REST + webhooks | More common in retail/restaurant. Build only if requested. |

**Build threshold:** 10+ wineries requesting the same POS integration justifies building a new adapter.

---

## Event Types

New events for the `sales` event source partition (provider-agnostic — prefixed by channel, not vendor):

- `restaurant_order_received` — normalized order data from any external POS adapter
- `restaurant_order_voided` — reversal event
- `restaurant_order_refunded` — partial or full refund
- `restaurant_menu_synced` — outbound availability push confirmation
- `restaurant_mapping_created` — menu item mapped to VineSuite SKU/lot
- `restaurant_mapping_removed` — menu item unmapped

The event payload includes `provider: 'toast'` (or `'square'`, etc.) for attribution, but the event type itself is provider-agnostic so reporting and TTB calculations don't need provider-specific logic.

---

## Wine Club Membership — Restaurant-to-Club Pipeline

VineSuite's own POS enables seamless one-tap wine club signup at checkout. In the restaurant scenario, VineSuite doesn't control the checkout screen, so club enrollment takes three alternative paths:

### Path 1: Post-Visit Email Nurture (Automated)

Toast order webhooks can include guest email and name (via Toast guest profiles or digital receipts). When VineSuite receives an order with guest contact data:

```
restaurant_order_received (with guest email)
  → CRM: create or update contact, tag as 'restaurant_visitor'
    → Automation trigger (Task 18): send post-visit email within 2-4 hours
      → "Loved the 2023 Reserve Cab? Join the club — get it shipped quarterly."
      → Deep link to club signup page with pre-filled contact info
```

This is a warm lead nurtured within hours. Not checkout-seamless, but significantly better than losing the contact entirely.

### Path 2: Physical Tasting Room Handoff (Operational)

Winery-restaurants like CASS typically have a tasting room adjacent to the restaurant. The natural flow: dine → walk to tasting bar → flight → club signup on VineSuite's POS. VineSuite's unified CRM means the server can see "this guest dined 30 minutes ago, ordered the estate Cab" and tailor the tasting experience accordingly.

### Path 3: Bidirectional Loyalty (Phase 10+ — Deeper Integration)

Toast has a loyalty and marketing API. In a deeper integration phase, VineSuite could push club membership status into Toast so the restaurant server sees "Gold Club Member" on the guest profile and applies the member discount on bottles ordered with dinner. This turns the restaurant into a club perk touchpoint — dine as a member, get 15% off bottles, reinforcing the club value proposition.

This path requires Toast Integration Partner status and is recommended only after the base integration is proven with real customers.

---

## Value Proposition for Winery-Restaurants

**Without integration (current state):**
- Restaurant wine list managed manually in Toast, disconnected from cellar inventory
- By-the-glass pours not tracked in production system — TTB volume reconciliation is a guess
- No unified view of where wine was sold (restaurant vs. tasting room vs. club vs. online)
- COGS for restaurant wine sales are approximate at best
- 86'd items require manual communication between cellar and kitchen
- Restaurant diners leave without any connection to the wine club

**With integration:**
- Wine list availability auto-syncs from VineSuite inventory
- Every pour is an event: lot attribution, gallon conversion, TTB-ready
- Unified revenue reporting across all sales channels in one dashboard
- Real COGS per glass/bottle including lot-specific production costs
- Automatic 86 when stock hits zero — no more selling wine you don't have
- Restaurant guest emails flow into CRM → automated club nurture emails
- Club member status visible to restaurant servers (Phase 10+ deeper integration)

---

## Filament UI

**Settings page:** Toast connection setup (OAuth flow), location selection, webhook URL display.

**Menu Mapping page:** Two-column interface — Toast menu items on the left, VineSuite SKUs/lots on the right. Drag-and-drop or dropdown mapping. Serving type and volume-per-serving configuration per item.

**Dashboard widget:** Toast sales summary — today's revenue, glasses poured, bottles sold, top-selling wine, with drill-down to lot-level attribution.

---

## Sequencing

This integration requires:
- Inventory module (complete — Phase 4)
- Case goods SKU management (complete — Phase 4)
- POS event patterns established (Phase 9, Task 09)
- Sales event source partition active (Phase 10, Task 10/11)

**Recommended slot:** Task 16 (Accounting Integrations) as a sub-task, or a standalone integration task in Phase 10. The webhook receiver pattern established here becomes the template for other third-party POS integrations (Square, Clover, Lightspeed) that winery tasting rooms use.

---

## Cross-References

- Task 09 (POS App) — VineSuite's own POS handles direct tasting room sales. External POS integration handles the restaurant side. Both feed the same event log.
- Task 10 (Wine Club) — Club allocation decisions benefit from knowing restaurant pour velocity. Restaurant guest emails feed the club nurture pipeline.
- Task 11 (Ecommerce) — Unified channel reporting includes restaurant as a sales channel.
- Task 13 (CRM/Email) — Guest email capture from restaurant orders feeds CRM contact creation and automated nurture flows.
- Task 16 (Accounting Integrations) — Restaurant POS revenue data flows into accounting exports (QuickBooks, Xero).
- Task 18 (Notifications/Automation) — Post-visit email nurture triggered by restaurant order events.
- Task 19 (Reporting) — Per-channel sales breakdown includes restaurant data.
- Task 23 (Public API) — The `RestaurantPosAdapter` interface and webhook format should be documented in the public API so third parties can build custom adapters.
- `multi-vertical-cellar-suite.md` — Restaurant POS integration is even more relevant for breweries (taproom + restaurant is the dominant model in craft beer).
- `unified-tax-engine.md` — Restaurant orders carry tax data that should reconcile with VineSuite's tax calculations.
