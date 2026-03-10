# Accounting Integrations

## Phase
Phase 7

## Dependencies
- `01-foundation.md` — event log (financial events trigger sync jobs)
- `05-cost-accounting.md` — COGS data, cost ledger entries to sync
- `11-ecommerce.md` — Orders and payments to sync as invoices/receipts
- `10-wine-club.md` — Club processing charges to sync as invoices
- `15-payments-advanced.md` — Payment reconciliation and dispute tracking

## Goal
Two-way sync with QuickBooks Online and Xero. Push: invoices from orders, COGS journal entries from bottling, payment receipts, refunds. Pull: payment confirmations, account balances, chart of accounts. Wineries currently spend hours reconciling their winery software with their accounting software — this integration eliminates double-entry and reduces monthly close from days to hours. Sync is automatic on order and bottling events, with a manual re-sync button for edge cases. Uses event-driven push (Order → Invoice Sync Job queued) and scheduled pull (daily pull of payment status and balances).

## Data Models

- **AccountingConnection** — `id` (UUID), `tenant_id` (FK), `provider` (quickbooks_online/xero), `access_token_encrypted` (encrypted string), `refresh_token_encrypted` (encrypted string, nullable for Xero — uses long-lived tokens), `token_expires_at` (timestamp), `company_id` (string, QBO Company ID), `realm_id` (string, QBO Realm ID — alternative to Company ID), `tenant_id_xero` (string, Xero Tenant ID), `is_active` (boolean), `last_synced_at` (timestamp, nullable), `last_error` (text, nullable — last sync error message), `created_at`, `updated_at`
  - Relationships: belongsTo Tenant (one per provider per tenant), hasMany AccountMappings, hasMany SyncLogs
  - Note: company_id and realm_id are QBO-specific, tenant_id_xero is Xero-specific

- **AccountMapping** — `id` (UUID), `accounting_connection_id` (FK), `vinesuite_category` (enum: wine_sales, merch_sales, club_sales, wholesale_sales, tasting_fees, shipping_revenue, cogs_fruit, cogs_materials, cogs_labor, cogs_overhead, inventory_asset, accounts_receivable, gift_card_liability, returns_credit), `external_account_id` (string, ID in QBO/Xero), `external_account_name` (string, cached from provider), `external_account_type` (enum from provider: asset/liability/equity/revenue/expense), `created_at`, `updated_at`
  - Relationships: belongsTo AccountingConnection
  - Constraint: one vinesuite_category per AccountingConnection (cannot map same category twice)

- **SyncLog** — `id` (UUID), `accounting_connection_id` (FK), `direction` (push/pull), `entity_type` (invoice/journal_entry/payment/refund/account/tax_item), `entity_id` (UUID from VineSuite, nullable for pull syncs), `external_id` (string, ID in QBO/Xero), `status` (pending/success/failed/skipped), `error_code` (string, nullable), `error_message` (text, nullable), `payload_snapshot` (JSONB — what was sent to provider), `response_snapshot` (JSONB — what provider returned), `retry_count` (integer), `next_retry_at` (timestamp, nullable), `created_at`, `updated_at`
  - Relationships: belongsTo AccountingConnection
  - Indexes: (accounting_connection_id, created_at), (status, next_retry_at for retry jobs)

## Sub-Tasks

### 1. OAuth connection flow and token management
**Description:** Implement OAuth2 connection for QuickBooks Online and Xero, including automatic token refresh. Tokens are short-lived and must be refreshed proactively to keep sync active.
**Files to create:**
- `api/app/Models/AccountingConnection.php`
- `api/database/migrations/xxxx_create_accounting_connections_table.php`
- `api/app/Services/Accounting/QuickBooksAuthService.php` — OAuth2 flow, token refresh, company ID/Realm ID lookup
- `api/app/Services/Accounting/XeroAuthService.php` — OAuth2 flow, token refresh, tenant ID lookup
- `api/app/Services/Accounting/Contracts/AccountingAuthService.php` — interface
- `api/app/Http/Controllers/AccountingOAuthController.php` — handles OAuth redirect and callback
- `api/app/Http/Controllers/Api/V1/AccountingConnectionController.php` — API for connection status and disconnect
- `api/app/Jobs/RefreshAccountingTokenJob.php` — scheduled daily, refreshes tokens before expiry
- `api/app/Services/EncryptionService.php` — reuse from payments, or create accounting-specific wrapper
**Acceptance criteria:**
- One-click "Connect to QuickBooks Online" / "Connect to Xero" button in Management Portal (Settings > Integrations)
- OAuth2 redirect flow with PKCE (especially important for Xero)
  - User clicks "Connect to [Provider]"
  - Redirected to provider's OAuth consent screen
  - User grants permission to read/write invoices, accounts, payments
  - Provider redirects back to callback handler with authorization code
  - Callback exchanges code for access + refresh tokens
  - Tokens stored encrypted in database
- Company/Realm ID lookup (QBO): OAuth callback queries QBO GetCompanyInfo to find Company ID and Realm ID
- Tenant ID lookup (Xero): OAuth callback queries Xero GET /Connections to find Tenant ID
- Tokens stored encrypted using Laravel's encryption (use encrypted cast on model)
- Automatic token refresh before expiry
  - QBO access tokens expire in 60 minutes, refresh tokens in 100 days
  - Xero access tokens expire in 30 minutes, refresh tokens never expire
  - Schedule daily job (e.g., 2 AM) to check all active connections
  - For each connection where token_expires_at <= NOW() + 1 hour, call refresh
  - Update access_token, refresh_token, token_expires_at in database
  - If refresh fails (invalid refresh token, user revoked), set is_active=false and notify owner
- Disconnect option: DELETE /api/v1/accounting/disconnect/{provider}
  - Mark is_active=false
  - Clear tokens (optional: revoke with provider's API if they support it)
  - Clear all AccountMappings for this connection (or mark unmapped, keeping history)
- Connection health indicator in portal (Management Portal > Integrations > [Provider] section)
  - Show: "Connected as [Company Name] — Last synced 2 hours ago"
  - If error: "Connection failed: [error_message]. Last successful sync: [date]."
  - Refresh button: manually trigger RefreshAccountingTokenJob
- Test connection: GET /api/v1/accounting/test/{provider}
  - Calls RefreshAccountingTokenJob for that connection
  - Returns success or error (useful for debugging token issues)
**Gotchas:** QBO tokens expire fast (60 minutes) — proactive refresh is critical. If a token is not refreshed before expiry, the next sync will fail with "invalid_request" and require user to reconnect. Xero uses PKCE (Proof Key for Code Exchange) — don't skip this, it's required for their OAuth2. QBO uses Realm ID (not Company ID) for API calls — store both in case company_id is used elsewhere. Xero connection may get stale if refresh isn't called — a winery who doesn't log in for 2 months needs their token refreshed anyway (use background job, not just on-login). Test token refresh manually by setting token_expires_at to NOW() - 1 day and running the job to verify it refreshes.

### 2. Chart of accounts mapping
**Description:** Map VineSuite revenue/expense categories to the winery's specific accounts in QBO/Xero. All categories must be mapped before sync is enabled.
**Files to create:**
- `api/app/Models/AccountMapping.php`
- `api/database/migrations/xxxx_create_account_mappings_table.php`
- `api/app/Services/Accounting/AccountMappingService.php` — pull accounts, suggest mappings, validate mapping
- `api/app/Filament/Pages/AccountingMapping.php` — Livewire page with account picker UI
- `api/app/Http/Controllers/Api/V1/AccountMappingController.php` — API for getting/setting mappings
**Acceptance criteria:**
- Pull chart of accounts from QBO/Xero on connection
  - After OAuth callback, fetch GetCompanyInfo (QBO) or GET /Accounts (Xero)
  - Cache account list in database (don't re-fetch every time)
  - Provide refresh button in portal: "Sync Chart of Accounts" (calls provider API and updates cache)
- Show mapping UI: left column VineSuite categories, right column dropdown of their accounts
  - Categories: wine_sales, merch_sales, club_sales, wholesale_sales, tasting_fees, shipping_revenue, cogs_fruit, cogs_materials, cogs_labor, cogs_overhead, inventory_asset, accounts_receivable, gift_card_liability, returns_credit
  - Dropdown filtered by account type (revenue → revenue accounts, expenses → expense accounts, etc.)
  - Show account number + name (e.g., "4000 — Wine Sales" or "5010 — Cost of Goods Sold")
- Default suggestions based on account type
  - wine_sales → first revenue account or manually pick "Wine Sales"
  - cogs_* → first expense account
  - inventory_asset → first asset account
  - Suggestions are hints, not automatic mapping (user must confirm)
- All categories must be mapped before sync is enabled
  - On save: validate all 14 categories have a mapping
  - If unmapped: show red "Required" label, disable Save button
  - Error message: "Missing mapping: [category], [category]"
- Mapping is re-editable: change category mapping, save, affects future syncs only
  - Past synced transactions linked to old account remain in that account (don't rewrite history)
  - Show warning: "Changing account mappings only affects future syncs. Past invoices in [old account] will remain there."
- API endpoint: GET /api/v1/accounting/mapping
  - Returns: list of VineSuite categories with their current mappings (account_id, account_name)
  - Also returns: list of available accounts from provider (for dropdown on frontend)
- API endpoint: PUT /api/v1/accounting/mapping
  - Accepts: { "mappings": [{ "vinesuite_category": "wine_sales", "external_account_id": "4000" }, ...] }
  - Validates: all 14 categories present, all account IDs valid in provider
  - Saves or returns validation errors
**Gotchas:** Wineries have wildly different charts of accounts. Some have one "Sales" account, others have 50+ revenue accounts by vintage or channel. Allow flexibility in mapping (multiple VineSuite categories can map to same account if winery prefers consolidated). Always pull their latest chart of accounts before showing mapping UI (accounts can be added in QBO/Xero at any time, don't want stale dropdown). Xero account numbers are more structured (format varies); QBO uses numeric codes. Both should display as "CODE — Name" for clarity. Test with a real QBO/Xero test company during development to catch account name/code mismatches.

### 3. Invoice and payment push sync
**Description:** Push invoices (from orders) and payment receipts to QBO/Xero automatically when orders are placed/paid. Refunds are pushed as credit memos or refund line items.
**Files to create:**
- `api/app/Services/Accounting/Contracts/AccountingProvider.php` — interface: pushInvoice, pushPayment, pushRefund, pushJournalEntry, pullAccountBalance, pullPaymentStatus
- `api/app/Services/Accounting/QuickBooksProvider.php` — QBO-specific implementation
- `api/app/Services/Accounting/XeroProvider.php` — Xero-specific implementation
- `api/app/Jobs/SyncInvoiceToAccountingJob.php` — queued job to push order to QBO/Xero
- `api/app/Jobs/SyncPaymentToAccountingJob.php` — queued job to attach payment receipt to invoice
- `api/app/Jobs/SyncRefundToAccountingJob.php` — queued job to push refund/credit memo
- `api/app/Listeners/OrderPlacedListener.php` — dispatch SyncInvoiceToAccountingJob on order_placed event
- `api/app/Listeners/PaymentCapturedListener.php` — dispatch SyncPaymentToAccountingJob on payment_captured event
- `api/app/Listeners/PaymentRefundedListener.php` — dispatch SyncRefundToAccountingJob on payment_refunded event
- `api/app/Exceptions/AccountingSyncException.php` — custom exception with retry-able flag
**Acceptance criteria:**
- Order placed → invoice created in QBO/Xero with correct line items and mapped accounts
  - Data pushed: order date, customer name, line items (SKU, description, quantity, unit price, account)
  - Invoice total calculated correctly (includes surcharges, excludes gift cards)
  - For club orders: invoice shows "Club Shipment" with member name
  - For wholesale orders: invoice includes credit terms (Net 30, Net 60) if applicable
  - QBO: Uses line items with Account IDs; Xero: Uses line items with Account Codes
  - Tax handling: push tax amount as-is (don't try to map to Xero tax rates — too fragile)
- Payment captured → payment receipt attached to invoice in QBO/Xero
  - Data pushed: payment date, amount, payment method (card, check, ACH)
  - QBO: Deposit → Journal Entry (debit Bank, credit invoice account)
  - Xero: Payment allocation to invoice
- Refund processed → credit memo or refund line item synced
  - Full refund: create credit memo in QBO, full-amount refund in Xero
  - Partial refund: adjust line items or create line-level credit in both
- Club charges create invoices (batch — one per member per run)
  - Job: SyncInvoiceToAccountingJob receives array of orders (from club processing run)
  - Loops: for each order, push invoice
  - QBO rate limit: 500 requests/min per company — batch endpoint preferred where available
  - If rate limited (429), catch and re-queue with exponential backoff
- Wholesale orders create invoices with credit terms
  - Data includes: InvoiceDate, DueDate (calculated from credit terms, e.g., +30 days)
  - Both QBO and Xero support credit terms via field mappings
- Sync is queued (not synchronous) — failures don't block winery operation
  - Job runs with `delay()` if immediate sync fails (retry in 30 seconds)
  - Failures are logged to SyncLog table with error message for review
- SyncLog records every push attempt with payload snapshot
  - On success: SyncLog.status = success, external_id = invoice ID from QBO/Xero
  - On failure: SyncLog.status = failed, error_code and error_message populated, retry_count incremented
- Failed syncs retried (3 attempts with exponential backoff)
  - Attempt 1: immediate
  - Attempt 2: delay 5 minutes
  - Attempt 3: delay 30 minutes
  - After 3 attempts, mark as failed and notify owner via activity log
  - Admin can manually retry from SyncLog detail view (Filament page)
- Duplicate prevention: check for existing external_id before creating
  - Before pushing: lookup SyncLog where entity_id = order_id and status = success
  - If found and external_id already exists in QBO/Xero, skip push
  - Prevent accidental duplicate invoices if sync is accidentally run twice
- Async idempotency: if same order queued twice before first sync completes
  - Second job checks if SyncLog already has success entry for this order
  - If yes, skip (don't create duplicate)
  - If no, proceed (first job may still be processing)
**Gotchas:** QBO has rate limits (500 requests/minute per company) — batch club charges (300+ invoices) must be throttled or use batch endpoint. QBO's Batch API is not recommended (fragile); instead, spread jobs across multiple job cycles (e.g., charge 50 per second). Tax handling differs between QBO and Xero — QBO expects tax as a line item, Xero uses tax rates and calculates tax. Don't try to map VineSuite tax % to Xero tax rates; just push total tax amount as a separate line. Wholesale credit terms (Net 30/60) are not universally supported — check both QBO and Xero's current API docs to confirm DueDate field works. Test with a real QBO/Xero test company to catch API surprises (e.g., required fields that docs don't mention).

### 4. COGS journal entry push
**Description:** Push COGS journal entries to QBO/Xero when bottling is completed. COGS crystallizes at bottling, so this is critical for monthly accounting.
**Files to create:**
- `api/app/Jobs/SyncCOGSJournalEntryJob.php`
- `api/app/Listeners/BottlingCompletedListener.php` — dispatch job on bottling_completed event
- `api/app/Services/Accounting/COGSCalculationService.php` — calculate per-bottle COGS for bottling (reuse from cost-accounting module)
**Acceptance criteria:**
- Bottling completed → journal entry synced: debit COGS, credit Inventory Asset
  - Entry includes: bottling date, lot/SKU, per-bottle COGS, total bottles, total amount
  - Journal entry memo: "Bottling Run [RunID]: [Lot Name] — [Vintage] [Variety]"
  - Memo also includes: cost allocation breakdown (if available from COGS ledger)
- Configuration option: per-event or monthly rollup
  - Winery setting: "Push COGS entries" → dropdown: [Immediately per bottling] or [Monthly batch on 1st]
  - If monthly: accumulate all COGS entries for month, push as single journal entry on 1st
  - If immediate: push journal entry as each bottling is completed
- Journal entry structure:
  - Debit: COGS account (mapped from SurchargeConfig.cogs_* categories, depending on cost type)
  - Credit: Inventory Asset account (mapped)
  - Amount: total COGS for this bottling run
  - Memo: detailed breakdown
- SyncLog recorded for each journal entry push
- Retry logic: 3 attempts with exponential backoff (same as invoice sync)
- Monthly reconciliation: provide report showing all journal entries pushed in a month
  - Report accessible from accounting integrations page
  - Export as CSV: date, lot, variety, bottles, cogs_total, qbo_journal_id
**Gotchas:** Some accountants prefer one journal entry per bottling run (granular), others prefer monthly batch (consolidated). Make it configurable. COGS journal entries are the most scrutinized sync item — accountants will compare these against their own calculations. Include enough detail in the memo for them to verify (lot name, vintage, variety, per-bottle COGS breakdown if available). If bottling run includes multiple cost types (fruit, materials, labor), create separate debit lines per cost type or aggregate — check what winery prefers (ask during mapping setup). Test with a realistic bottling run (e.g., 500 bottles at $12.50 COGS each = $6,250 journal entry).

### 5. Pull sync (payment confirmations, account balances)
**Description:** Pull data back from QBO/Xero — primarily payment status on outstanding invoices and account balances for dashboard display.
**Files to create:**
- `api/app/Jobs/PullAccountingUpdatesJob.php` — scheduled job (runs daily)
- `api/app/Services/Accounting/QuickBooksProvider.php` — implement pullPaymentStatus, pullAccountBalance
- `api/app/Services/Accounting/XeroProvider.php` — implement pullPaymentStatus, pullAccountBalance
**Acceptance criteria:**
- Scheduled pull (daily at 3 AM): check payment status on synced invoices
  - Query QBO/Xero for all invoices created by VineSuite sync (marked via memo or stored external_id)
  - For each invoice, check its status (Sent, Viewed, Paid, Overdue)
  - For each paid invoice, find corresponding Order in VineSuite and update payment status if different
- Update order payment status if invoice is marked paid in QBO/Xero
  - In VineSuite: Order.payment_status = pending → check QBO → if invoice paid → set to completed
  - Useful if customer paid invoice directly in QBO (e.g., via bank transfer that customer recorded) without going through VineSuite checkout
  - Only auto-update if QBO mark is "Paid" with high confidence
- Pull account balances for display on financial dashboard
  - Query configured accounts (wine_sales, cogs_*, inventory_asset, accounts_receivable)
  - Display as: "Total Revenue (YTD): $50,000" (sum of wine_sales, club_sales, merch_sales accounts)
  - Update once daily or on-demand when user opens dashboard
- Detect and flag manual changes in QBO/Xero that conflict with VineSuite data
  - If invoice in QBO has been manually edited after sync (different total, different date), flag for review
  - If invoice has been deleted in QBO but order still exists in VineSuite, flag
  - Surface flags in activity log: "Invoice for Order #123 was manually edited in QBO. Verify amounts match."
- Read-only cautious: never overwrite VineSuite data from accounting
  - Only update order.payment_status if order exists and it's a safe transition
  - Never delete or refund orders based on QBO/Xero state
  - Flag and alert for human review on conflicts (don't auto-correct)
**Gotchas:** Pull sync should be read-only cautious — never overwrite VineSuite data. If an accountant deletes an invoice in QBO, don't delete the order in VineSuite — flag it. Payment status pull is a nice-to-have but low priority — implement after push sync is solid. Account balance pull is more critical for the financial dashboard. Query performance: if winery has thousands of invoices, pulling all to find ones created by VineSuite will be slow. Store external_id in SyncLog (already doing this) and use it to query QBO/Xero efficiently (e.g., "filter by invoice ID in list").

### 6. COGS export for non-integrated accounting
**Description:** CSV export of COGS data formatted for manual import into any accounting system. Fallback for wineries not on QBO/Xero.
**Files to create:**
- `api/app/Services/Accounting/COGSExportService.php`
- `api/app/Http/Controllers/Api/V1/COGSExportController.php`
- `api/app/Filament/Pages/COGSExport.php` — UI for selecting date range and downloading
**Acceptance criteria:**
- Export COGS data as CSV with proper structure for manual import
  - Grouped by: lot, SKU, vintage, variety (one row per bottling run)
  - Columns: date, lot_name, variety, vintage, sku, bottles, per_bottle_cogs, total_cogs, cost_breakdown_json
  - Sort by date descending
- Journal entry format compatible with QBO and Xero
  - Include separate debit/credit columns: debit_account, debit_amount, credit_account, credit_amount
  - Format: "5010 - COGS" | 6250.00 | "1200 - Inventory" | 6250.00
  - Include memo column for bottling run details
  - This format is importable as a journal entry in both systems
- Date range filter (e.g., "Jan 1 - Dec 31, 2024")
  - Default: last 365 days
  - UI: date picker in Filament page
- Downloadable from portal: Management Portal > Integrations > [Export COGS] button
  - Generates CSV on-demand, returns as download attachment
  - Filename: cogs_export_2024-01-31.csv
- Note: this is purely a backup/fallback export, not a replacement for integrated sync
**Gotchas:** This export must be accountant-friendly (proper debits/credits, correct account format). Test with an accountant to verify they can import it into their system. Format strings (account codes) may vary by accounting system — provide instructions or template for how to customize before import. Include a README or header row explaining the format.

## API Endpoints

| Method | Path | Description | Auth Scope |
|--------|------|-------------|------------|
| GET | `/api/v1/accounting/connection` | Get current connection status (provider, company name, last sync time) | owner+ |
| POST | `/api/v1/accounting/connect/{provider}` | Start OAuth flow redirect to provider | owner+ |
| GET | `/api/v1/accounting/callback` | OAuth callback handler (receives code, exchanges for tokens) | Public (callback URL from provider) |
| DELETE | `/api/v1/accounting/disconnect/{provider}` | Disconnect and revoke tokens | owner+ |
| POST | `/api/v1/accounting/test/{provider}` | Test connection (refresh token and verify access) | owner+ |
| GET | `/api/v1/accounting/accounts` | Pull and cache chart of accounts from provider | owner+ |
| GET | `/api/v1/accounting/mapping` | Get current account mappings | owner+ |
| PUT | `/api/v1/accounting/mapping` | Save account mappings (validate and enable sync) | owner+ |
| GET | `/api/v1/accounting/sync-log` | View sync history (filter by status, entity type, date) | accountant+ |
| GET | `/api/v1/accounting/sync-log/{id}` | Get sync log entry detail (payload, response, error) | accountant+ |
| POST | `/api/v1/accounting/sync-log/{id}/retry` | Retry failed sync | accountant+ |
| GET | `/api/v1/accounting/cogs-export` | Download COGS CSV export | accountant+ |
| POST | `/api/v1/accounting/pull` | Manually trigger pull sync (payment status, balances) | owner+ |

## Events

| Event Name | Payload Fields | Materialized State Updated |
|------------|---------------|---------------------------|
| `accounting_connected` | provider, company_id/realm_id (QBO) or tenant_id (Xero), company_name | accounting_connections table |
| `accounting_mapping_saved` | provider, mapping_count, incomplete_mappings (if any) | account_mappings table |
| `accounting_invoice_synced` | order_id, external_invoice_id (QBO/Xero), amount | sync_logs table |
| `accounting_payment_synced` | payment_id, external_payment_id | sync_logs table |
| `accounting_cogs_synced` | bottling_run_id, external_journal_id, amount | sync_logs table |
| `accounting_sync_failed` | entity_type, entity_id, error_code, error_message, retry_count | sync_logs table |
| `accounting_payment_confirmed` | order_id, paid_status_from_provider | orders table (payment_status updated) |
| `accounting_token_refreshed` | provider, success (bool), expires_at | accounting_connections table |

## Testing Notes

- **Unit tests:**
  - Account mapping validation: all 14 categories mapped, duplicate category rejection, account type matching (revenue category → revenue account)
  - COGS journal entry calculation: per-bottle COGS × bottles = total, correct debit/credit structure
  - CSV export format: verify columns present, date range filtering, account format
  - Token refresh scheduling: verify job enqueues for connections expiring within 1 hour, skips recently refreshed
  - SyncLog retry logic: increment retry_count, calculate next_retry_at with exponential backoff (5 min, 30 min)

- **Integration tests (with mock QBO/Xero APIs):**
  - Full OAuth flow: start → redirect to provider → callback with code → exchange for tokens → store encrypted
  - Connection health: token refresh when expiry < 1 hour, retry on failure, mark is_active=false if refresh fails
  - Chart of accounts pull: query provider, cache in AccountMappings table, update on refresh, handle stale cache gracefully
  - Account mapping save: all categories required, validate account IDs exist in provider, save mappings
  - Invoice sync: order placed → SyncInvoiceToAccountingJob queued → job calls provider.pushInvoice() → SyncLog.success created with external_id
  - Club batch: 300 orders in club processing run → queue 300 SyncInvoiceToAccountingJob jobs → throttle to avoid rate limit (verify job delays)
  - Payment sync: payment captured on invoice → SyncPaymentToAccountingJob queued → payment attached/recorded in provider
  - Refund sync: refund processed → credit memo created in provider
  - Pull sync: daily job queries provider for paid invoices → updates Order.payment_status if invoice paid in provider
  - COGS sync: bottling completed → SyncCOGSJournalEntryJob queued → journal entry created in provider with correct debit/credit
  - Pull account balance: job queries configured accounts → caches balances for dashboard
  - Export: select date range → CSV generated → download contains correct format

- **Critical:**
  - Token refresh must be proactive — a winery that doesn't log in for 90 days should not lose their QBO connection. Schedule daily refresh job for all active connections.
  - Rate limiting: club batch (300+ invoices) will hit QBO's 500 req/min limit. Verify jobs are delayed/throttled, not all queued at once.
  - Duplicate invoice prevention: create order → sync (external_id saved) → crash → manual retry → verify no duplicate invoice created (check SyncLog before pushing).
  - Account mapping mandatory before sync: attempt to place order without mapping → verify job fails gracefully (logged, not blocking order) → admin maps accounts → next order syncs successfully.
  - Pull sync read-only: manual edit in QBO → pull sync → verify VineSuite data unchanged, flag created in activity log for review.

