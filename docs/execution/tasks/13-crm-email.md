# CRM & Customer Management

## Phase
Phase 7

## Dependencies
- `01-foundation.md` — auth, event log, Filament
- `11-ecommerce.md` — Order model (purchase history)
- `10-wine-club.md` — ClubMember model (club history)
- `12-reservations-events.md` — Reservation model (visit history)

## Goal
Build unified customer profiles that aggregate every touchpoint — tasting room visits, online purchases, club membership, reservations, email engagement. Provide segmentation tools to create targeted marketing lists and a basic built-in email system. Integrations with Mailchimp and Klaviyo sync customer data for wineries using external email platforms.

## Data Models

- **Customer** — `id` (UUID), `first_name`, `last_name`, `email`, `phone`, `date_of_birth`, `addresses` (JSON array), `default_shipping_address_id`, `tags` (JSON array), `preferences` (JSON — varietal likes, communication prefs), `source` (pos/online/club/import/manual), `lifetime_value` (decimal, computed), `last_visit_at`, `last_purchase_at`, `notes`, `created_at`, `updated_at`
  - Relationships: hasMany Orders, hasOne ClubMember, hasMany Reservations, hasMany CustomerCommunications
- **CustomerCommunication** — `id`, `customer_id`, `type` (email/sms), `subject`, `template_name`, `sent_at`, `opened_at`, `clicked_at`, `bounced_at`
- **Segment** — `id` (UUID), `name`, `criteria` (JSON — filter rules), `customer_count` (cached), `created_at`, `updated_at`
- **EmailCampaign** — `id` (UUID), `name`, `subject`, `body_html`, `segment_id` (nullable), `status` (draft/scheduled/sending/sent), `scheduled_at`, `sent_at`, `total_sent`, `total_opened`, `total_clicked`, `created_at`, `updated_at`
- **EmailTemplate** — `id`, `name`, `category` (newsletter/club/event/reengagement), `body_html`, `is_default`, `created_at`, `updated_at`

## Sub-Tasks

### 1. Customer model and unified profile
**Description:** Central customer model that all modules reference.
**Files to create:**
- `api/app/Models/Customer.php`
- `api/database/migrations/xxxx_create_customers_table.php`
- `api/app/Filament/Resources/CustomerResource.php`
- `api/app/Http/Controllers/Api/V1/CustomerController.php`
- `api/app/Services/CustomerService.php`
**Acceptance criteria:**
- Unified profile aggregating: all orders (any channel), club membership, reservations, communications
- Lifetime value auto-calculated from order history
- Duplicate detection on create (email match)
- Merge duplicate customers (combine histories)
- Customer search via Meilisearch (name, email, phone)
- Tags for manual categorization

### 2. Customer segmentation
**Description:** Build filter-based segmentation — create saved customer lists based on criteria.
**Files to create:**
- `api/app/Models/Segment.php`
- `api/database/migrations/xxxx_create_segments_table.php`
- `api/app/Services/SegmentationService.php`
- `api/app/Filament/Resources/SegmentResource.php`
**Acceptance criteria:**
- Filter by: channel, purchase history (amount, recency, frequency), club tier, location, last visit date, LTV, tags, wine preferences
- Save segments as named lists
- Segment size preview before action
- Segments update dynamically (re-evaluated on use)

### 3. Built-in email system
**Description:** Basic email marketing: compose, send to segment, schedule, track opens/clicks.
**Files to create:**
- `api/app/Models/EmailCampaign.php`
- `api/app/Models/EmailTemplate.php`
- `api/app/Models/CustomerCommunication.php`
- `api/database/migrations/` for all email tables
- `api/app/Services/EmailCampaignService.php`
- `api/app/Jobs/SendEmailCampaignJob.php`
- `api/app/Filament/Resources/EmailCampaignResource.php`
**Acceptance criteria:**
- Compose email with template or from scratch
- Send to a segment or all customers
- Schedule for future send
- Open and click tracking (via tracking pixel and link wrapping)
- Unsubscribe management (CAN-SPAM compliant)
- Template library: newsletter, club announcement, event invite, re-engagement

### 4. Mailchimp integration [GROWTH]
**Description:** Two-way sync: push customer segments to Mailchimp, sync email engagement back.
**Files to create:**
- `api/app/Services/Integrations/MailchimpService.php`
- `api/app/Jobs/SyncMailchimpJob.php`
**Acceptance criteria:**
- Export customer segment to Mailchimp list/audience
- Sync contact data (name, email, tags, club status)
- Import engagement data back (opens, clicks, unsubscribes)

### 5. Klaviyo integration [PRO]
**Description:** Advanced integration — sync contacts + trigger event-based flows.
**Files to create:**
- `api/app/Services/Integrations/KlaviyoService.php`
- `api/app/Jobs/SyncKlaviyoJob.php`
**Acceptance criteria:**
- Sync contacts with properties (LTV, club tier, last visit, preferences)
- Push events to Klaviyo (order placed, club charged, reservation booked, tasting completed)
- Enables Klaviyo flows triggered by VineSuite events (e.g., post-visit email series)

### 6. Birthday and loyalty features [GROWTH]
**Description:** Birthday recognition and basic loyalty program.
**Files to create:**
- `api/app/Jobs/BirthdayEmailJob.php`
- `api/app/Services/LoyaltyService.php` (optional points system)
**Acceptance criteria:**
- Automated birthday email with optional offer
- Points-based loyalty system (optional, configurable)
- Anniversary emails (join date, first purchase date)

## API Endpoints

| Method | Path | Description | Auth Scope |
|--------|------|-------------|------------|
| GET | `/api/v1/customers` | List customers | admin+ |
| POST | `/api/v1/customers` | Create customer | Authenticated |
| GET | `/api/v1/customers/{customer}` | Full profile | admin+ |
| PUT | `/api/v1/customers/{customer}` | Update customer | admin+ |
| POST | `/api/v1/customers/merge` | Merge duplicates | admin+ |
| GET | `/api/v1/segments` | List segments | admin+ |
| POST | `/api/v1/segments` | Create segment | admin+ |
| GET | `/api/v1/segments/{segment}/preview` | Preview member count | admin+ |
| POST | `/api/v1/email-campaigns` | Create campaign | admin+ |
| POST | `/api/v1/email-campaigns/{campaign}/send` | Send/schedule | admin+ |

## Events

| Event Name | Payload Fields | Materialized State Updated |
|------------|---------------|---------------------------|
| `customer_created` | name, email, source | customers |
| `customer_merged` | kept_id, merged_id | customers, orders, club_members |

## Testing Notes
- **Unit tests:** Segmentation query builder (each filter type), LTV calculation, duplicate detection
- **Integration tests:** Segment → email campaign → send → track open. Customer merge (verify all related records transfer).
- **Critical:** Email unsubscribe must work perfectly — CAN-SPAM violations have legal consequences. Test unsubscribe link in every email template.
