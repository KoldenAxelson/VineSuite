# Medium Priority Refactors

Better done before the codebase has more consumers and modules depending on current patterns. Harder to fix later, but nothing is broken today.

---

## 1. Extract Service Interfaces for API-Facing Services

**Where:** `app/Services/LotService.php`, `AdditionService.php`, `TransferService.php`, `BlendService.php`, `BottlingService.php`, `InventoryService.php`

**Problem:** All services are injected as concrete classes. Controllers and other services depend directly on the implementation, not an abstraction. This means:

- Unit testing controllers requires Mockery or full integration tests (no simple fakes/stubs)
- The KMP mobile app's API contract has no formal interface to code against
- Swapping implementations (e.g., a `DryRunBottlingService` for simulation mode) requires refactoring all consumers

**Why it's MEDIUM:** Works fine with one implementation per service, which is the current state. Becomes painful when you add the second consumer (POS, mobile sync, AI features) or want to test in isolation.

**Fix:** Extract interfaces for the 6 core services. Convention: `app/Contracts/LotServiceInterface.php`. Bind in `AppServiceProvider`:
```php
$this->app->bind(LotServiceInterface::class, LotService::class);
```
Controllers and other services type-hint the interface. Start with the services the mobile API will call — `LotService`, `TransferService`, `AdditionService` — and expand from there.

**Estimated effort:** 2-3 hours. Mechanical extraction — copy public method signatures into interfaces, update type hints, add bindings.

---

## 2. Domain Exception Classes

**Where:** Throughout all services — `BottlingService.php`, `TransferService.php`, `BlendService.php`, `InventoryService.php`

**Problem:** Business rule violations are communicated via `ValidationException::withMessages()` or `\InvalidArgumentException`. These work but they:

- Conflate input validation (missing/malformed fields) with domain rule violations (insufficient volume, invalid status transition)
- Don't carry structured metadata (the lot ID that failed, the volume shortfall amount)
- Make it hard for the mobile app to programmatically distinguish "your request was malformed" from "the winery doesn't have enough wine for this operation"

**Why it's MEDIUM:** The API works today because the mobile app isn't built yet. Once KMP is consuming these errors, you'll want error codes the client can switch on rather than parsing English strings.

**Fix:** Create `app/Exceptions/Domain/` with specific exceptions:
```
InsufficientVolumeException      — lot/vessel doesn't have enough wine
InvalidStatusTransitionException — e.g., can't bottle an archived lot
DuplicateOperationException      — blend already finalized, bottling already completed
CapacityExceededException        — vessel overfill, location overflow
```
Each carries structured properties (`$lotId`, `$available`, `$requested`). A single exception handler maps them to API error responses with stable error codes:
```json
{
  "errors": [{
    "code": "INSUFFICIENT_VOLUME",
    "message": "Lot has only 50.0 gallons available, but 75.0 gallons needed.",
    "meta": { "lot_id": "abc-123", "available": 50.0, "requested": 75.0 }
  }]
}
```

**Estimated effort:** 3-4 hours. Create exception classes, update services to throw them, add handler mapping, update tests.

---

## 3. HandleSubscriptionChange Webhook Dispatch Pattern

**Where:** `app/Listeners/HandleSubscriptionChange.php`

**Problem:** The listener uses a `match` statement to route webhook types to handler methods. Currently handles 2 types. By the time you handle plan changes, trial expirations, subscription pauses, payment method updates, and invoice finalization, this becomes a long switch in a single class.

**Why it's MEDIUM:** Only 2 cases today. Will grow to 8-10 as billing matures in Phase 5. Not urgent, but refactoring a listener with 10 match arms is more annoying than refactoring one with 2.

**Fix:** Create `app/Listeners/Webhooks/` with one handler per event type:
```
PaymentSucceededHandler.php
PaymentFailedHandler.php
SubscriptionUpdatedHandler.php
// etc.
```
The main listener becomes a dispatcher:
```php
public function handle(WebhookReceived $event): void
{
    $handler = $this->resolveHandler($event->payload['type'] ?? '');
    $handler?->handle($event->payload);
}
```
Use a config map or tagged services for handler resolution. Each new webhook type is a new class, not a new match arm.

**Estimated effort:** 1-2 hours.

---

## 4. Consistent Volume Mutation Through a Single Codepath

**Where:** `BottlingService.php` (line ~108-111), `BlendService.php` (line ~189-191), `TransferService.php` (line ~79-82), `AdditionService.php`

**Problem:** Lot volume deductions happen in 3+ different services via direct `$lot->update(['volume_gallons' => ...])` calls. Each service independently calculates the new volume and writes its own event. There's no single `deductVolume()` / `addVolume()` method that enforces invariants like "volume can't go negative" or "volume change must produce an event."

**Why it's MEDIUM:** The pattern is consistent today because one person wrote all the services. As more modules touch lot volumes (POS sales reducing case goods, wine club allocations, custom crush billing), the risk of one module forgetting the event write or the negativity check grows.

**Fix:** Add `adjustVolume(Lot $lot, float $delta, string $reason, string $performedBy)` to `LotService`. All volume mutations route through it. The method enforces invariants, writes the event, and returns the updated lot. Services call `$this->lotService->adjustVolume()` instead of `$lot->update()`.

**Estimated effort:** 2-3 hours. Requires updating all services that touch `volume_gallons`.
