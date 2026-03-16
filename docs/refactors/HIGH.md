# High Priority Refactors

Do these before the next phase of development begins. Deferring these creates compounding debt or audit gaps.

---

## 1. Filament Bulk Actions Bypass Service Layer

**Where:** `app/Filament/Resources/LotResource.php` line ~152, and likely other Filament resources with bulk actions.

**Problem:** The bulk archive action calls `$lot->update(['status' => 'archived'])` directly on the model, bypassing `LotService::updateLot()`. This means no `lot_status_changed` event is written to the event log. Every bulk status change is invisible to the audit trail, TTB compliance reporting, and the mobile sync stream.

**Why it's HIGH:** The event log is your compliance backbone and your data gravity moat. Silent mutations undermine both. Every Filament action that mutates domain data needs to route through the service layer.

**Fix:**
```php
// Before (broken)
->action(fn ($records) => $records->each(
    fn (Lot $lot) => $lot->update(['status' => 'archived'])
))

// After (correct)
->action(function ($records) {
    $lotService = app(LotService::class);
    $userId = auth()->id();
    $records->each(fn (Lot $lot) => $lotService->updateLot(
        $lot,
        ['status' => 'archived'],
        $userId,
    ));
})
```

**Scope:** This is not just `LotResource`. The following Filament resources also call `->update()` directly, bypassing their respective services:

- `BottlingRunResource.php` line 144 — marks run as completed without going through `BottlingService::completeBottlingRun()` (skips volume deduction, event write, SKU generation)
- `WorkOrderResource.php` line 160 — status change bypasses `WorkOrderService`
- `BlendTrialResource.php` line 139 — status change bypasses `BlendService`
- `UserResource.php` lines 127/134 — activate/deactivate user (less critical since no event log, but still worth routing through a service for consistency)

The pattern should be: Filament action -> Service method -> Model + EventLogger. Anywhere Filament touches a domain model's `update()`, `create()`, or `delete()` directly is an audit gap.

**Estimated effort:** 2-3 hours. Mostly mechanical — find the direct calls, replace with service calls. The `BottlingRunResource` one is the most dangerous since it skips volume deduction entirely.

---

## 2. EventLogger::resolveSource() Hardcoded Match Statement

**Where:** `app/Services/EventLogger.php` lines 109-127.

**Problem:** The `resolveSource()` method maps operation type prefixes to event sources using a hardcoded `match` block. Every new event source category (DTC, wine club, POS, AI) requires modifying this method. This is the clearest Open/Closed principle violation in the codebase, and it sits on the hottest path — every single event write flows through it.

**Why it's HIGH:** Phases 5-8 will each introduce new event source categories. If four developers are all editing this one method to add their prefixes, you get merge conflicts and silent misclassifications. Fix it once now while there are only 4 source categories.

**Fix — config-driven mapping:**
```php
// config/event-sources.php
return [
    'lab' => ['lab_', 'fermentation_', 'sensory_'],
    'inventory' => ['stock_', 'purchase_', 'equipment_', 'dry_goods_', 'raw_material_'],
    'accounting' => ['cost_', 'cogs_'],
    'dtc' => ['order_', 'club_', 'shipment_'],
    'pos' => ['pos_', 'terminal_'],
    // default => 'production'
];
```
```php
// EventLogger.php
private function resolveSource(string $operationType): string
{
    foreach (config('event-sources') as $source => $prefixes) {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($operationType, $prefix)) {
                return $source;
            }
        }
    }
    return 'production';
}
```

Each new module just adds its prefixes to config. EventLogger never changes.

**Estimated effort:** 30 minutes. Create config file, update method, verify existing tests still pass.

---

## 3. LabImport Parser Registration is Hardcoded

**Where:** `app/Services/LabImport/LabImportService.php` constructor.

**Problem:** The `LabImportService` directly instantiates its parsers: `$this->parsers = [new ETSLabsParser(), new GenericCSVParser()]`. Adding support for a new lab (WineScan, OenoFoss, Vinmetrica) requires modifying this constructor. The `LabCsvParser` interface exists and is well-designed — the registration mechanism just doesn't match the pattern.

**Why it's HIGH:** Lab integrations are a Phase 3 selling point and a differentiator you called out in the architecture docs. Each new lab format should be a drop-in class, not a constructor edit.

**Fix — tagged service provider registration:**
```php
// AppServiceProvider or a dedicated LabImportServiceProvider
$this->app->tag([
    ETSLabsParser::class,
    GenericCSVParser::class,
], 'lab-csv-parsers');

$this->app->when(LabImportService::class)
    ->needs('$parsers')
    ->giveTagged('lab-csv-parsers');
```
```php
// LabImportService constructor
public function __construct(
    protected LabAnalysisService $labService,
    protected EventLogger $eventLogger,
    /** @var LabCsvParser[] */
    #[Tagged('lab-csv-parsers')]
    protected iterable $parsers,
) {}
```

New parsers just get added to the tag list in the provider. `LabImportService` never changes.

**Estimated effort:** 30 minutes. Create or update the service provider, adjust constructor, test.
