# Low Priority Refactors

Quality-of-life improvements. Safe to do at any time, even after the codebase is mature. No risk from deferring.

---

## 1. Tenant Plan Configuration as Enum or Config Object

**Where:** `app/Models/Tenant.php` — `PLANS` constant, `PLAN_HIERARCHY` constant, `stripePriceForPlan()` method.

**Problem:** Plan tiers, their hierarchy, and their Stripe price mappings are spread across multiple constants and a `match` statement in the Tenant model. Adding a plan tier (e.g., "hobbyist") means editing 3 places in one file. Not an OCP violation per se (plans change rarely), but it's knowledge duplication within a single class.

**Fix:** Extract to a `PlanTier` backed enum (PHP 8.1+):
```php
enum PlanTier: string
{
    case Free = 'free';
    case Basic = 'basic';
    case Pro = 'pro';
    case Max = 'max';

    public function stripePrice(): ?string { ... }
    public function hierarchy(): int { ... }
    public function hasFeature(string $feature): bool { ... }
}
```
The Tenant model casts `plan` to the enum and delegates. Adding a tier is a single enum case with its methods.

**Estimated effort:** 1 hour.

---

## 2. Standardize Log Context Keys Across Services

**Where:** All service files.

**Problem:** Log context keys are mostly consistent but not perfectly so. Some use `user_id`, others use `performed_by`. Some include `tenant_id`, a few don't. The EventLogger always includes `tenant_id`, but service-level logs are spotty.

**Fix:** Create a `LogContext` helper or trait that auto-appends `tenant_id`, `user_id`, and `request_id` (if available) to every log call. Services call `Log::info('message', LogContext::with([...]))` instead of manually including tenant context.

**Estimated effort:** 1-2 hours. Mostly find-and-replace.

---

## 3. Move Filament Color/Badge Mappings to Model or Config

**Where:** `app/Filament/Resources/LotResource.php` (lines ~104-112, ~168-174, ~190-197), and likely other Filament resources.

**Problem:** Status-to-color mappings are defined inline in Filament resource closures. The same `match` on lot status appears 3 times in `LotResource` alone (`table()`, `infolist()` badge, `infolist()` event timeline). If you add a new status, you edit 3 closures in one file — and then repeat for any other resource that displays lot status.

**Fix:** Add a `badgeColor()` method (or similar) to the model or a dedicated presenter:
```php
// On Lot model or a LotPresenter
public function statusColor(): string
{
    return match ($this->status) {
        'in_progress' => 'info',
        'aging' => 'warning',
        'finished' => 'success',
        'bottled' => 'primary',
        default => 'secondary',
    };
}
```
Filament resources reference the method instead of duplicating the match. Works even better with the `PlanTier` enum pattern (LOW #1) applied to statuses.

**Estimated effort:** 30 minutes per resource.

---

## 4. Consistent Use of Constructor Promotion Visibility

**Where:** Across all services.

**Problem:** Most services use `protected` for constructor-promoted properties (`protected EventLogger $eventLogger`). `InventoryService` uses `private readonly`. Both work, but the inconsistency is noticeable. `private readonly` is the stronger contract — it says "this dependency is never reassigned and never accessed by subclasses."

**Fix:** Standardize on `private readonly` for all service constructor dependencies, since none of these services are designed for inheritance. If a service is later subclassed, promote to `protected` at that time.

**Estimated effort:** 30 minutes. Find-and-replace across ~20 service constructors.

---

## 5. EventProcessor Double-Checks Idempotency Key

**Where:** `app/Services/EventProcessor.php` line 108, `app/Services/EventLogger.php` line 61.

**Problem:** `EventProcessor::processEvent()` checks for an existing idempotency key at line 108, then calls `EventLogger::log()` which checks *again* at line 61. The EventProcessor check short-circuits with `'skipped'` status, while the EventLogger check returns the existing event silently. The double-check isn't harmful but it's redundant work and makes the control flow harder to reason about.

**Fix:** Remove the idempotency check from `EventProcessor::processEvent()` and let `EventLogger::log()` handle it. The processor would need to detect whether the returned event was new or existing (compare `created_at` to `now()`, or have `EventLogger::log()` return a result tuple). Alternatively, keep both checks but document why — the processor check avoids the transaction overhead for known duplicates.

**Estimated effort:** 30 minutes. Decide on the approach, implement, test.
