# POS App (Native Tablet)

## Phase
Phase 6

## Dependencies
- `07-kmp-shared-core.md` — MUST be complete. Same sync engine and local DB as Cellar App.
- `01-foundation.md` — API auth, event sync endpoint
- `04-inventory.md` — Case goods SKUs and stock levels (product catalog)
- `02-production-core.md` — Not directly consumed by POS, but POS sales deduct from the same inventory that production creates

## Goal
Build the native tablet POS app for tasting room sales. iOS (SwiftUI) + Android (Jetpack Compose), tablet-optimized layout. Offline-first — the POS must keep processing card AND cash payments when wifi drops. This is the key differentiator: "Keeps taking cards when your wifi goes down." Integrates Stripe Terminal native SDKs for card-present payments with offline capture capability. This module unlocks the Growth tier.

## Sub-Tasks

### 1. Android project setup (Jetpack Compose — tablet layout)
**Description:** Create the Android POS project with tablet-optimized Compose layout. Depends on `:shared` KMP module.
**Files to create:**
- `pos-app/android/` — Android project
- `pos-app/android/build.gradle.kts` — depends on `:shared`, Stripe Terminal SDK
- `pos-app/android/app/src/main/kotlin/.../POSApp.kt`
- `pos-app/android/app/src/main/kotlin/.../navigation/POSNavGraph.kt`
**Acceptance criteria:**
- App builds and launches on Android tablet emulator (10" minimum)
- Shared KMP core accessible
- Tablet layout: product grid on left, cart on right (split-screen)
- Stripe Terminal Android SDK integrated
**Gotchas:** Target tablet screen sizes (10"+). Use adaptive layouts that work on both 10" and 12.9" tablets.

### 2. iOS project setup (SwiftUI — tablet layout)
**Description:** Create the iOS POS project with iPad-optimized SwiftUI layout.
**Files to create:**
- `pos-app/ios/` — Xcode project (iPad only)
- `pos-app/ios/POSApp/POSApp.swift`
- `pos-app/ios/POSApp/Navigation/`
**Acceptance criteria:**
- App builds and launches on iPad Simulator
- Shared KMP framework linked
- iPad layout: split view with product grid and cart
- Stripe Terminal iOS SDK integrated
**Gotchas:** Configure as iPad-only app (no iPhone layout needed for POS). Use SwiftUI's NavigationSplitView for the two-column layout.

### 3. Product catalog browsing and search
**Description:** Display the wine catalog (from case goods SKUs) with categories, search, and quick-add buttons.
**Files to create:**
- Android: `ProductGridScreen.kt`, `ProductCard.kt`
- iOS: `ProductGridView.swift`, `ProductCardView.swift`
**Acceptance criteria:**
- Product grid with images, names, prices
- Categories: wines by type, flights/tastings, merchandise, food, gift cards
- Search by name, SKU, varietal
- Quick-add configurable favorites (most popular items pinned)
- All products cached in local SQLite — works fully offline
- Stock level indicator (in stock / low / out)
**Gotchas:** Product images need to be cached locally for offline display. Use a disk-backed image cache. Don't show placeholder for the 80% of wineries that don't upload product images — use a clean default.

### 4. Cart and tab management
**Description:** Build the cart/tab system — add items, manage quantities, support multiple open tabs per table/party.
**Files to create:**
- Android: `CartScreen.kt`, `TabListScreen.kt`, `TabDetailScreen.kt`
- iOS: `CartView.swift`, `TabListView.swift`, `TabDetailView.swift`
**Acceptance criteria:**
- Add items to cart with quantity controls
- Support multiple open tabs (one per party/table)
- Tab transfer between staff
- Notes per line item and per tab
- Party/table assignment
- Tab totals with tax calculation
- Stored locally — tabs persist through app restart
**Gotchas:** Tax calculation must work offline. Cache the applicable tax rate(s) locally. Support compound tax (state + county + city) where applicable.

### 5. Stripe Terminal SDK integration — reader discovery and connection
**Description:** Integrate Stripe Terminal native SDKs. First sub-task: discover and connect to card readers.
**Files to create:**
- Android: `StripeTerminalManager.kt`
- iOS: `StripeTerminalManager.swift`
- Server: `api/app/Http/Controllers/Api/V1/StripeTerminalController.php` — connection token endpoint
**Acceptance criteria:**
- Discover nearby readers (Bluetooth + USB)
- Connect to selected reader
- Handle reader disconnection gracefully
- Show reader battery/connection status
- Connection token endpoint on server (required by Stripe Terminal SDK)
**Gotchas:** Stripe Terminal requires a connection token from your server on each SDK initialization. This endpoint must be fast and cached (token valid for a few minutes). Supported readers: BBPOS WisePOS E, Stripe Reader S700.

### 6. Stripe Terminal — card-present payment flow
**Description:** Process a card-present payment through the connected reader. Chip, tap, Apple Pay, Google Pay.
**Files to create:**
- Android: `PaymentProcessor.kt`, `CheckoutScreen.kt`
- iOS: `PaymentProcessor.swift`, `CheckoutView.swift`
**Acceptance criteria:**
- Initiate payment with amount → reader prompts for card
- Handle: chip insert, contactless tap, Apple Pay, Google Pay
- Show payment processing state (waiting → processing → approved / declined)
- On approval: write `payment_captured` and `order_placed` events to outbox
- Deduct inventory (optimistic local deduction)
- Print / email / skip receipt
- Handle declined cards gracefully (retry prompt)
**Gotchas:** Payment processing happens in the Stripe Terminal SDK, not your code. Your code handles the pre/post flow. The SDK returns a PaymentIntent — store the payment intent ID as the reference.

### 7. Stripe Terminal — offline card payments
**Description:** Configure Stripe Terminal for offline card capture when wifi is down. The reader stores offline transactions and syncs when connectivity returns.
**Files to create:**
- Extend `StripeTerminalManager` on both platforms
**Acceptance criteria:**
- When offline, reader still captures card payments
- Transactions queued in reader hardware (not in your SQLite — Stripe handles this)
- Cash transactions queued in your local SQLite event log
- On reconnect: Stripe Terminal auto-syncs offline card transactions
- Your sync engine pushes queued cash transactions
- POS shows "offline mode" indicator but does NOT block transactions
- Offline limit: up to ~$5,000 per reader (Stripe-imposed)
**Gotchas:** Stripe Terminal's offline mode has limits and requirements. Read Stripe's offline docs thoroughly. Some reader models support offline better than others. WisePOS E has good offline support.

### 8. Cash payment flow
**Description:** Process cash payments with drawer tracking.
**Files to create:**
- Android: `CashPaymentScreen.kt`
- iOS: `CashPaymentView.swift`
**Acceptance criteria:**
- Enter cash tendered
- Calculate and display change due
- Write `payment_captured` event (payment_method: cash) to outbox
- Track cash in drawer (running total from shift open)
- Works fully offline
**Gotchas:** Cash drawer tracking is per-shift, not per-device. If multiple staff share a device, each has their own shift/drawer.

### 9. Split payments
**Description:** Support splitting a tab across multiple payment methods (by item, by amount, by person).
**Files to create:**
- Android: `SplitPaymentScreen.kt`
- iOS: `SplitPaymentView.swift`
**Acceptance criteria:**
- Split by item: assign items to different payers
- Split by amount: divide total into custom amounts
- Split by person: divide evenly by N people
- Mixed methods: part card, part cash, part gift card
- Each partial payment writes its own event
**Gotchas:** Keep split UI simple — most splits are "split by 2" or "split by item." Don't over-engineer the edge cases.

### 10. Tip handling
**Description:** Tip prompts after payment with configurable percentages.
**Files to create:**
- Android: `TipScreen.kt`
- iOS: `TipView.swift`
**Acceptance criteria:**
- Post-payment tip prompt with configurable % options (15%, 18%, 20%, custom)
- Tip amount added to total and captured with the payment
- Tip tracking per staff member
- Tip pooling vs. individual assignment (configurable)
**Gotchas:** For card payments, tips may need to be captured as a separate charge or included in the original charge depending on timing. Check Stripe Terminal's tip handling capabilities.

### 11. Discount and promo code support
**Description:** Apply discounts at the POS — percentage off, dollar off, club member auto-discount, promo codes.
**Files to create:**
- Android: `DiscountScreen.kt`
- iOS: `DiscountView.swift`
**Acceptance criteria:**
- Manual discount: % off or $ off, per item or per order
- Manager override for ad-hoc discounts (requires PIN)
- Promo code entry and validation
- Club member auto-discount on member identification (card swipe or lookup)
- Tasting fee waiver when purchase exceeds threshold (configurable)
**Gotchas:** Club member discount requires identifying the member — either by card-on-file lookup or manual search. Member data must be cached locally for offline.

### 12. Receipt handling (email + print)
**Description:** Send receipts via email or print to a Bluetooth/USB receipt printer.
**Files to create:**
- Android: `ReceiptManager.kt` (Epson/Star Micronics SDK)
- iOS: `ReceiptManager.swift` (Epson/Star Micronics SDK)
**Acceptance criteria:**
- Email receipt: capture email, queue for send on sync
- Print receipt: format and send to connected printer
- Skip receipt option
- Printer discovery and pairing
**Gotchas:** Use native printer SDKs (Star Micronics, Epson). Printer connection is Bluetooth — separate from card reader. Both need to be managed.

### 13. Shift management (open/close, cash reconciliation)
**Description:** Shift lifecycle: open shift with starting cash count, track throughout the day, close with reconciliation.
**Files to create:**
- Android: `ShiftManagementScreen.kt`
- iOS: `ShiftManagementView.swift`
**Acceptance criteria:**
- Open shift: enter starting cash count, logged-in staff ID
- During shift: running totals of card sales, cash sales, tips
- Close shift: enter ending cash count, system calculates expected vs. actual
- Variance reported (over/short)
- Shift summary exportable
**Gotchas:** PIN-based login for shared devices (multiple staff use the same tablet). Shift belongs to the individual, not the device.

### 14. Wine club signup at POS
**Description:** Sign up new club members directly from the POS during a tasting experience.
**Files to create:**
- Android: `ClubSignupScreen.kt`
- iOS: `ClubSignupView.swift`
**Acceptance criteria:**
- Quick signup form: name, email, phone, address, club tier selection
- Card-on-file capture (for future club charges)
- Writes `club_member_joined` event to outbox
- Offline: member record created locally, card capture deferred until online
- Member discount applies to current tab immediately after signup
**Gotchas:** Card-on-file capture requires a Stripe SetupIntent (different from PaymentIntent). If offline, the card-on-file capture is deferred — flag the member as "payment pending" and capture when connectivity returns.

### 15. Offline stress test
**Description:** Comprehensive offline test — disconnect wifi, process multiple transactions, reconnect, verify everything syncs.
**Test procedure:**
1. Open shift with $200 starting cash
2. Disconnect wifi (airplane mode)
3. Process 5 card payments via Stripe Terminal (offline mode)
4. Process 3 cash payments
5. Process 1 split payment (card + cash)
6. Apply 1 club member discount
7. Sign up 1 new club member
8. Process 1 refund
9. Reconnect wifi
10. Verify: all 10+ transactions sync to server, Stripe offline charges settle, cash drawer totals match, inventory decremented correctly, club member created
**Acceptance criteria:**
- Zero data loss
- Stripe offline payments settle correctly on reconnect
- Cash drawer reconciliation matches expected amounts
- Inventory levels on server reflect all sales
- No duplicate transactions
**Gotchas:** Stripe Terminal offline settlement can take a few minutes after reconnect. Don't fail the test if settlement takes time — verify eventual consistency.

## API Endpoints (Consumed)

| Method | Path | Description |
|--------|------|-------------|
| POST | `/api/v1/auth/login` | Staff login |
| POST | `/api/v1/events/sync` | Batch sync events |
| GET | `/api/v1/skus` | Product catalog |
| GET | `/api/v1/skus/{sku}/stock` | Stock levels |
| POST | `/api/v1/stripe/terminal/connection-token` | Stripe Terminal token |

## Events

| Event Name | Payload Fields | Materialized State Updated |
|------------|---------------|---------------------------|
| `order_placed` | items, customer_id, subtotal, tax, total, tab_id | orders table, stock_levels (deduct) |
| `payment_captured` | order_id, amount, method, stripe_intent_id, tip | payments table |
| `payment_failed` | order_id, amount, method, error | — (logged only) |
| `order_refunded` | order_id, amount, reason, items | orders, stock_levels (restore), payments |
| `tasting_completed` | party_size, experience_type, waived_on_purchase | — (analytics) |
| `club_member_joined` | customer data, tier, signup_source: pos | customers, club_members |

## Testing Notes
- **Android instrumented tests:** Product grid → add to cart → checkout → mock payment. Run on CI tablet emulator.
- **iOS XCTest:** Same flow on iPad simulator.
- **Stripe Terminal:** Test with Stripe's simulated reader in development. Physical reader testing required before launch.
- **CRITICAL:** Offline stress test (sub-task 15) is the most important POS test. Must pass on both physical iPad and Android tablet.
