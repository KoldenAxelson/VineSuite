# eCommerce

## Phase
Phase 7

## Dependencies
- `01-foundation.md` — auth, event log, Filament
- `04-inventory.md` — case goods SKUs and stock levels (orders deduct inventory)
- `06-ttb-compliance.md` — DTC shipping compliance (block orders to non-compliant states)
- `15-payments-advanced.md` — payment processing for online orders

> **Pre-implementation check:** This spec predates completed phases. Before starting, load `CONVENTIONS.md` and review phase recaps for any dependency phases listed above. Patterns, service boundaries, and data model decisions may affect assumptions in this spec.

## Ideas to Evaluate

> Review these before starting this phase. If they fit, create additional sub-tasks.

- `ideas/unified-tax-engine.md` — Consumes the shared tax service designed in Task 09

## Goal
Build the hosted online store for DTC wine sales. Winery gets a storefront at `shop.wineryname.com` (or custom domain via CNAME) with product pages, age gate, state compliance layer, cart, checkout with Stripe payments, and a complete order management + fulfillment workflow. This is a core Growth tier feature — wineries without an integrated online store are losing revenue.

## Data Models

- **Store** — `id`, `subdomain`, `custom_domain`, `theme` (JSON — colors, fonts, hero_image), `age_gate_enabled` (boolean), `meta_title`, `meta_description`, `is_live`, `created_at`, `updated_at`
- **ProductListing** — `id` (UUID), `sku_id`, `is_published`, `sort_order`, `category`, `description_override`, `food_pairing`, `serving_suggestion`, `created_at`, `updated_at`
  - Relationships: belongsTo CaseGoodsSku, hasMany ProductImages
- **ProductImage** — `id`, `product_listing_id`, `path`, `alt_text`, `sort_order`
- **Order** — `id` (UUID), `order_number` (auto-increment display number), `customer_id`, `status` (new/processing/shipped/delivered/cancelled/refunded), `source` (online/pos/club/phone/wholesale), `subtotal`, `discount_amount`, `shipping_amount`, `tax_amount`, `total`, `shipping_address` (JSON), `billing_address` (JSON), `shipping_method`, `tracking_number`, `notes`, `created_at`, `updated_at`
  - Relationships: belongsTo Customer, hasMany OrderItems, hasMany Payments
- **OrderItem** — `id`, `order_id`, `sku_id`, `quantity`, `price_per_unit`, `discount_amount`, `total`
- **Payment** — `id` (UUID), `order_id`, `amount`, `method` (card/cash/gift_card/ach), `status` (captured/failed/refunded), `processor_reference`, `processor_fee`, `created_at`
- **PromoCode** — `id`, `code`, `type` (percent/fixed/free_shipping), `value`, `min_order`, `max_uses`, `uses_count`, `starts_at`, `expires_at`, `is_active`, `created_at`
- **ShippingLabel** — `id`, `order_id`, `carrier` (fedex/ups), `tracking_number`, `label_url`, `cost`, `created_at`

## Sub-Tasks

### 1. Store configuration and theming
**Description:** Set up the hosted store with subdomain routing, basic theming, and age gate.
**Files to create:**
- `api/app/Models/Store.php`
- `api/database/migrations/xxxx_create_stores_table.php`
- `api/app/Filament/Pages/StoreSettings.php`
- Storefront Blade/Livewire views
**Acceptance criteria:**
- Winery configures subdomain (e.g., shop.pasoroblescellar.com)
- Theme settings: primary color, font, logo, hero image
- Age gate (21+ confirmation) on first visit
- SEO metadata configurable
- Live/offline toggle

### 2. Product listing management
**Description:** Manage which SKUs appear in the online store, with additional display info (descriptions, food pairings, images).
**Files to create:**
- `api/app/Models/ProductListing.php`
- `api/app/Models/ProductImage.php`
- `api/database/migrations/xxxx_create_product_listings_table.php`
- `api/app/Filament/Resources/ProductListingResource.php`
**Acceptance criteria:**
- Select which SKUs to publish
- Override descriptions, add food pairings, serving suggestions
- Multiple images per product with ordering
- Category assignment for navigation
- State compliance layer: auto-hide products not shippable to visitor's state

### 3. Cart and checkout flow
**Description:** Build the shopping cart and multi-step checkout: cart → shipping → payment → confirmation.
**Files to create:**
- Storefront Livewire components for cart and checkout
- `api/app/Services/CartService.php`
- `api/app/Services/CheckoutService.php`
- `api/app/Services/TaxCalculationService.php`
**Acceptance criteria:**
- Cart with quantity controls and promo code field
- Guest checkout + optional account creation
- Shipping address validation
- DTC compliance check before allowing checkout (auto-blocks non-compliant states)
- Tax calculation (by shipping destination)
- Stripe payment via Stripe.js (tokenized, PCI compliant)
- Order confirmation email
- Writes `order_placed` event
- Inventory committed on order creation

### 4. Order model and management
**Description:** Central order model used by all sales channels (online, POS, club, phone, wholesale).
**Files to create:**
- `api/app/Models/Order.php`
- `api/app/Models/OrderItem.php`
- `api/app/Models/Payment.php`
- `api/database/migrations/xxxx_create_orders_table.php`
- `api/database/migrations/xxxx_create_order_items_table.php`
- `api/database/migrations/xxxx_create_payments_table.php`
- `api/app/Filament/Resources/OrderResource.php`
- `api/app/Services/OrderService.php`
**Acceptance criteria:**
- Order created from any source (online, POS event sync, club processing, manual)
- Status lifecycle: new → processing → shipped → delivered
- Manual order creation for phone orders
- Order editing before fulfillment
- Partial and full refunds
- Order search and filter

### 5. Promo code system
**Description:** Discount codes for online and POS use.
**Files to create:**
- `api/app/Models/PromoCode.php`
- `api/database/migrations/xxxx_create_promo_codes_table.php`
- `api/app/Filament/Resources/PromoCodeResource.php`
- `api/app/Services/PromoCodeService.php`
**Acceptance criteria:**
- Types: percentage off, fixed amount off, free shipping
- Constraints: min order, max uses, date range
- Validation at checkout and POS
- Usage tracking

### 6. Shipping and fulfillment workflow
**Description:** Pack-and-ship workflow with carrier label generation.
**Files to create:**
- `api/app/Models/ShippingLabel.php`
- `api/database/migrations/xxxx_create_shipping_labels_table.php`
- `api/app/Services/ShippingService.php`
- `api/app/Services/Carriers/FedExService.php`
- `api/app/Services/Carriers/UPSService.php`
- `api/app/Filament/Pages/Fulfillment.php`
**Acceptance criteria:**
- Pick list generation from pending orders
- Packing slip generation
- Rate shopping: get rates from FedEx and UPS, display cheapest/fastest
- Print shipping label (FedEx/UPS API)
- Mark order as shipped, tracking number auto-captured
- Customer notification email with tracking
- Adult signature required flag (configurable per state)

### 7. Allocation and futures support [GROWTH]
**Description:** Limited-release wines allocated to specific customers, and futures/pre-release ordering.
**Files to create:**
- `api/app/Models/Allocation.php`
- `api/app/Models/AllocationCustomer.php`
- `api/database/migrations/xxxx_create_allocations_table.php`
- `api/app/Services/AllocationService.php`
**Acceptance criteria:**
- Define allocation: SKU, eligible customer list, quantity cap per customer, access window
- Notify eligible customers when window opens
- Futures: accept orders + deposits before wine is bottled
- Waitlist for sold-out allocations

### 8. eCommerce demo data
**Files to modify:** `api/database/seeders/EcommerceSeeder.php`
**Acceptance criteria:**
- Store configured with theme, 20 published products, sample orders in various states, promo codes

## API Endpoints

| Method | Path | Description | Auth Scope |
|--------|------|-------------|------------|
| GET | `/api/v1/store/products` | Public product list | Public (keyed) |
| GET | `/api/v1/store/products/{id}` | Product detail | Public |
| POST | `/api/v1/store/cart` | Update cart | Public (session) |
| POST | `/api/v1/store/checkout` | Process checkout | Public |
| GET | `/api/v1/orders` | List orders (admin) | admin+ |
| GET | `/api/v1/orders/{order}` | Order detail | admin+ |
| POST | `/api/v1/orders` | Manual order creation | admin+ |
| POST | `/api/v1/orders/{order}/refund` | Process refund | owner+ |
| POST | `/api/v1/orders/{order}/ship` | Mark shipped + label | admin+ |

## Events

| Event Name | Payload Fields | Materialized State Updated |
|------------|---------------|---------------------------|
| `order_placed` | order details, items, customer, source | orders, stock_levels (commit) |
| `order_fulfilled` | order_id, tracking, carrier | orders, stock_levels (deduct committed) |
| `order_refunded` | order_id, amount, items, reason | orders, stock_levels (restore), payments |
| `payment_captured` | order_id, amount, method, reference | payments |

## Testing Notes
- **Unit tests:** Cart calculations (subtotal, tax, discount, shipping), DTC compliance blocking, promo code validation
- **Integration tests:** Full checkout flow: add to cart → checkout → payment → order created → inventory committed. Refund flow: refund → inventory restored.
- **Critical:** DTC compliance — test that orders to prohibited states are blocked at checkout. Test state-specific quantity limits.
