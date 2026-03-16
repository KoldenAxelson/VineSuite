# Stripe Product & Price Setup

> Environment: Test mode (sandbox)
> Dashboard: https://dashboard.stripe.com/test

---

## 1. Create Subscription Products

Go to **Products** → **Add product**:

### Basic Plan
- **Name:** VineSuite Basic
- **Description:** Production tracking, compliance, COGS, lab/fermentation, cellar app.
- **Pricing:** Monthly — **$99/month**
- Copy the **Price ID** (starts with `price_...`)

### Pro Plan
- **Name:** VineSuite Pro
- **Description:** Everything in Basic + DTC (wine club, ecommerce, POS, CRM, reservations).
- **Pricing:** Monthly — **$179/month**
- Copy the **Price ID**

### Max Plan
- **Name:** VineSuite Max
- **Description:** Everything in Pro + AI features, public API, multi-brand, wholesale.
- **Pricing:** Monthly — **$299/month**
- Copy the **Price ID**

---

## 2. Add Price IDs to `.env`

Open `api/.env`:

```
STRIPE_PRICE_BASIC=price_xxxxxxxxxxxxxxxx
STRIPE_PRICE_PRO=price_xxxxxxxxxxxxxxxx
STRIPE_PRICE_MAX=price_xxxxxxxxxxxxxxxx
```

Also add placeholders to `api/.env.example`:

```
STRIPE_PRICE_BASIC=
STRIPE_PRICE_PRO=
STRIPE_PRICE_MAX=
```

---

## 3. Set Up Customer Portal

Go to **Settings** → **Billing** → **Customer portal**. Enable:

- **Invoices:** View invoice history
- **Subscription updates:** Switch between plans
- **Cancellation:** Allow cancellation (Cashier handles grace period)
- **Payment method updates:** Update card details

---

## 4. Set Up Webhooks (Optional for Local Dev)

Go to **Developers** → **Webhooks** → **Add endpoint**:

- **URL:** `https://your-domain.com/api/v1/stripe/webhook`
- **Events:**
  - `customer.subscription.created`
  - `customer.subscription.updated`
  - `customer.subscription.deleted`
  - `invoice.payment_succeeded`
  - `invoice.payment_failed`

Copy the **Webhook Signing Secret** and add to `.env`:

```
STRIPE_WEBHOOK_SECRET=whsec_xxxxxxxxxxxxxxxx
```

For local dev, use Stripe CLI:

```bash
stripe listen --forward-to localhost:8000/api/v1/stripe/webhook
```

---

## 5. Verify Integration

Test the checkout flow:

```bash
curl -X POST http://localhost:8000/api/v1/billing/checkout \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Tenant-ID: YOUR_TENANT_ID" \
  -H "Content-Type: application/json" \
  -d '{"plan": "basic"}'
```

Should return `checkout_url` pointing to Stripe's hosted checkout. Use test card `4242 4242 4242 4242` with any future expiry and any CVC.

After checkout, verify subscription status:

```bash
curl http://localhost:8000/api/v1/billing/status \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Tenant-ID: YOUR_TENANT_ID"
```

Should show `"subscribed": true` and correct plan.
