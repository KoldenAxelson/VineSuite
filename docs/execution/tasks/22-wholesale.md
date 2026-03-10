# Wholesale & Distribution

## Phase
Phase 8

## Dependencies
- `01-foundation.md` — auth, event log
- `04-inventory.md` — case goods (wholesale orders deduct inventory)
- `11-ecommerce.md` — Order model (wholesale orders use the same order pipeline)
- `13-crm-email.md` — Customer model (wholesale accounts are customer type)

## Goal
Support wholesale/distribution sales — a significant revenue channel for established wineries. Wholesale accounts have different pricing, payment terms (Net 30/60), and ordering workflows than DTC customers. Pro tier adds a distributor portal where distributors can self-serve orders.

## Data Models

- **WholesaleAccount** — `id` (UUID), `customer_id`, `account_type` (distributor/restaurant/retailer/on_premise), `price_list_id`, `sales_rep_id` (FK users), `credit_terms` (cod/net_30/net_60), `credit_limit`, `state_license_number`, `license_verified`, `notes`, `created_at`, `updated_at`
- **PriceList** — `id` (UUID), `name`, `type` (wholesale/on_premise/by_the_glass), `is_default`, `created_at`
- **PriceListItem** — `id`, `price_list_id`, `sku_id`, `price_per_unit`, `min_quantity`
- **WholesaleInvoice** — `id` (UUID), `order_id`, `wholesale_account_id`, `due_date`, `status` (pending/partial/paid/overdue), `amount_due`, `amount_paid`, `created_at`

## Sub-Tasks

### 1. Wholesale account management
**Files to create:** WholesaleAccount model, migration, Filament resource
**Acceptance criteria:** Account CRUD with credit terms, state license verification, sales rep assignment. Account type classification. Credit limit tracking.

### 2. Price list management
**Files to create:** PriceList model, PriceListItem model, migrations, Filament resources
**Acceptance criteria:** Multiple tiered price lists. Per-SKU pricing within each list. Minimum quantity pricing (volume discounts). Default price list per account type.

### 3. Wholesale order entry
**Description:** Phone/email order entry by staff on behalf of wholesale accounts.
**Acceptance criteria:** Select account → apply correct price list → enter items → generate invoice with credit terms. Orders deduct from case goods inventory. Order confirmation to account.

### 4. Accounts receivable tracking
**Files to create:** WholesaleInvoice model, migration, AR aging report
**Acceptance criteria:** Invoice generation with Net 30/60 terms. Payment recording (partial and full). AR aging report (current, 30, 60, 90+ days). Overdue payment alerts.

### 5. Distributor portal [PRO]
**Description:** Self-service portal for distributors to place orders.
**Acceptance criteria:** Distributor login with limited access. View available products with their price list. Place orders. View order history and invoices. Depletion reporting.

## API Endpoints

| Method | Path | Description | Auth Scope |
|--------|------|-------------|------------|
| GET | `/api/v1/wholesale/accounts` | List accounts | admin+ |
| POST | `/api/v1/wholesale/orders` | Create order | admin+ |
| GET | `/api/v1/wholesale/invoices` | List invoices | accountant+ |
| GET | `/api/v1/wholesale/ar-aging` | AR aging report | accountant+ |

## Testing Notes
- **Integration tests:** Wholesale order with correct price list application. Invoice generation with credit terms. AR aging calculation.
