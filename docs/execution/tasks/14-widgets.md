# Embeddable Widgets

## Phase
Phase 7

## Dependencies
- `01-foundation.md` — API, auth
- `11-ecommerce.md` — Store API for shop widget
- `12-reservations-events.md` — Availability API for booking widget
- `10-wine-club.md` — Club signup API + member portal API

> **Pre-implementation check:** This spec predates completed phases. Before starting, load `CONVENTIONS.md` and review phase recaps for any dependency phases listed above. Patterns, service boundaries, and data model decisions may affect assumptions in this spec.

## Goal
Build embeddable JavaScript Web Components that wineries paste into their existing website (Squarespace, WordPress, Wix, custom HTML). Four widgets: store, reservations, club signup, and member portal. Each renders in Shadow DOM (isolated from host site CSS), communicates directly with the Laravel API, and is themeable to match the winery's brand. Served from Cloudflare CDN.

## Sub-Tasks

### 1. Widget framework and loader
**Description:** Build the widget loader (`widget.js`) that reads `data-widget` and `data-winery` attributes from the script tag and loads the appropriate Web Component.
**Files to create:**
- `widgets/src/loader.js` — main entry point, reads attributes, lazy-loads widget
- `widgets/src/core/BaseWidget.js` — base Web Component class with Shadow DOM, theming, API client
- `widgets/build/` — Vite or Rollup config for production build
- `widgets/package.json`
**Acceptance criteria:**
- Single script tag loads the correct widget based on `data-widget` attribute
- Shadow DOM isolates widget CSS from host site
- Theming via `data-theme` JSON attribute (primary color, font)
- Widget communicates with API using winery's public API key
- Built JS deployed to Cloudflare R2/CDN
- Widget bundle < 50KB gzipped per widget type

### 2. Store widget
**Description:** Embeddable wine shop — browse products, add to cart, checkout with Stripe.
**Files to create:**
- `widgets/src/store/StoreWidget.js`
- `widgets/src/store/components/` — ProductGrid, ProductCard, Cart, Checkout
**Acceptance criteria:**
- Product grid with images, names, prices
- Add to cart, quantity controls
- Cart with subtotal
- Checkout flow with Stripe.js payment
- State compliance (hide products not shippable to visitor's state)
- Mobile responsive within widget bounds

### 3. Reservations widget
**Description:** Embeddable booking calendar for tasting experiences.
**Files to create:**
- `widgets/src/reservations/ReservationsWidget.js`
- `widgets/src/reservations/components/` — ExperienceSelector, Calendar, BookingForm
**Acceptance criteria:**
- Experience selection → date picker → time slot → party details → deposit payment → confirmation
- Real-time availability from API
- Mobile responsive

### 4. Club signup widget
**Description:** Embeddable club signup form with tier selection and card capture.
**Files to create:**
- `widgets/src/club-signup/ClubSignupWidget.js`
**Acceptance criteria:**
- Tier selector with descriptions and perks
- Signup form: name, email, phone, address, card (Stripe.js)
- Creates club member via API
- Confirmation display

### 5. Member portal widget
**Description:** Embeddable member self-service portal (requires member authentication).
**Files to create:**
- `widgets/src/member-portal/MemberPortalWidget.js`
**Acceptance criteria:**
- Member login (email + password or magic link)
- View: current tier, next shipment, shipment history
- Update: shipping address, payment method
- Skip/customize upcoming shipment
- Cancel membership

### 6. Widget documentation and setup guide
**Description:** Create the winery-facing setup instructions (shown in Management Portal settings).
**Files to create:**
- `api/app/Filament/Pages/WidgetSetup.php` — shows embed code snippets
**Acceptance criteria:**
- Auto-generated embed code with winery's API key pre-filled
- Preview of each widget type
- Theme customization instructions
- CORS configuration per widget domain

## API Endpoints (Public Widget Endpoints)

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/public/{slug}/products` | Widget store products |
| POST | `/api/v1/public/{slug}/checkout` | Widget checkout |
| GET | `/api/v1/public/{slug}/availability` | Widget availability |
| POST | `/api/v1/public/{slug}/reservations` | Widget booking |
| POST | `/api/v1/public/{slug}/club/join` | Widget club signup |
| GET | `/api/v1/public/{slug}/member` | Widget member portal |

## Testing Notes
- **Unit tests:** Widget loader (correct widget instantiated for each data-widget value), theme parsing
- **Integration tests:** Embed widget on a test HTML page, verify API calls work cross-origin
- **Critical:** CORS must be locked to registered domains per API key. Test that widget from an unregistered domain is blocked.
