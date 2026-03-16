# Widget Development

> Filament v3 widgets in Livewire v3 SPA have critical timing issues. This doc captures patterns that work.

---

## Core Rule

**Never use Alpine.data() registration.** Use inline `x-data` objects with `init()` methods.

The `alpine:init` event only fires once on first page load. SPA navigation swaps DOM via Livewire morph; `alpine:init` doesn't re-fire. Registered Alpine components silently break.

---

## Approved Pattern: Inline Alpine + @assets

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
                        // Rendering logic
                    },
                }"
                class="w-full"
            >
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

**Why each piece:**
- **Inline x-data** — Alpine picks it up during Livewire morph; no registration needed
- **init() with $nextTick()** — Ensures DOM (x-ref elements) rendered before code runs
- **@assets / @endassets** — Livewire v3: loads external scripts once per page lifecycle, works across SPA navigations
- **@js($chartData)** — Safe PHP-to-JS serialization; handles escaping, nested arrays, nulls

---

## Pattern: Prevent Auto-Discovery

Filament auto-discovers all widget classes. For page-specific widgets, opt out:

```php
class FermentationCurveChart extends Widget
{
    protected static bool $isDiscovered = false;  // Not on Dashboard

    public ?string $roundId = null;  // Data from parent page
}
```

Without this, widget appears on Dashboard with no context, shows errors.

---

## Pattern: Pass Data from Parent Page

Use `Widget::make([...])` in the ViewRecord page:

```php
protected function getFooterWidgets(): array
{
    return [
        FermentationCurveChart::make([
            'roundId' => $this->record->getKey(),
        ]),
    ];
}
```

Widget declares matching public properties. **Do NOT use `getFooterWidgetsData()`** — that method doesn't exist in Filament v3.

---

## Anti-Patterns

**Alpine.data() via alpine:init** — Works on first load, breaks on SPA navigation. Errors: "Can't find variable".

**<script> tags in Blade body** — Re-executes on every SPA navigation. Use `@assets` / `@endassets`.

**getFooterWidgetsData()** — Doesn't exist. Use `Widget::make([...])`.

---

## New Widget Checklist

1. Create class in `app/Filament/Widgets/`
2. Set `protected static bool $isDiscovered = false` if page-specific
3. Declare public properties for parent-page data
4. Create Blade view in `resources/views/filament/widgets/`
5. Use inline `x-data` with `init()` and `$nextTick()`
6. Load external scripts via `@assets / @endassets`
7. Always include empty state (`@if ($hasData) ... @else ...`)
8. **Test by navigating TO the page from another Filament page** — SPA bugs surface here, not on direct URL

---

*Created during Phase 3 fermentation chart debugging. Root cause: `alpine:init` timing, auto-discovery, missing `::make()` pattern.*
