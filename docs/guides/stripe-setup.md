# Stripe Product & Price Setup

> Environment: Test mode (sandbox)
> Dashboard: https://dashboard.stripe.com/test

---

## 1. Create the Three Subscription Products

Go to **Products** → **Add product** in the Stripe Dashboard. Create each of the following:

### Starter Plan
- **Name:** VineSuite Starter
- **Description:** For small wineries getting started. Core production tracking, basic compliance.
- **Pricing:** Recurring, Monthly — **$99/month**
- Copy the **Price ID** (starts with `price_...`)

### Growth Plan
- **Name:** VineSuite Growth
- **Description:** For growing wineries. Full production suite, POS, wine club, mobile apps.
- **Pricing:** Recurring, Monthly — **$249/month**
- Copy the **Price ID**

### Pro Plan
- **Name:** VineSuite Pro
- **Description:** For established wineries. Everything in Growth plus public API, advanced analytics, priority support.
- **Pricing:** Recurring, Monthly — **$499/month**
- Copy the **Price ID**

## 2. Add Price IDs to `.env`

Open `api/.env` and fill in the three price IDs you copied:

```
STRIPE_PRICE_STARTER=price_xxxxxxxxxxxxxxxx
STRIPE_PRICE_GROWTH=price_xxxxxxxxxxxxxxxx
STRIPE_PRICE_PRO=price_xxxxxxxxxxxxxxxx
```

Also add them to `api/.env.example` as empty placeholders (they're already there as comments — just add the keys):

```
STRIPE_PRICE_STARTER=
STRIPE_PRICE_GROWTH=
STRIPE_PRICE_PRO=
```

## 3. Set Up the Customer Portal

Go to **Settings** → **Billing** → **Customer portal**. Enable the following:

- **Invoices:** Allow customers to view invoice history
- **Subscription updates:** Allow switching between Starter, Growth, and Pro plans
- **Subscription cancellation:** Allow cancellation (this gives a grace period handled by Cashier)
- **Payment method updates:** Allow updating card details

Save the portal configuration.

## 4. Set Up Webhooks (for Production — Optional for Local Dev)

Go to **Developers** → **Webhooks** → **Add endpoint**.

- **Endpoint URL:** `https://your-domain.com/api/v1/stripe/webhook`
- **Events to listen for:**
  - `customer.subscription.created`
  - `customer.subscription.updated`
  - `customer.subscription.deleted`
  - `invoice.payment_succeeded`
  - `invoice.payment_failed`

Copy the **Webhook Signing Secret** (starts with `whsec_...`) and add it to `.env`:

```
STRIPE_WEBHOOK_SECRET=whsec_xxxxxxxxxxxxxxxx
```

For local development, you can use the Stripe CLI to forward webhooks:

```bash
stripe listen --forward-to localhost:8000/api/v1/stripe/webhook
```

The CLI will print a webhook signing secret to use locally.

## 5. Verify the Integration

Once products are created and env vars are set, test the checkout flow:

```bash
# Create a tenant and login to get a token, then:
curl -X POST http://localhost:8000/api/v1/billing/checkout \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Tenant-ID: YOUR_TENANT_ID" \
  -H "Content-Type: application/json" \
  -d '{"plan": "starter"}'
```

This should return a `checkout_url` pointing to Stripe's hosted checkout page. Use Stripe's test card `4242 4242 4242 4242` with any future expiry and any CVC to complete the payment.

After checkout, verify the subscription status:

```bash
curl http://localhost:8000/api/v1/billing/status \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Tenant-ID: YOUR_TENANT_ID"
```

Should show `"subscribed": true` and the correct plan.
