# Notifications & Automation

## Phase
Phase 7 (notifications) / Phase 8 (automation rules engine)

## Dependencies
- `01-foundation.md` — event log (events trigger notifications), Laravel notification system, Reverb websockets
- All other modules — notifications span the entire system (every module emits events that can trigger notifications)

## Goal
Centralized notification system with three channels: in-app (real-time via Reverb), email (via Resend), and SMS (via Twilio). Staff alerts for internal operational events. Customer notifications for transactional events. Phase 8 adds a visual automation rules engine ("if/then" builder) for custom workflows. This module is architecturally important because it defines how every other module's events surface to users — the notification hookpoints established here affect how event handlers are structured across the entire platform.

## Data Models

- **Notification** — `id` (UUID), `notifiable_type` (string — 'User' for staff or 'Customer' for customers), `notifiable_id` (UUID), `type` (varchar 255 — class name of the notification, e.g., 'ClubChargeSuccess'), `channel` (enum: in_app/email/sms), `title` (varchar 255), `body` (text — HTML for email, plain text for SMS), `data` (JSON — structured payload for links, actions, context — e.g., `{"order_id": "123", "tracking_url": "..."}`), `action_url` (nullable varchar 255 — primary CTA link), `read_at` (nullable timestamp), `sent_at` (timestamp), `failed_at` (nullable timestamp), `failure_reason` (nullable text), `created_at`, `updated_at`
  - Uses Laravel's polymorphic notifiable (can notify User for staff or Customer for customers)
  - Indexes: `notifiable_type, notifiable_id, read_at` (for bell icon queries), `type, created_at` (for type filtering)

- **NotificationPreference** — `id` (UUID), `user_id` (UUID), `notification_type` (string — key matching notification class, e.g., 'LowInventoryAlert'), `channel_in_app` (boolean, default true), `channel_email` (boolean, default true), `channel_sms` (boolean, default false), `created_at`, `updated_at`
  - Relationships: belongsTo User
  - Composite unique index: `user_id, notification_type`
  - Allows granular per-type control for staff

- **NotificationTemplate** — `id` (UUID), `key` (varchar 255 unique — domain-style key like 'club_charge_success', 'order_shipped'), `name` (varchar 255 — display name), `description` (text), `subject_template` (text — Blade string with `@variables`), `body_html_template` (text — Blade HTML with `@variables`), `body_sms_template` (text — max 160 chars, Blade string), `variables` (JSON — schema of available variables, e.g., `{"customer_name": "string", "order_id": "uuid", "tracking_url": "url"}`), `is_customizable` (boolean — true if winery can edit), `winery_id` (nullable UUID — null = system default, non-null = winery override), `created_at`, `updated_at`
  - Relationships: belongsTo Winery (nullable)
  - Indexes: `key, winery_id` (for template lookup fallback: winery override then system default)

- **AutomationRule** — `id` (UUID), `winery_id` (UUID), `name` (varchar 255), `description` (text), `trigger_event` (varchar 255 — event class name, e.g., 'OrderPlaced', 'TastingCompleted'), `conditions` (JSON — filter criteria, e.g., `{"customer.lifetime_value": {">": 500}, "order.source": "pos"}`), `actions` (JSON array — ordered actions to execute, e.g., `[{"type": "send_email", "template": "post_visit_followup"}, {"type": "add_tag", "tag": "high_value"}]`), `delay_minutes` (nullable integer — wait before executing), `is_active` (boolean, default true), `last_triggered_at` (nullable timestamp), `trigger_count` (integer, default 0), `created_at`, `updated_at` [PRO]
  - Relationships: belongsTo Winery
  - Indexes: `trigger_event, is_active` (for event listener to check rules)
  - Example conditions: `{"customer.lifetime_value": {">": 500}, "order.source": "pos"}`
  - Example actions: `[{"type": "send_email", "template": "post_visit_followup"}, {"type": "add_tag", "tag": "high_value"}]`

## Sub-Tasks

### 1. Notification infrastructure and in-app channel
**Description:** Build the core notification system using Laravel's notification framework. In-app notifications displayed in a bell-icon dropdown with unread count, powered by Reverb for real-time delivery.

**Files to create:**
- `api/app/Models/Notification.php`
- `api/app/Models/NotificationPreference.php`
- `api/app/Services/NotificationService.php` — central dispatcher that checks preferences
- `api/app/Notifications/BaseNotification.php` — abstract base class extending Laravel's Notification
- `api/app/Channels/DatabaseChannel.php` — custom channel for in-app (if needed beyond Laravel default)
- `api/app/Events/NotificationCreated.php` — broadcast event for Reverb
- `api/app/Listeners/BroadcastNotificationListener.php` — listens to NotificationCreated and broadcasts
- `api/database/migrations/2024_xx_xx_create_notifications_table.php`
- `api/database/migrations/2024_xx_xx_create_notification_preferences_table.php`
- `api/app/Filament/Widgets/NotificationBell.php` — Livewire component for bell icon
- `api/resources/views/filament/widgets/notification-bell.blade.php`

**Acceptance criteria:**
- Bell icon in portal header (staff and customer portal) with unread count badge
- Dropdown shows recent 10 notifications with: title, body snippet (first 80 chars), timestamp, unread indicator
- Mark individual notification as read (soft state change)
- Mark all as read (bulk update, retains read_at)
- Real-time: new notification appears instantly in dropdown via Reverb websocket (no page refresh required)
- Notification preferences page (both staff and customer portal): toggle per notification type across in_app/email/sms channels
- NotificationService checks NotificationPreference before dispatching to each channel — if channel_email=false for a type, email not sent
- Unread count badge auto-updates via Reverb
- Click notification title navigates to context (e.g., order notification → order detail page)
- Delete individual notification (optional, soft delete or hide_at)

**Gotchas:**
- Use Laravel's built-in notification system (Notifiable trait, notification classes, database channel) — don't reinvent it. The "in-app" channel IS the database channel.
- Reverb broadcast is in addition to database storage, not instead of. Every notification written to DB AND broadcast to websocket.
- Rate-limit notifications to prevent spam (e.g., don't send 50 "low inventory" alerts if 50 SKUs drop below threshold simultaneously — batch into one "50 SKUs below reorder point" notification).
- Staff notifications (NotificationPreference) default to in_app=true, email=true. Customer notifications default to in_app=false (not shown in customer portal), email=true (transactional), sms=false (opt-in).
- Notification data JSON should be typed/versioned in case schema changes (e.g., data_version=1).

### 2. Email notification channel and templates
**Description:** Transactional email delivery via Resend with customizable templates. All emails respect the NotificationTemplate system and use Blade templating for merge variables.

**Files to create:**
- `api/app/Models/NotificationTemplate.php`
- `api/app/Channels/ResendChannel.php` (wrapper around Resend API)
- `api/app/Notifications/Mailable.php` — intermediate Mailable class if using Laravel Mail instead of direct Resend
- `api/app/Jobs/SendNotificationEmailJob.php` — queued job for email dispatch
- `api/database/migrations/2024_xx_xx_create_notification_templates_table.php`
- `api/database/seeders/NotificationTemplateSeeder.php` — seed all default templates
- `api/app/Filament/Resources/NotificationTemplateResource.php` — admin UI for template editing
- `api/resources/views/emails/layouts/base.blade.php` — responsive base template
- `api/resources/views/emails/notifications/` — folder with all notification templates
  - `club-charge-success.blade.php`
  - `club-charge-failed.blade.php`
  - `order-confirmation.blade.php`
  - `order-shipped.blade.php`
  - `reservation-confirmation.blade.php`
  - `reservation-reminder.blade.php`
  - `card-expiring.blade.php`
  - etc. (see inventory below)

**Acceptance criteria:**
- Default templates seeded for all notification types (see Notification Type Inventory table below)
- Template customization UI in Filament: subject field, HTML editor for body, preview pane
- Winery can customize subject and body while preserving merge variables (variables highlighted/protected)
- Preview before saving customization (renders with sample data)
- Unsubscribe link on every customer-facing email (`{!! $unsubscribeLink !!}` — generates signed URL)
- Unsubscribe token valid for unlimited time, one-click removal from all marketing emails
- Professional HTML email template: responsive (mobile-friendly), matches winery branding (logo, colors), footer with winery info
- Template categories: transactional (order confirmation, shipping — CAN-SPAM exempt but include unsubscribe anyway), club (charge success, charge failed, customization window), reservations (confirmation, reminder), marketing (campaigns from CRM module — full CAN-SPAM required)
- Merge variables: available variables listed in template UI (customer name, order ID, total, tracking URL, etc.)
- Email sent via queued job (not synchronous)
- Resend API error handling and retry (exponential backoff)
- Delivery tracking: read receipts (if Resend supports) optional

**Gotchas:**
- CAN-SPAM compliance: requires physical mailing address and unsubscribe link on every commercial email (marketing, club). Transactional emails (order confirmations, payment receipts) are exempt from CAN-SPAM but include it anyway for good UX.
- Never send email without checking customer unsubscribe status (check if global unsubscribe or notification type specifically disabled).
- Merge variables must be typed in template schema (string, uuid, url, number with formatting rules).
- SMS template stored in same model but limited to 160 chars — enforce limit on save.
- Test email delivery: provide "send test email" button in template editor.

### 3. SMS notification channel via Twilio [GROWTH]
**Description:** SMS delivery for time-sensitive notifications (reservation reminders, shipping notifications, failed payment alerts). TCPA compliance is critical — every SMS must be explicitly opted-in.

**Files to create:**
- `api/app/Services/TwilioService.php` — Twilio API wrapper
- `api/app/Channels/TwilioSmsChannel.php` — Laravel notification channel for SMS
- `api/app/Jobs/SendNotificationSmsJob.php` — queued job for SMS dispatch
- `api/app/Models/CustomerSmsOptIn.php` — tracks SMS consent per customer
- `api/database/migrations/2024_xx_xx_create_customer_sms_opt_ins_table.php`
- `api/app/Filament/Pages/TwilioSetup.php` — settings page for Twilio credentials
- `api/app/Http/Controllers/Api/V1/SmsOptInController.php` — for customer opt-in endpoint
- `api/resources/views/emails/sms-opt-in-confirmation.blade.php` — confirmation email after opt-in

**Acceptance criteria:**
- Twilio account connection in portal settings (Account SID, Auth Token, From Number stored encrypted in settings)
- SMS opt-in tracking per customer: `CustomerSmsOptIn` table with `customer_id, phone_number, opted_in_at, opted_in_source, opted_out_at, opted_out_reason`
- SMS opt-in must be explicit (checkbox during sign-up or in account settings, never pre-checked)
- SMS opt-in source recorded (signup, account settings, in-person POS, staff added)
- SMS notification types: reservation reminder (24h before + 2h before), club charge notification, order shipped with tracking, failed payment alert
- 160-character template limit enforced on save in NotificationTemplate
- Delivery status tracking: sent, delivered, bounced, failed with error code
- Fallback to email if no phone number or SMS not opted in
- Opt-out support: "Reply STOP to unsubscribe" in every SMS, process inbound STOP message and mark opted_out_at
- Test SMS: staff can send test SMS to their own number from settings

**Gotchas:**
- TCPA (Telephone Consumer Protection Act) compliance is critical — sending unsolicited SMS is illegal. Penalties are $500-$1500 per message. Mandatory: opt-in is explicit, opt-in date/source recorded, opt-out honored.
- SMS from number must be a real phone number (not short code) for initial launch. Short codes require TCPA clearance and are expensive.
- Twilio costs ~$0.0079/message (varies by destination country). Track per-tenant SMS usage for potential future billing/cost allocation.
- Phone number validation and normalization (E.164 format).
- Rate-limiting: don't send multiple SMS to same customer within 5 minutes (e.g., order placed SMS + shipping SMS sent seconds apart = batch into one).
- SMS delivery not guaranteed — Twilio may not be able to reach invalid numbers. Monitor delivery status and clean up bounced numbers.

### 4. Staff alert system
**Description:** Internal alerts for operational events that need staff attention. All staff alerts are in-app and email (no SMS for staff).

**Files to create:**
- `api/app/Notifications/Staff/LowInventoryAlert.php`
- `api/app/Notifications/Staff/OverdueWorkOrderAlert.php`
- `api/app/Notifications/Staff/LicenseExpiringAlert.php`
- `api/app/Notifications/Staff/FailedClubChargeAlert.php`
- `api/app/Notifications/Staff/NewWholesaleOrderAlert.php`
- `api/app/Notifications/Staff/HighVAAlert.php`
- `api/app/Listeners/InventoryThresholdListener.php` — listens to StockAdjusted event
- `api/app/Listeners/WorkOrderOverdueListener.php` — runs daily at 9am
- `api/app/Listeners/LicenseExpirationListener.php` — runs daily
- `api/app/Listeners/ClubChargeFailedListener.php` — listens to ClubChargeFailed event
- `api/app/Listeners/WholesaleOrderCreatedListener.php` — listens to WholesaleOrderCreated event
- `api/app/Listeners/LabAnalysisHighVAListener.php` — listens to LabAnalysisRecorded event
- `api/app/Jobs/SendDailyStaffDigestJob.php` — batches non-urgent alerts

**Acceptance criteria:**
- **Low inventory:** triggered when SKU stock drops below reorder_point (from `03-inventory.md`). Notification lists the SKU, current qty, reorder point, and suggested reorder qty. Notifies: Owner + all users with Staff role + Winemaker if wine SKU. Rate-limited: batches multiple inventory alerts fired in same minute into single notification with count (e.g., "5 SKUs below reorder point").
- **Overdue work orders:** triggered daily at 9am for work orders with due_date < today. Digest format: count of overdue orders, grouped by operation type (harvest, sorting, crush, fermentation, etc.). Link to work order list filtered to overdue. Notifies: Winemaker role.
- **License expiring:** triggered at 90, 60, 30, 14, 7 days before expiry_date. Notification includes license type, current expiry_date, renewal URL if available. Notifies: Owner only.
- **Failed club charge:** triggered on each failure (via ClubChargeFailed event). Notification includes member name, charge amount, failure reason, retry schedule. Notifies: Owner + Admin role.
- **New wholesale order:** triggered on WholesaleOrderCreated event. Notification includes account name, total SKUs, total cases, required ship date. Notifies: Sales Rep assigned to account (from `09-wholesale.md`).
- **High VA detected:** triggered when lab_analysis VA exceeds configured threshold (default 0.9). Notification includes lot name, current VA, threshold. Notifies: Winemaker.
- Each alert type configurable: NotificationPreference per user per alert type controls whether they receive it.
- Urgent alerts (failed payment, high VA) send immediately in_app and email.
- Non-urgent alerts (overdue work orders, license reminders) batched into 9am daily digest.

**Gotchas:**
- Staff alerts should batch where appropriate. If 20 work orders are overdue, send one alert listing all 20 (with count + link to filtered list), not 20 separate alerts. Use a "digest" pattern.
- Urgent vs. non-urgent: define urgency per alert type (failed charge = urgent, overdue work order = digest). Urgent sends immediately, non-urgent batches.
- Overdue work order daily job runs at 9am winery timezone. Use scheduled queue job with timezone support.
- License expiration triggered at 90/60/30/14/7 days: use a scheduled command that runs daily and checks all licenses.
- Each staff member can control which alerts they receive (NotificationPreference).

### 5. Customer transactional notification inventory
**Description:** Implement all customer-facing transactional notifications. These are fired by events in other modules and dispatch via NotificationService (which checks preferences).

**Files to create:**
- `api/app/Notifications/Customer/OrderConfirmation.php`
- `api/app/Notifications/Customer/OrderShipped.php`
- `api/app/Notifications/Customer/OrderRefunded.php`
- `api/app/Notifications/Customer/ClubChargeSuccess.php`
- `api/app/Notifications/Customer/ClubChargeFailed.php`
- `api/app/Notifications/Customer/ClubCustomizationWindowOpen.php`
- `api/app/Notifications/Customer/ReservationConfirmation.php`
- `api/app/Notifications/Customer/ReservationReminder24h.php`
- `api/app/Notifications/Customer/ReservationReminder2h.php`
- `api/app/Notifications/Customer/CardExpiringReminder.php`
- `api/app/Notifications/Customer/WelcomeToClub.php`
- `api/app/Listeners/OrderPlacedListener.php` → dispatch OrderConfirmation
- `api/app/Listeners/OrderFulfilledListener.php` → dispatch OrderShipped
- `api/app/Listeners/OrderRefundedListener.php` → dispatch OrderRefunded
- `api/app/Listeners/ClubChargeProcessedListener.php` → dispatch success or failed
- `api/app/Listeners/ClubMemberJoinedListener.php` → dispatch WelcomeToClub
- `api/app/Listeners/ReservationBookedListener.php` → dispatch ReservationConfirmation, schedule 24h and 2h reminders
- `api/app/Jobs/SendReservationReminderJob.php` — queued with delay

**Acceptance criteria:**
- Each notification uses the NotificationTemplate system (customer can customize per event type via Filament)
- Each has appropriate channels: email always, SMS only if opted in and notification type allows SMS
- Merge variables populated correctly: customer first name, order ID, order total, tracking URL, reservation time, etc.
- Reservation reminders sent at 24h and 2h before reservation_at: use scheduled queue job with `delay()`. At time of booking, enqueue two jobs with delays.
- If reservation is cancelled, delete pending reminder notifications from queue and mark existing notifications as cancelled.
- SMS template: 160 character max (e.g., "Your wine shipment is on the way! Track it: {{ tracking_url }}")
- Email template: full HTML with order details, branding, link to order page
- Refund notification: include original order ID, refund amount, refund reason, expected processing time

**Gotchas:**
- Reservation reminder scheduling must account for timezone. If reservation is at "2pm" and customer is in PST, send 24h before their local time, not UTC. Store reservation_at as timestamp (which is UTC) but convert to winery/customer timezone when calculating delay.
- If a reservation is cancelled after reminders are scheduled, need to cancel the jobs. Use a cancellable job pattern (queue name + unique ID).
- Order shipped notification should include tracking URL + carrier (if available from fulfillment module).
- Club charge failed should include retry date if auto-retry is enabled.

### 6. Notification preference management
**Description:** Allow staff and customers to control which notifications they receive on which channels.

**Files to create:**
- `api/app/Models/NotificationPreference.php`
- `api/app/Filament/Pages/NotificationPreferences.php` — staff preferences UI
- `api/app/Http/Controllers/Api/V1/NotificationPreferenceController.php` — customer API endpoints
- `api/resources/views/customer-portal/notification-preferences.blade.php` — customer UI

**Acceptance criteria:**
- **Staff preferences page** (in Filament):
  - Table of notification types (rows) × channels (columns: in_app, email)
  - Checkboxes for each type/channel combo
  - Save button, success toast
  - Grouping by category (Orders, Club, Reservations, Staff Alerts, etc.)
- **Customer API endpoints:**
  - GET `/api/v1/notification-preferences` — returns current preferences
  - PUT `/api/v1/notification-preferences` — update preferences
- **Preferences schema:** per-type toggles (transactional, club, marketing). Customers cannot disable critical transactional emails (order confirmation, payment receipt) — only marketing emails.
- Global unsubscribe option for customers: checkbox "Unsubscribe from all marketing emails" → sets all marketing toggles to false, sends global unsubscribe confirmation email
- Default preferences seeded on user/customer creation:
  - Staff: all in_app=true, email=true, sms=false
  - Customer: transactional email=true, club email=true, marketing email=false, all sms=false (opt-in)

**Gotchas:**
- Transactional emails (order confirmation, payment receipt, reservation confirmation) should not be disableable. Only marketing/promotional emails should be disableable.
- Global unsubscribe is separate from per-type preferences (CAN-SPAM compliance). Store global unsubscribe state independently.
- Customer portal should show preferences in a simple format (categories with email/SMS toggles), not a matrix.

### 7. Automation rules engine [PRO]
**Description:** Visual if/then rule builder for custom automated workflows. This is a Phase 8 feature but data model defined here.

**Files to create:**
- `api/app/Models/AutomationRule.php`
- `api/database/migrations/2024_xx_xx_create_automation_rules_table.php`
- `api/app/Services/AutomationEngine.php` — evaluates conditions, executes actions
- `api/app/Services/ConditionEvaluator.php` — recursive condition evaluation (supports nested AND/OR)
- `api/app/Services/ActionExecutor.php` — executes individual actions (send_email, add_tag, create_work_order)
- `api/app/Jobs/ExecuteAutomationRuleJob.php` — queued job for rule execution (for delayed actions)
- `api/app/Listeners/AutomationRuleTriggerListener.php` — listens to all domain events, checks rules
- `api/app/Filament/Resources/AutomationRuleResource.php` — rule CRUD
- `api/app/Filament/Pages/AutomationRuleBuilder.php` — visual rule builder (if/then UI)
- `api/resources/views/filament/pages/automation-rule-builder.blade.php`

**Acceptance criteria:**
- Rule builder UI: multi-step form or visual canvas
  - Step 1: Select trigger event (dropdown, searchable — all available domain events)
  - Step 2: Add conditions (if none, rule triggers on every event)
    - Condition picker: entity type (customer, order, lot) → field → operator → value
    - Operators: =, !=, >, <, >=, <=, contains, in_list, starts_with
    - Condition logic: AND/OR between conditions
  - Step 3: Add actions (ordered list, add/remove/reorder)
    - Action types: send_email (choose template), send_sms, add_customer_tag, create_work_order (specify type), notify_staff (choose role), wait (delay in minutes)
  - Step 4: Review and save (name, description, active toggle)
- Supported triggers: all domain events (OrderPlaced, ClubMemberJoined, TastingCompleted, LabAnalysisRecorded, etc.)
- Supported conditions: customer.lifetime_value, customer.has_tag, order.total, order.channel, lot.variety, lot.vintage, lot.va, etc.
- Supported actions:
  - `send_email`: specify template + recipient (customer, owner, sales_rep)
  - `send_sms`: specify template + recipient (customer only if opted in)
  - `add_customer_tag`: specify tag name
  - `create_work_order`: specify operation type + priority
  - `notify_staff`: choose role (owner, winemaker, sales_rep) + message
  - `wait`: delay in minutes before next action (queued job)
- Delay support: "wait 24 hours then send email" = first action waits, then subsequent actions execute. Implemented via ordered actions: if action[i].type == 'wait', delay next action by delay_minutes.
- Rule activity log: for each trigger, log the event data, which conditions matched/didn't match, which actions executed, any errors
- Enable/disable toggle per rule (is_active boolean)
- Example preset rules (seeded):
  - **Post-visit follow-up:** Trigger: `TastingCompleted`. Conditions: none. Actions: [wait 1440 (24h), send_email template=post_visit_followup]. Fires 24h after any tasting.
  - **Win-back campaign:** Trigger: `ClubMembershipCancelled`. Conditions: none. Actions: [wait 10080 (1 week), send_email template=win_back_offer]. Fires 1 week after cancellation.
  - **High VA alert:** Trigger: `LabAnalysisRecorded`. Conditions: lot.va > 0.9. Actions: [notify_staff role=winemaker, add_customer_tag tag=high_va_lot]. Fires immediately if VA exceeds threshold.
  - **VIP order follow-up:** Trigger: `OrderPlaced`. Conditions: customer.lifetime_value > 5000. Actions: [send_email template=vip_order_thanks]. Fires only for high-value customers.
- Rule performance monitoring: last_triggered_at, trigger_count for debugging and analytics

**Gotchas:**
- **Efficiency critical:** AutomationRuleTriggerListener listens to every event and checks all active rules. Don't iterate every rule for every event — index by trigger_event. Load only rules matching the fired event, then check conditions.
- **Infinite loop prevention:** a rule's action cannot trigger the same rule. Example: rule A triggers on "customer_updated" and executes "add_tag" action. The "add_tag" event fires a "customer_updated" event. Prevent rule A from re-triggering on that customer_updated by tracking "triggered_by_rule_X" in event context.
- **Rate-limiting:** don't send 5 emails if a customer places 5 orders in a day (unless that's intentional). Define rate-limit per rule: max X times per customer per time window (hour/day).
- **Condition evaluation:** build a recursive evaluator that handles nested AND/OR (JSON structure: `{"AND": [{"customer.ltv": {">": 500}}, {"OR": [{"order.source": "pos"}, {"order.source": "tasting_room"}]}]}`).
- **Action execution:** if an action fails (e.g., send_email fails), should the rule stop or continue to next action? Define error handling: skip failing action and log, or stop entire rule. Recommend: skip and log.
- **Delay/queue:** actions with wait delays must be queued jobs. Use unique job ID per rule trigger to allow cancellation (e.g., "rule_123_trigger_456").

## Notification Type Inventory

This is the complete list of notification types the system must support. Each module is responsible for dispatching these via the NotificationService. Event name column shows the domain event that triggers the notification.

| Category | Notification | Channels | Recipient | Trigger Event | Template Key |
|----------|-------------|----------|-----------|---------------|--------------|
| Orders | Order confirmation | email | Customer | order_placed | order_confirmation |
| Orders | Order shipped | email, sms | Customer | order_fulfilled | order_shipped |
| Orders | Refund processed | email | Customer | order_refunded | order_refunded |
| Club | Charge successful | email | Customer | club_charge_processed (success) | club_charge_success |
| Club | Charge failed | email, sms | Customer | club_charge_processed (failed) | club_charge_failed |
| Club | Customization window open | email | Customer | club_processing_run status=customization | club_customization_window |
| Club | Welcome to club | email | Customer | club_member_joined | club_welcome |
| Reservations | Booking confirmation | email | Customer | reservation_booked | reservation_confirmation |
| Reservations | Reminder 24h | email, sms | Customer | scheduled (24h before) | reservation_reminder_24h |
| Reservations | Reminder 2h | sms | Customer | scheduled (2h before) | reservation_reminder_2h |
| Payments | Card expiring | email | Customer | scheduled (30 days before club run) | card_expiring_reminder |
| Staff | Low inventory | in_app, email | Owner/Staff | stock below reorder_point | staff_low_inventory |
| Staff | Overdue work orders | in_app, email | Winemaker | daily digest @ 9am | staff_overdue_work_orders |
| Staff | License expiring | in_app, email | Owner | 90/60/30/14/7 days before | staff_license_expiring |
| Staff | Failed club charge | in_app, email | Owner/Admin | club_charge_processed (failed) | staff_failed_club_charge |
| Staff | New wholesale order | in_app, email | Sales Rep | wholesale_order_created | staff_new_wholesale_order |
| Staff | High VA detected | in_app, email | Winemaker | lab_analysis_recorded (va > threshold) | staff_high_va_alert |

## API Endpoints

| Method | Path | Description | Auth Scope | Returns |
|--------|------|-------------|------------|---------|
| GET | `/api/v1/notifications` | List notifications for current user (paginated, 20 per page) | Authenticated | `{data: [{id, type, title, body, data, action_url, read_at, created_at}], pagination}` |
| GET | `/api/v1/notifications/unread-count` | Get unread notification count | Authenticated | `{count: int}` |
| POST | `/api/v1/notifications/{id}/mark-read` | Mark single notification as read | Authenticated | `{success: bool}` |
| POST | `/api/v1/notifications/mark-all-read` | Mark all notifications as read | Authenticated | `{success: bool}` |
| DELETE | `/api/v1/notifications/{id}` | Delete notification | Authenticated | `{success: bool}` |
| GET | `/api/v1/notification-preferences` | Get user's notification preferences | Authenticated | `{preferences: [{notification_type, channel_in_app, channel_email, channel_sms}]}` |
| PUT | `/api/v1/notification-preferences` | Update notification preferences | Authenticated | `{preferences: updated array}` |
| POST | `/api/v1/notifications/unsubscribe/{token}` | Customer unsubscribe from all marketing (via email link) | Public | redirect to /unsubscribed page |
| GET | `/api/v1/automation-rules` | List automation rules [PRO] | owner+ | `{data: [{id, name, trigger_event, is_active, last_triggered_at, trigger_count}]}` |
| POST | `/api/v1/automation-rules` | Create automation rule [PRO] | owner+ | `{id, name, ...}` |
| GET | `/api/v1/automation-rules/{id}` | Get single rule detail [PRO] | owner+ | `{id, name, trigger_event, conditions, actions, ...}` |
| PUT | `/api/v1/automation-rules/{id}` | Update rule [PRO] | owner+ | `{updated rule}` |
| DELETE | `/api/v1/automation-rules/{id}` | Delete rule [PRO] | owner+ | `{success: bool}` |
| POST | `/api/v1/automation-rules/{id}/toggle` | Toggle rule active/inactive [PRO] | owner+ | `{is_active: bool}` |
| GET | `/api/v1/automation-rules/{id}/activity-log` | Get rule trigger activity log [PRO] | owner+ | `{data: [{triggered_at, matched_conditions, actions_executed, errors}]}` |

## Events

| Event Name | Payload Fields | Notification Emitted | Listener File | Notes |
|------------|---------------|----------------------|----------------|-------|
| `notification_sent` | notification_id, type, channel, notifiable_type, notifiable_id, recipient_address | (no — internal tracking) | N/A | Broadcast to Reverb on in_app channel |
| `notification_failed` | notification_id, type, channel, error, failure_code, notifiable_id | (no — internal tracking) | N/A | Log failure for monitoring |
| `automation_rule_triggered` | rule_id, trigger_event, event_data, matched_conditions, actions_executed | (no — internal tracking) | AutomationRuleTriggerListener | Log for audit trail |

## Testing Notes

### Unit Tests
- **NotificationService:** verify preference checking — for each notification type, check if user has disabled a channel, NotificationService should skip that channel
- **NotificationService:** verify batch rate-limiting — if 20 low inventory alerts fired in same minute, only one notification sent with count
- **Template variable substitution:** verify merge variables correctly interpolated (customer name, order ID, tracking URL)
- **AutomationEngine condition evaluation:** test condition matching logic — customer.ltv > 500 evaluates correctly, nested AND/OR logic works
- **AutomationEngine action execution:** test each action type (send_email, add_tag, notify_staff) executes without error
- **SMS character limit enforcement:** verify 160-character limit enforced on NotificationTemplate.body_sms_template save
- **SMS opt-in validation:** verify NotificationService skips SMS channel if customer not opted in

### Integration Tests
- **Notification dispatch flow:** event fired (e.g., OrderPlaced) → NotificationService called → notification created in DB → broadcast via Reverb → email job queued
- **Staff alert batching:** 20 low inventory events fired → single "20 SKUs below reorder point" notification sent
- **Automation rule trigger:** event fired → rule trigger_event matches → conditions evaluated → matching actions executed (send email, add tag, etc.)
- **Automation rule delay:** rule with wait action queued as job → job executes after delay → subsequent actions run
- **Unsubscribe flow:** customer clicks email unsubscribe link → token validated → customer marked globally unsubscribed → subsequent marketing emails suppressed
- **Reservation reminder:** reservation booked → two reminder jobs queued (24h, 2h) → jobs trigger at correct times → reminders sent
- **Reservation cancellation:** reservation cancelled → pending reminder jobs cancelled → no reminders sent
- **Timezone handling:** reservation reminder scheduled for "24h before" in customer timezone, not UTC → correct time

### Critical
- **TCPA compliance for SMS:** verify opt-in checkbox checked before every SMS dispatch. Unit test: NotificationService.sendSms() fails if customer not opted in. Integration test: SMS not sent to non-opted-in customer.
- **CAN-SPAM compliance:** verify unsubscribe link renders and functions in every customer email. Automated test: scrape email HTML, extract unsubscribe link, click it, verify customer marked unsubscribed.
- **Automation rule infinite loop prevention:** rule A triggers on "customer_updated" with action "add_tag". Save rule. Fire customer_updated event. Verify rule A triggers once, not infinitely. Test: trigger count increments by 1, not unbounded.
- **Reverb real-time delivery:** new notification created in DB → appears in bell icon dropdown within 2 seconds (no page refresh). Manual test with live dev server.
- **Rate-limit enforcement:** place 5 orders in rapid succession → verify only 1 order confirmation notification sent per customer, not 5 (or verify per design: do send 5 if that's intentional). Clarify with product.

## File Path Manifest
- Models: `api/app/Models/Notification.php`, `NotificationPreference.php`, `NotificationTemplate.php`, `AutomationRule.php`
- Services: `api/app/Services/NotificationService.php`, `TwilioService.php`, `AutomationEngine.php`, `ConditionEvaluator.php`, `ActionExecutor.php`
- Channels: `api/app/Channels/ResendChannel.php`, `TwilioSmsChannel.php`
- Notifications (base & staff & customer): `api/app/Notifications/BaseNotification.php`, `api/app/Notifications/Staff/*.php`, `api/app/Notifications/Customer/*.php`
- Listeners: `api/app/Listeners/*.php` (e.g., OrderPlacedListener, AutomationRuleTriggerListener)
- Jobs: `api/app/Jobs/SendNotificationEmailJob.php`, `SendNotificationSmsJob.php`, `ExecuteAutomationRuleJob.php`, `SendDailyStaffDigestJob.php`, `SendReservationReminderJob.php`
- Migrations: `api/database/migrations/2024_xx_xx_create_notifications_table.php`, `create_notification_preferences_table.php`, `create_notification_templates_table.php`, `create_automation_rules_table.php`, `create_customer_sms_opt_ins_table.php`
- Filament UI: `api/app/Filament/Widgets/NotificationBell.php`, `api/app/Filament/Resources/NotificationTemplateResource.php`, `api/app/Filament/Resources/AutomationRuleResource.php`, `api/app/Filament/Pages/NotificationPreferences.php`, `api/app/Filament/Pages/TwilioSetup.php`, `api/app/Filament/Pages/AutomationRuleBuilder.php`
- API Controllers: `api/app/Http/Controllers/Api/V1/NotificationPreferenceController.php`, `SmsOptInController.php`
- Email views: `api/resources/views/emails/layouts/base.blade.php`, `api/resources/views/emails/notifications/*.blade.php`
- Tests: `tests/Unit/Services/NotificationServiceTest.php`, `tests/Unit/Services/AutomationEngineTest.php`, `tests/Integration/NotificationDispatchTest.php`, `tests/Integration/AutomationRuleTest.php`
