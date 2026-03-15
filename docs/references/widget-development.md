# Widget Development

> Last updated: 2026-03-15
> Relevant source: `api/resources/views/filament/widgets/`, `api/app/Filament/Widgets/`
> Learned from: Phase 3 fermentation chart widget (FermentationCurveChart)

---

## Why This Doc Exists

Filament v3 widgets running inside Livewire v3's SPA navigation have a critical timing issue that isn't obvious from any single framework's docs. The `alpine:init` event only fires once on the very first page load — if you register an Alpine component via `Alpine.data()` inside that event, it will work on the initial page load but silently break when the user navigates between Filament pages (because Livewire swaps the DOM via SPA morph, and `alpine:init` doesn't re-fire). This doc captures the patterns that actually work.

---

## The Core Rule

**Never use `Alpine.data()` registration in Filament widgets.** Use inline `x-data` objects with `init()` methods instead.

This is the single most important rule. Everything else follows from it.

---

## Pattern: Inline Alpine + @assets

This is the approved pattern for any widget that needs JavaScript libraries (Chart.js, D3, etc.) or any Alpine component logic.

```blade
<x-filament-widgets::widget>
    <x-filament::section>
        @if ($hasData)
            <div
                x-data="{
                    chart: null,
                    chartData: @js($chartData),

                    init() {
                        this.$nextTick(() => { this.renderChart() });
                    },

                    renderChart() {
                        // Your rendering logic here
                    },
                }"
                class="w-full"
            >
                {{-- Widget HTML --}}
                <canvas x-ref="chartCanvas"></canvas>
            </div>

            @assets
                <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
            @endassets
        @else
            {{-- Empty state --}}
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
```

### Why each piece matters

**`x-data="{ ... }"` inline:** The Alpine component is declared directly on the element, so Alpine picks it up during the Livewire morph — no registration step needed, no reliance on `alpine:init`.

**`init()` with `$nextTick()`:** The `init()` method fires when Alpine initializes the component. `$nextTick()` ensures the DOM (including `x-ref` elements) is fully rendered before your code runs.

**`@assets` / `@endassets`:** Livewire v3 directive that loads external scripts exactly once per page lifecycle, even across SPA navigations. This is the correct way to load CDN scripts — not `<script>` tags in the Blade body (which would re-execute on every navigation) and not `@push('scripts')` (which doesn't work reliably with SPA mode).

**`@js($chartData)`:** Blade directive that safely serializes PHP data into a JavaScript literal. Handles escaping, nested arrays, nulls. Always prefer this over `JSON.parse('{!! json_encode(...) !!}')`.

---

## Pattern: Preventing Dashboard Auto-Discovery

Filament auto-discovers any widget class in `app/Filament/Widgets/` and places it on the main Dashboard. If your widget is meant for a specific resource page (like a ViewRecord footer), you must opt out of auto-discovery.

```php
class FermentationCurveChart extends Widget
{
    /**
     * Prevent Filament from auto-discovering this widget on the Dashboard.
     * It is only used explicitly on the ViewFermentationRound page footer.
     */
    protected static bool $isDiscovered = false;

    public ?string $roundId = null;
    // ...
}
```

Without `$isDiscovered = false`, the widget appears on the Dashboard with no context data, shows an empty state or throws errors, and if it uses `Alpine.data()` registration, the Alpine errors will pollute the console on every page.

---

## Pattern: Passing Properties to Page Widgets

When a widget needs data from the parent page (like a record ID), use Filament's `::make()` method on the ViewRecord page:

```php
// In the ViewRecord page class
protected function getFooterWidgets(): array
{
    return [
        FermentationCurveChart::make([
            'roundId' => $this->record->getKey(),
        ]),
    ];
}
```

The `::make()` call passes Livewire component properties. The widget class declares matching public properties:

```php
class FermentationCurveChart extends Widget
{
    public ?string $roundId = null;
}
```

**Do NOT use `getFooterWidgetsData()`.** That method does not exist in Filament v3. The `::make([...])` pattern is the correct approach — it maps directly to Livewire component property initialization.

---

## Anti-Patterns

### Alpine.data() registration via alpine:init

```blade
{{-- BAD — breaks on SPA navigation --}}
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('fermentationChart', () => ({
        chart: null,
        init() { this.renderChart(); },
        renderChart() { /* ... */ },
    }));
});
</script>

<div x-data="fermentationChart"> ... </div>
```

This works on the first page load. It breaks silently when Livewire swaps the page content via SPA morph. You'll see console errors like `Can't find variable: fermentationChart` and the widget renders blank.

### Script tags in Blade body

```blade
{{-- BAD — re-executes on every SPA navigation --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
```

Use `@assets` / `@endassets` instead.

### getFooterWidgetsData() for property passing

```php
// BAD — method doesn't exist in Filament v3
protected function getFooterWidgetsData(): array
{
    return ['roundId' => $this->record->getKey()];
}
```

Use `Widget::make([...])` in `getFooterWidgets()` instead.

---

## Checklist for New Widgets

1. Create the widget class in `app/Filament/Widgets/`
2. Set `protected static bool $isDiscovered = false` if it's page-specific
3. Declare public properties for any data the parent page will pass
4. Create the Blade view in `resources/views/filament/widgets/`
5. Use inline `x-data` with `init()` and `$nextTick()` for any JS logic
6. Load external scripts via `@assets` / `@endassets`
7. Always include an empty state (`@if ($hasData) ... @else ... @endif`)
8. Test by navigating TO the page from another Filament page (not just direct URL) — this is when SPA bugs surface

---

## History
- 2026-03-15: Created after Phase 3 fermentation chart debugging. Root causes: `alpine:init` timing, auto-discovery, missing `::make()` pattern.
