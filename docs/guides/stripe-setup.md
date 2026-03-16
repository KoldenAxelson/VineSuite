# Stripe Product & Price Setup

> Environment: Test mode (sandbox)
> Dashboard: https://dashboard.stripe.com/test

---

## 1. Create Subscription Products

Go to **Products** → **Add product**:

### Starter Plan
- **Name:** VineSuite Starter
- **Description:** For small wineries. Core production tracking, basic compliance.
- **Pricing:** Monthly — **$99/month**
- Copy the **Price ID** (starts with `price_...`)

### Growth Plan
- **Name:** VineSuite Growth
- **Description:** Growing wineries. Full production suite, POS, wine club, mobile apps.
- **Pricing:** Monthly — **$249/month**
- Copy the **Price ID**

### Pro Plan
- **Name:** VineSuite Pro
- **Description:** Established wineries. Everything in Growth plus public API, advanced analytics, priority support.
- **Pricing:** Monthly — **$499/month**
- Copy the **Price ID**

---

## 2. Add Price IDs to `.env`

Open `api/.env`:

```
STRIPE_PRICE_STARTER=price_xxxxxxxxxxxxxxxx
STRIPE_PRICE_GROWTH=price_xxxxxxxxxxxxxxxx
STRIPE_PRICE_PRO=price_xxxxxxxxxxxxxxxx
```

Also add placeholders to `api/.env.example`:

```
STRIPE_PRICE_STARTER=
STRIPE_PRICE_GROWTH=
STRIPE_PRICE_PRO=
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
  -d '{"plan": "starter"}'
```

Should return `checkout_url` pointing to Stripe's hosted checkout. Use test card `4242 4242 4242 4242` with any future expiry and any CVC.

After checkout, verify subscription status:

```bash
curl http://localhost:8000/api/v1/billing/status \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Tenant-ID: YOUR_TENANT_ID"
```

Should show `"subscribed": true` and correct plan.
