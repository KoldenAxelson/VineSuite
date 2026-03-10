# Reservations & Events

## Phase
Phase 7

## Dependencies
- `01-foundation.md` — auth, event log, Filament
- `11-ecommerce.md` — Payment model (deposits/tickets), Order model (event tickets)
- `13-crm-email.md` — Customer profiles (reservation linked to customer)

## Goal
Build the reservation system for tasting room experiences and the event management module for winery events (dinners, barrel tastings, release parties). Includes a public booking widget, availability management, deposit/prepay handling, confirmation/reminder emails, and day-of check-in. Integrates with POS for on-site activities and CRM for customer history.

## Data Models

- **TastingExperience** — `id` (UUID), `name`, `description`, `duration_minutes`, `capacity`, `price`, `deposit_amount` (nullable), `images` (JSON), `is_active`, `sort_order`, `created_at`, `updated_at`
- **AvailabilitySlot** — `id`, `experience_id`, `date`, `start_time`, `end_time`, `capacity`, `booked_count`, `is_blocked`, `staff_id` (nullable), `created_at`
- **Reservation** — `id` (UUID), `experience_id`, `slot_id`, `customer_id`, `party_size`, `status` (confirmed/checked_in/cancelled/no_show), `deposit_paid` (decimal), `payment_id` (nullable), `special_notes`, `confirmation_code`, `reminder_sent_at`, `created_at`, `updated_at`
- **WineryEvent** — `id` (UUID), `name`, `description`, `date`, `start_time`, `end_time`, `location`, `capacity`, `ticket_price` (nullable — free events), `member_price` (nullable), `images` (JSON), `is_published`, `created_at`, `updated_at`
  - Relationships: hasMany EventTickets
- **EventTicket** — `id` (UUID), `event_id`, `customer_id`, `ticket_type` (general/vip/member), `quantity`, `total_paid`, `payment_id`, `qr_code`, `checked_in_at`, `created_at`

## Sub-Tasks

### 1. Tasting experience configuration
**Description:** Define tasting experiences (e.g., "Estate Tasting - $25", "Reserve Flight - $45") with capacity, duration, and pricing.
**Files to create:**
- `api/app/Models/TastingExperience.php`
- `api/database/migrations/xxxx_create_tasting_experiences_table.php`
- `api/app/Filament/Resources/TastingExperienceResource.php`
**Acceptance criteria:** Full CRUD with images, pricing, capacity per slot. Fee-waived-on-purchase threshold configurable per experience.

### 2. Availability and slot management
**Description:** Generate and manage time slots per experience per day. Support blocking times and blackout dates.
**Files to create:**
- `api/app/Models/AvailabilitySlot.php`
- `api/database/migrations/xxxx_create_availability_slots_table.php`
- `api/app/Services/AvailabilityService.php`
**Acceptance criteria:** Auto-generate slots from experience template (e.g., every hour from 10am-4pm). Block specific slots/dates. Real-time availability checking. Capacity enforcement.

### 3. Reservation booking flow
**Description:** Public-facing booking flow: select experience → pick date/time → enter party details → pay deposit → confirmation.
**Files to create:**
- `api/app/Models/Reservation.php`
- `api/database/migrations/xxxx_create_reservations_table.php`
- `api/app/Http/Controllers/Api/V1/ReservationController.php`
- `api/app/Services/ReservationService.php`
**Acceptance criteria:** Book with party size and special notes. Deposit/prepay via Stripe. Confirmation email with confirmation code. Reminder emails (24h and 2h before). Cancellation with refund rules. Walk-in addition to the same system.

### 4. Event management
**Description:** Create and manage winery events with ticketing.
**Files to create:**
- `api/app/Models/WineryEvent.php`
- `api/app/Models/EventTicket.php`
- `api/database/migrations/xxxx_create_winery_events_table.php`
- `api/database/migrations/xxxx_create_event_tickets_table.php`
- `api/app/Filament/Resources/WineryEventResource.php`
**Acceptance criteria:** Event CRUD with multi-tier ticketing. Promo codes for events. QR code on tickets for check-in. Attendee list and post-event email.

### 5. Calendar and capacity dashboard
**Description:** Unified calendar view showing reservations, events, and closures.
**Files to create:**
- `api/app/Filament/Pages/ReservationCalendar.php`
**Acceptance criteria:** Day/week/month calendar views. Color-coded by experience type. Today's bookings at a glance. Staff assignment per slot.

### 6. POS check-in integration
**Description:** API endpoints for POS app to check in reservations and event tickets.
**Files to create:**
- `api/app/Http/Controllers/Api/V1/CheckInController.php`
**Acceptance criteria:** Scan confirmation code or QR → mark checked in. Show party details and special notes on POS. Member benefit auto-apply (complimentary tastings). Walk-in tracking.

## API Endpoints

| Method | Path | Description | Auth Scope |
|--------|------|-------------|------------|
| GET | `/api/v1/public/{slug}/availability` | Public availability | Public |
| POST | `/api/v1/public/{slug}/reservations` | Book reservation | Public |
| GET | `/api/v1/reservations` | List reservations (admin) | admin+ |
| POST | `/api/v1/reservations/{res}/check-in` | Check in | tasting_room+ |
| GET | `/api/v1/events` | List events | Authenticated |
| POST | `/api/v1/events` | Create event | admin+ |
| POST | `/api/v1/events/{event}/tickets` | Purchase ticket | Public |

## Events

| Event Name | Payload Fields | Materialized State Updated |
|------------|---------------|---------------------------|
| `reservation_booked` | experience, slot, customer, party_size | reservations, availability_slots |
| `reservation_checked_in` | reservation_id, checked_in_by | reservations |
| `event_ticket_purchased` | event_id, customer_id, ticket_type, qty | event_tickets |

## Testing Notes
- **Integration tests:** Full reservation flow (book → confirm → remind → check in). Capacity enforcement (reject over-capacity bookings). Cancellation refund logic.
- **Critical:** Double-booking prevention — concurrent booking attempts for the last slot must be handled atomically.
