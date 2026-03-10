# Advanced Payments

## Phase
Phase 7

## Dependencies
- `01-foundation.md` — Stripe billing (SaaS subscription), Stripe Connect onboarding
- `09-pos-app.md` — Stripe Terminal integration (card-present payments)
- `11-ecommerce.md` — Order model (payments attach to orders)
- `10-wine-club.md` — Club processing uses card-on-file charging

## Goal
Build the payment processor abstraction layer supporting Managed Stripe (default) and BYO Processor (Growth+). Implement card-on-file for club charging, gift cards, surcharging/cash discount programs, dispute management, and payment reconciliation. Support Apple Pay and Google Pay for faster checkouts (tokenized via processor, same as card-on-file). The two-mode system is a competitive advantage — managed mode lowers friction for new signups, BYO removes the transaction fee objection for price-sensitive wineries.

## Data Models

- **PaymentProcessorConfig** — `id` (UUID), `tenant_id` (FK), `processor_type` (stripe_managed/stripe_direct/square/braintree), `api_key_encrypted` (encrypted string, nullable for managed), `webhook_secret_encrypted` (encrypted string, nullable for managed), `config` (JSONB — processor-specific settings e.g., location_id for Square), `is_active` (boolean), `test_mode` (boolean), `setup_completed_at` (timestamp, nullable), `created_at`, `updated_at`
  - Relationships: belongsTo Tenant (one active config per tenant)
  - Constraint: only one is_active per tenant

- **StoredPaymentMethod** — `id` (UUID), `customer_id` (FK), `processor_type` (stripe/square/braintree — recorded at save time), `processor_token` (string, the external token from processor), `last_four` (string, 4 digits), `card_brand` (visa/mastercard/amex/discover), `expiry_month` (integer), `expiry_year` (integer), `is_default` (boolean), `created_at`, `updated_at`
  - Relationships: belongsTo Customer, hasMany ClubShipments (via ClubMember.payment_token_id)
  - Constraint: only one is_default per customer

- **GiftCard** — `id` (UUID), `code` (unique, 16-char alphanumeric, case-insensitive), `initial_balance` (integer, cents), `current_balance` (integer, cents), `issued_to_customer_id` (UUID, nullable — unassigned gift cards), `issued_by` (FK users), `is_physical` (boolean), `is_active` (boolean), `expires_at` (timestamp, nullable), `created_at`
  - Relationships: hasMany GiftCardTransactions, belongsTo Customer (optional), belongsTo User (issued_by)
  - Constraint: code is case-insensitive and unique per tenant
  - Note: balance is the source of truth; transactions are audit trail

- **GiftCardTransaction** — `id` (UUID), `gift_card_id` (FK), `order_id` (UUID, nullable), `type` (load/redeem/refund_credit), `amount` (integer, cents — positive for loads, negative for redemptions), `balance_after` (integer, cents), `note` (string, nullable), `created_at`
  - Relationships: belongsTo GiftCard, belongsTo Order (optional)
  - Constraint: balance_after must equal prior transaction's balance_after ± amount

- **Dispute** — `id` (UUID), `payment_id` (FK), `order_id` (FK, nullable), `processor_dispute_id` (string, external ID from Stripe/Square), `status` (needs_response/under_review/won/lost/resolved), `reason` (string, chargeback reason), `amount` (integer, cents), `evidence_due_by` (timestamp), `evidence_submitted_at` (timestamp, nullable), `resolved_at` (timestamp, nullable), `notes` (text, internal investigation notes), `created_at`, `updated_at`
  - Relationships: belongsTo Payment, belongsTo Order (optional)

- **SurchargeConfig** — `id` (UUID), `tenant_id` (FK), `is_enabled` (boolean), `type` (surcharge/cash_discount), `rate` (decimal, e.g., 0.035 for 3.5%), `excluded_states` (JSONB array of state codes where surcharging is illegal), `display_text` (string, e.g., "Processing Fee" or "Cash Discount"), `created_at`, `updated_at`
  - Relationships: belongsTo Tenant (one per tenant)
  - Constraint: type surcharge and cash_discount are mutually exclusive per tenant

## Sub-Tasks

### 1. Payment processor abstraction layer
**Description:** Build the `PaymentProcessor` interface and concrete implementations for Stripe (managed + direct), Square, and Braintree. The factory resolves the correct processor per tenant based on their config. This is the abstraction defined in architecture.md Section 9.
**Files to create:**
- `api/app/Services/Payments/Contracts/PaymentProcessor.php` — interface with methods: charge(PaymentIntent): ChargeResult, refund(string chargeId, int amount): RefundResult, saveCard(Customer, string token): StoredCard, chargeStoredCard(StoredCard, int amount): ChargeResult, createSetupIntent(Customer): SetupIntentResult
- `api/app/Services/Payments/Contracts/PaymentIntent.php` — DTO: customer_id, amount (cents), currency, description, metadata (order_id, etc.)
- `api/app/Services/Payments/Contracts/ChargeResult.php` — DTO: success (bool), charge_id (string, nullable), error_code (string, nullable), error_message (string, nullable), processor_response (array)
- `api/app/Services/Payments/Contracts/RefundResult.php` — DTO: success (bool), refund_id (string, nullable), error_code, error_message
- `api/app/Services/Payments/Contracts/StoredCard.php` — DTO: processor_token, last_four, card_brand, expiry_month, expiry_year
- `api/app/Services/Payments/Contracts/SetupIntentResult.php` — DTO: client_secret (string, for frontend tokenization), setup_intent_id (string, processor-specific)
- `api/app/Services/Payments/StripeConnectProcessor.php` — managed mode, inherits from Stripe Connect platform account
- `api/app/Services/Payments/StripeDirectProcessor.php` — BYO Stripe, charges go to winery's account via Stripe Connect
- `api/app/Services/Payments/SquareProcessor.php`
- `api/app/Services/Payments/BraintreeProcessor.php`
- `api/app/Services/Payments/PaymentProcessorFactory.php` — singleton service that resolves per tenant based on PaymentProcessorConfig
- `api/app/Models/PaymentProcessorConfig.php`
- `api/database/migrations/xxxx_create_payment_processor_configs_table.php`
- `api/app/Services/Payments/EncryptionService.php` — helper to encrypt/decrypt API keys using Laravel's encryption
**Acceptance criteria:**
- Interface methods implemented exactly as defined in architecture Section 9
- Factory reads tenant's active PaymentProcessorConfig and resolves correct implementation
- Managed mode: charges flow through Stripe Connect with `on_behalf_of` set to Stripe Express account ID, platform fee auto-deducted per Stripe Connect mechanics
- BYO mode: charges go directly to winery's own Stripe/Square/Braintree account via their API keys
- All money amounts are integer cents (never float, never string)
- Failed charges return structured ChargeResult with error code and message (no exceptions thrown from charge method)
- API key encryption/decryption transparent to consumers (use Laravel's encrypted cast where possible)
- Stripe processor handles both live and test mode based on test_mode flag in config
- Square and Braintree implementations follow same interface contract (may have processor-specific config in the config JSON field)
- All implementations are testable via mock (use dependency injection, no global config reads)
**Gotchas:** Never store raw API keys in the database unencrypted — use Laravel's encrypted cast or manual encryption. The Stripe Connect account ID (for managed mode) is different from a direct API key (for BYO mode) — both should be in the api_key_encrypted field but tagged in config JSON. Square and Braintree have fundamentally different tokenization flows (Square uses nonces, Braintree uses client tokens) — abstract at the right level (charge/refund/save/saveCard), not at the HTTP request level. Stripe requires `idempotency_key` header for all charge requests to prevent double-charging on retry — implement in StripeProcessor and StripeDirectProcessor. Test mode keys must not charge real cards; verify config.test_mode gates environment selection.

### 2. BYO processor setup wizard
**Description:** Guided multi-step setup in the Management Portal for connecting a winery's own Stripe/Square/Braintree account. This is a Filament wizard page with validation and test transaction execution.
**Files to create:**
- `api/app/Filament/Pages/PaymentProcessorSetup.php` — multi-step wizard using Filament's Form component
- `api/app/Services/Payments/ProcessorTestService.php` — validates credentials and runs test transaction
- `api/app/Http/Controllers/Api/V1/PaymentProcessorController.php` — API endpoints for getting current processor, switching
**Acceptance criteria:**
- Step 1: Select processor type (radio buttons: stripe_managed [default, no setup needed], stripe_direct, square, braintree)
- Step 2 (if BYO selected): Enter API keys (test and live, separate fields). For Stripe, show instruction link to find keys in dashboard.
- Step 3: Run test transaction ($0.50 auth + immediate void) to validate keys
  - Test fires actual charge to the processor's test environment
  - Captures charge_id from successful charge
  - Immediately voids the charge
  - Surface error clearly if test fails (e.g., "Invalid API key" or "Processor responded with: …")
- Step 4: Confirm and activate
  - Show warning: "Switching processors will affect card-on-file charging. Existing stored payment methods will require re-entry."
  - On confirm, set is_active=true and setup_completed_at=NOW()
  - Deactivate previous processor config (is_active=false)
- Validate keys before saving (incorrect keys rejected with clear error)
- After setup, disable wizard step 1-2 (user can re-run test but not re-select processor without downgrade warning)
- Downgrade from BYO back to managed: show confirmation dialog: "Reverting to managed mode will require club members to re-enter their cards before next processing run."
- API endpoint: GET /api/v1/payment-processor (returns current config — processor type, test mode, setup status)
- API endpoint: PUT /api/v1/payment-processor (update config — test mode toggle, activate/deactivate)
**Gotchas:** Switching processors mid-club-cycle is dangerous — stored card tokens are processor-specific and cannot be transferred. The StoredPaymentMethod.processor_type field marks this. During club processing, verify all members' stored card processor matches the active PaymentProcessorConfig.processor_type; if mismatch, skip that member and flag for manual resolution. Test transaction void must succeed — if void fails but charge succeeded, flag for manual refund. Stripe test keys work against test charges only — a common mistake is winery entering live keys and wondering why test transactions fail.

### 3. Card-on-file management
**Description:** Store and manage tokenized payment methods for customers. Used by club processing and repeat orders. PCI compliant via processor tokenization only.
**Files to create:**
- `api/app/Models/StoredPaymentMethod.php`
- `api/database/migrations/xxxx_create_stored_payment_methods_table.php`
- `api/app/Services/Payments/CardOnFileService.php` — save, update, remove, flag expiring
- `api/app/Http/Controllers/Api/V1/PaymentMethodController.php` — CRUD endpoints and expiry check
- `api/app/Jobs/CheckExpiringPaymentMethodsJob.php` — scheduled daily to flag expiry within 30 days
- `api/app/Notifications/CardExpiringNotification.php` — email to customer when card expires soon
**Acceptance criteria:**
- Save card via SetupIntent (Stripe) or equivalent (PCI compliant — token only, no raw card numbers ever handled)
- Multiple cards per customer, one marked is_default=true
- Card update: re-tokenize via processor and update StoredPaymentMethod
- Card removal: delete StoredPaymentMethod (processor-side token cleanup is per-processor; some processors auto-expire unused tokens, others don't)
- Expiry tracking: check expiry_month/expiry_year, flag expiring within 30 days
- Notification to customer 30 days before expiry (sent via email)
- POS app can capture card-on-file during club signup (API endpoint returns SetupIntent client_secret so POS can tokenize)
- List cards endpoint: return only is_default and masked data (last_four, brand, expiry), never raw tokens
- Set default: PATCH /api/v1/customers/{c}/payment-methods/{pm}/set-default
- Constraint: is_default ensures only one card per customer has this flag
**Gotchas:** Card tokens are processor-specific — a Stripe token is useless if the winery switches to Square. The StoredPaymentMethod.processor_type field records which processor issued the token. During club processing, skip members whose stored card processor doesn't match winery's active processor and flag for manual resolution (send notification to member: "Your payment method is incompatible with our new payment system; please update your card."). Expiry check job must handle timezones — use tenant's configured timezone from WineryProfile. Do not delete stored cards, just mark as inactive if replaced (keeps audit trail).

### 4. Gift card system
**Description:** Issue, redeem, and track gift cards (physical and digital). Gift cards are a liability on the winery's books until redeemed.
**Files to create:**
- `api/app/Models/GiftCard.php`
- `api/app/Models/GiftCardTransaction.php`
- `api/database/migrations/xxxx_create_gift_cards_table.php`
- `api/database/migrations/xxxx_create_gift_card_transactions_table.php`
- `api/app/Services/GiftCardService.php` — issue, redeem, check balance, generate report
- `api/app/Filament/Resources/GiftCardResource.php` — CRUD and bulk issuance
- `api/app/Http/Controllers/Api/V1/GiftCardController.php` — public balance check, redeem
- `api/app/Jobs/GenerateGiftCardLiabilityReportJob.php` — monthly job for accounting
**Acceptance criteria:**
- Issue gift cards with specified balance (e.g., $25, $50, $100) and optional expiry
  - Generate unique 16-character alphanumeric code (case-insensitive, format: XXXX-XXXX-XXXX-XXXX for readability)
  - Code format enforced on both entry and lookup (case-insensitive matching)
  - Optionally assign to customer or leave unassigned (unassigned cards can be printed, digital cards emailed)
- Redeem at POS or online checkout (partial redemption supported)
  - Lookup by code, verify is_active and not expired
  - Check balance ≥ requested amount
  - Atomic transaction: create GiftCardTransaction with negative amount, update GiftCard.current_balance
  - Prevent double-spend on concurrent requests (database-level constraint or SELECT FOR UPDATE)
- Balance check by code (public endpoint: GET /api/v1/gift-cards/{code}/balance)
  - Return: current_balance, is_active, expiry_at (if applicable)
  - Do not reveal other data (issuer, customer, transaction history)
- Transaction history (internal): GET /api/v1/gift-cards/{code}/transactions (admin+)
  - List all loads, redemptions, refund credits in chronological order
  - Show balance_after for each
- Gift card liability report (accounting needs this for year-end)
  - Total unredeemed balance grouped by status (active, expired, redeemed)
  - Export as CSV with: code, issued_date, initial_balance, current_balance, expiry_date, status
  - Run monthly and store in sync_logs (for accountant to download)
- Bulk issuance for promotions
  - Filament action: "Issue 100 $25 cards for holiday promotion"
  - Generate 100 unique codes atomically
  - Download CSV for printing or email campaign
- Refund credits: if customer is refunded, can issue a gift card credit (type=refund_credit) or apply to existing card
**Gotchas:** Gift card balances are stored in integer cents, never float. Partial redemption must be atomic — use database transaction with row lock on GiftCard (SELECT FOR UPDATE) to prevent concurrent redemptions. Gift cards are a financial liability — the liability report is legally important for year-end accounting (some states require gift card reserves). Some states have laws about gift card expiration and dormancy fees — make expiry optional and configurable per winery (no enforcement here, just storage). Test partial redemption with concurrent requests (simulate two simultaneous redemptions) to ensure atomicity. Do not allow redemption of expired cards without special override (flag override in logs for audit).

### 5. Surcharging and cash discount programs
**Description:** Configurable surcharge (add % to card payments) or cash discount (discount for cash/ACH) with state-level compliance awareness. This is legally complex — surcharging is prohibited in some states.
**Files to create:**
- `api/app/Models/SurchargeConfig.php`
- `api/database/migrations/xxxx_create_surcharge_configs_table.php`
- `api/app/Services/Payments/SurchargeService.php` — calculate surcharge/discount for a given state
- `api/app/Filament/Pages/SurchargeSettings.php` — admin page to configure
- `api/database/seeders/SurchargeComplianceSeeder.php` — seed list of restricted states
**Acceptance criteria:**
- Toggle surcharge mode or cash discount mode (mutually exclusive via database constraint)
- Configurable rate (e.g., 3.5%) with validation: 0-10% (prevent accidental 35% surcharge)
- Auto-exclude states where surcharging is illegal (Connecticut, Massachusetts, Puerto Rico, etc.)
  - Maintain seed data of prohibited states (update annually if laws change)
  - At checkout or POS: pass customer's state to SurchargeService.calculate() → returns 0% if state is excluded
- Surcharge displayed as separate line item on receipt/invoice with label (e.g., "Processing Fee: +$1.50")
- Cash discount displayed as discount line item with label (e.g., "Cash Discount: -$2.00")
- POS and online checkout both apply surcharge/discount correctly
  - POS: SurchargeService.calculate(state) returns rate, POS multiplies order subtotal
  - Ecommerce: same logic at checkout, included in final total
- API endpoint: GET /api/v1/surcharge-config (returns current config for client-side calculation)
- Admin can view surcharge/discount revenue report (total surcharges collected, total discounts given) by month
**Gotchas:** Surcharging legality changes by state and by card network. Visa/Mastercard have specific rules about surcharge disclosure and caps (currently 3% max for Visa in most states). This feature needs a compliance data seed that can be updated. Do not implement dynamic network detection (too fragile); instead, rely on state-level rules. Cash discount programs have fewer legal restrictions but must be displayed as the "regular price" with a "cash discount" rather than a "card surcharge" (payment networks have strong opinions here). Always test a surcharge state (CA) and a restricted state (MA) to verify surcharge is applied/excluded correctly.

### 6. Dispute and chargeback management
**Description:** UI for viewing and managing payment disputes/chargebacks from the processor. Chargebacks are rare but high-stakes — every won dispute prevents a chargeback fee.
**Files to create:**
- `api/app/Models/Dispute.php`
- `api/database/migrations/xxxx_create_disputes_table.php`
- `api/app/Filament/Resources/DisputeResource.php` — table with status filtering, detail view, evidence upload
- `api/app/Services/Payments/DisputeService.php` — submit evidence, transition status
- `api/app/Listeners/StripeDisputeWebhookHandler.php` — webhook handler for Stripe disputes
- `api/app/Notifications/DisputeOpenedNotification.php` — alert to owner when dispute is opened
- `api/app/Http/Controllers/Api/V1/DisputeController.php` — API for dispute details and summary report
**Acceptance criteria:**
- Disputes auto-created from Stripe webhook (charge.dispute.created)
  - Webhook received → validate signature → create Dispute record with status=needs_response
  - Fetch dispute details from Stripe and populate amount, reason, evidence_due_by
  - Log in sync_logs for tracking
- Status tracking: needs_response → under_review → won/lost/resolved
  - Owner marks as under_review when investigation begins
  - Owner submits evidence (file upload) → evidence_submitted_at set
  - Stripe resolves dispute → webhook charge.dispute.closed received → status = won/lost, resolved_at set
- Evidence submission deadline displayed prominently in Filament (red warning if < 7 days)
  - Calculate days_remaining = evidence_due_by - NOW()
  - Show in table as "3 days remaining" or "Overdue"
  - Prevent submission after deadline (hard block, show error)
- Internal notes field for tracking investigation work
  - Staff can add notes (text area in Filament detail view)
  - Each note includes who wrote it and when (created_at)
- Dispute summary report (count, win rate, total $ disputed) by month
  - API: GET /api/v1/disputes/summary?month=2024-03
  - Returns: total_disputes, won_count, lost_count, win_rate_pct, total_amount
  - Materialized monthly (job runs on 1st of month for prior month's disputes)
- Webhook handler: Stripe sends charge.dispute.closed event with outcome (won/lost)
  - Update Dispute.status and resolved_at
  - Send notification to owner: "Dispute for Order #123 was won!" or "…was lost"
  - Link to Order so owner can see what was actually disputed
**Gotchas:** Dispute evidence deadlines are strict — Stripe closes disputes automatically if no evidence submitted by deadline. Surface the deadline prominently and send email reminder at 14 days and 7 days before deadline. Wine-specific dispute reason: "I didn't order this" is common for club charges where the member forgot they signed up — handle with member email verify (can club member confirm email on file? If yes, escalate to owner for review). Dispute webhook must idempotently handle re-deliveries (Stripe may retry webhook if it doesn't get a 200 response) — use unique processor_dispute_id to check for duplicates.

### 7. Payment reconciliation report
**Description:** Match payments received against orders, and match processor payouts against payments. Reconciliation is a daily pain point for accountants — this report eliminates manual matching.
**Files to create:**
- `api/app/Filament/Pages/PaymentReconciliation.php` — Livewire page with tabs: Daily Payments, Processor Payouts, Unmatched Items
- `api/app/Services/Payments/ReconciliationService.php` — matching logic and reporting
- `api/app/Jobs/SyncProcessorPayoutsJob.php` — scheduled job to fetch payouts from Stripe/Square and record
- `api/app/Models/ProcessorPayout.php` — one record per payout received from processor
- `api/database/migrations/xxxx_create_processor_payouts_table.php`
**Acceptance criteria:**
- Daily view: sum of payments captured today vs. sum of today's orders total
  - Payments tab: filterable table of all payments (date, order_id, amount, method, reference)
  - Flag mismatches (e.g., $10,000 in payments but only $9,500 in orders) with row highlighting
  - Common causes: pending orders not yet charged, refunds processed, gift cards used (not payment), surcharges (inflating payment total)
- Processor payout matching: sum of Stripe payouts vs. sum of payments
  - Payout tab: table of payouts received (date, amount, payout_id, status)
  - Match logic: for each payout, sum all charges in Stripe with payout_id = this payout ID
  - Flag mismatches (refunds, disputes, platform fees all affect payout amount vs. gross charges)
  - Show: gross charges, minus refunds, minus disputes, minus platform fee = net payout
- Unmatched payment detection
  - Payments with no order_id or orders with no payment_id
  - List with links to order/payment detail for investigation
- Export for accountant (CSV): columns: date, order_id, amount, method, payout_id, status, notes
  - Include all payments and payouts for selected date range
  - Accountant can import into QuickBooks for final reconciliation
- Filter by date range, processor (if multiple active), channel (online, pos, club)
  - Default: last 7 days
  - Date picker UI in Filament
- Scheduled job: daily at 6 AM fetch Stripe payouts and upsert into ProcessorPayout table
  - Job queries Stripe API: List all payouts since last_sync_at
  - Upsert each payout (idempotency key = payout_id)
  - Update last_synced_at in PaymentProcessorConfig
**Gotchas:** Stripe payouts are batched and delayed (typically 2 business days). The reconciliation must account for timing differences — don't expect today's charges to appear in today's payout. Refunds and disputes affect payout amounts and must be included in the matching logic. A $100 charge that's later 50% refunded = $50 appears in payout (net), not gross. Payout fetch job may fail if Stripe API rate-limited — implement exponential backoff and silent retry (don't block owner with error, just defer sync to next run). Some charges are held (under review for fraud) and don't appear in payouts immediately — surface this as a status in payout detail.

## API Endpoints

| Method | Path | Description | Auth Scope |
|--------|------|-------------|------------|
| POST | `/api/v1/payments/charge` | Process a payment (one-time card, cash, etc.) | Authenticated |
| POST | `/api/v1/payments/refund` | Process refund (full or partial) | admin+ |
| GET | `/api/v1/customers/{c}/payment-methods` | List stored cards | Authenticated |
| POST | `/api/v1/customers/{c}/payment-methods` | Save card on file (SetupIntent) | Authenticated |
| PATCH | `/api/v1/customers/{c}/payment-methods/{pm}/set-default` | Set default card | Authenticated |
| DELETE | `/api/v1/customers/{c}/payment-methods/{pm}` | Remove card | Authenticated |
| GET | `/api/v1/gift-cards/{code}/balance` | Check gift card balance (public) | Public |
| POST | `/api/v1/gift-cards` | Issue gift card | admin+ |
| POST | `/api/v1/gift-cards/{code}/redeem` | Redeem gift card at checkout/POS | Authenticated |
| GET | `/api/v1/gift-cards/{code}/transactions` | Gift card transaction history | admin+ |
| GET | `/api/v1/disputes` | List disputes | admin+ |
| GET | `/api/v1/disputes/{id}` | Get dispute detail with evidence submission form | admin+ |
| POST | `/api/v1/disputes/{id}/evidence` | Submit evidence file(s) for dispute | admin+ |
| PATCH | `/api/v1/disputes/{id}` | Update dispute notes and status | admin+ |
| GET | `/api/v1/disputes/summary` | Dispute stats (count, win rate, total $) | admin+ |
| GET | `/api/v1/payment-processor` | Get current payment processor config | owner+ |
| PUT | `/api/v1/payment-processor` | Update processor config (test mode, activate/deactivate) | owner+ |
| GET | `/api/v1/surcharge-config` | Get surcharge/discount config (for client-side calc) | Authenticated |
| GET | `/api/v1/payments/reconciliation` | Reconciliation report (daily/payout/unmatched) | accountant+ |
| GET | `/api/v1/payments/reconciliation/export` | Export reconciliation as CSV | accountant+ |

## Events

| Event Name | Payload Fields | Materialized State Updated |
|------------|---------------|---------------------------|
| `payment_captured` | order_id, amount, method (card/gift_card/cash), processor, processor_reference | payments table |
| `payment_failed` | order_id, amount, error_code, error_message, retry_count | payments table |
| `payment_refunded` | payment_id, amount, reason, refunded_by_user_id | payments table, order status |
| `card_tokenized` | customer_id, processor_type, last_four, brand | stored_payment_methods table |
| `card_deleted` | customer_id, payment_method_id | stored_payment_methods table (soft delete or is_active flag) |
| `gift_card_issued` | gift_card_id, code, balance, issued_to_customer_id, issued_by_user_id | gift_cards table |
| `gift_card_redeemed` | gift_card_id, amount, order_id, balance_after | gift_card_transactions table, gift_cards (balance) |
| `gift_card_expired` | gift_card_id, balance | gift_card_transactions table (refund_credit entry) |
| `dispute_opened` | payment_id, order_id, amount, reason, evidence_due_by, processor_dispute_id | disputes table |
| `dispute_evidence_submitted` | dispute_id, file_count | disputes table |
| `dispute_resolved` | dispute_id, outcome (won/lost), amount_recovered | disputes table |
| `processor_switched` | old_processor_type, new_processor_type, setup_completed_at | payment_processor_configs table |
| `surcharge_applied` | order_id, surcharge_amount, rate, customer_state | order totals |

## Testing Notes

- **Unit tests:**
  - Processor abstraction: each implementation (Stripe, Square, Braintree) correctly processes charges/refunds via mock. Test both success and failure paths (invalid card, insufficient funds).
  - Gift card balance math: load, partial redeem, full redeem, refund credit. Verify balance_after calculation.
  - Surcharge calculation: apply 3.5% to $100 order = $103.50. Test state exclusion: MA order should have 0% surcharge applied.
  - Reconciliation matching: sum of payments = sum of orders (happy path); detect mismatches (refund, pending charge).
  - Card expiry flagging: create card expiring in 25 days, run check job, verify is flagged and notification queued.

- **Integration tests:**
  - Managed vs. BYO processor switching: place order with managed → verify charge routed through Stripe Connect. Switch to BYO → place order → verify charge routed to winery's account. Verify stored cards are flagged as mismatched.
  - Card-on-file save → charge cycle: customer saves card via SetupIntent → charge that card for club processing → verify StoredPaymentMethod.processor_token matches charged card.
  - Gift card lifecycle: issue $50 card → lookup balance (returns $50) → redeem $30 (balance becomes $20) → redeem remaining $20 (balance 0, is_active still true) → attempt redeem $1 (fails, insufficient balance).
  - Dispute webhook: Stripe sends charge.dispute.created → handler creates Dispute record with status=needs_response → verify email sent to owner. Stripe sends charge.dispute.closed with outcome=won → update status, verify notification.
  - Club processing with processor switch: create club members with cards → switch processor → attempt charge → verify handler detects processor mismatch, skips member, flags for manual resolution.
  - Surcharge state compliance: checkout in CA (surcharge state) → verify 3.5% added. Checkout in MA (prohibited state) → verify 0% surcharge. Same winery, different states.

- **Critical:**
  - NEVER store raw card numbers or PCI data. Verify every payment flow uses processor tokenization only. Audit code for any string that looks like a card number.
  - Surcharging disabled for excluded states. Create test: order from MA address → verify surcharge not applied.
  - Gift card partial redemption must be atomic. Write concurrent test: two simultaneous redemptions of same card for amounts that sum to > balance. Verify only one succeeds, the other fails.
  - Dispute deadline enforcement: create dispute with deadline 5 days away → attempt evidence upload 6 days later → verify rejected with "deadline passed" error.
  - Processor token audit trail: save card → delete record → verify StoredPaymentMethod deleted but GiftCardTransaction or Club history still references it (no foreign key required).

