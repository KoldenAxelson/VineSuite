# Filament v3 → v4 Migration Scope

**Created:** 2026-03-17
**Status:** ✅ Delivered — Migration complete (pre-Task 7)
**Priority:** High — should precede Task 7 (KMP Shared Core)
**Estimated Effort:** 2–3 days for core migration, +1–2 days to replace custom blade templates with native Filament components

---

## Strategic Context

We've been fighting Tailwind JIT compilation issues and dark mode opacity hacks in hand-rolled blade templates (TTB report review, bulk wine inventory, cost reports). Custom stat boxes using classes like `dark:bg-blue-900/30` either don't compile into the built CSS or render nearly invisible at low opacity against the dark background. Wine-type badges suffer from the same issue — `rounded-full` styling doesn't take effect reliably.

**The fix isn't more Tailwind tweaking. The fix is using Filament's native component system**, which handles dark mode, badge rendering, stat cards, and color theming internally. Filament v4 makes this dramatically easier with its unified Schema, PHP-based page layouts, and improved widget system.

**Theme strategy simplified:** Instead of 20+ preset themes, we focus on making Filament's built-in Light and Dark modes look polished. The CSS custom property token system (`--vs-*`) stays in `app.css` as a foundation for a future theme picker, but it's no longer the priority.

---

## Why Upgrade Now

Filament v4 has been stable since August 2025 (currently v4.5+). The longer we wait, the more custom blade templates and workarounds accumulate in v3 that v4 solves natively. Upgrading before Task 7 means the KMP mobile apps will build against a stable, modern admin panel API from the start.

**Key wins for VineSuite:**

1. **Unified Schema architecture** — Forms and infolists share a single `Schema` class. Our 24 resources currently duplicate field definitions between `form()` and `infolist()` methods. Schema unification cuts that maintenance surface roughly in half.

2. **Customizable page layouts in PHP** — v4 lets you configure page layouts (split panels, multi-column, KPI bars) directly in PHP resource classes. This could eliminate several of our custom blade templates (TTB report review, bulk wine inventory, cost reports, physical count) over time, since the layout logic moves into the resource rather than living in a hand-rolled `.blade.php`.

3. **Automatic tenancy scoping** — v4 auto-scopes all queries in panels to the current tenant. We currently rely on stancl/tenancy's middleware + manual scoping in some places. v4's native scoping is more reliable and reduces boilerplate.

4. **Deferred table filters** — Filters apply on button click instead of live. Better UX for data-heavy tables like TTB line items and bulk inventory where each filter change triggered a full query.

5. **Improved dark mode + theming** — v4 moves many Tailwind classes into `@apply` rules in CSS, making custom theme overrides cleaner. This directly supports our "Dark Grape" theme and the planned theme picker.

---

## Current State Inventory

### Dependencies

| Package | Current | v4 Requirement | Action |
|---------|---------|----------------|--------|
| PHP | ^8.2 | 8.2+ | Already met |
| Laravel | ^12.0 | 11.28+ | Already met |
| Tailwind CSS | v4 (via @tailwindcss/vite) | v4.1+ | Verify exact version, likely met |
| filament/filament | ^3.0 | ^4.0 | Upgrade |
| stancl/tenancy | ^3.9 | Verify v4 compat | Check release notes |
| spatie/laravel-permission | ^7.2 | Should be fine | No Filament coupling |
| barryvdh/laravel-dompdf | ^3.0 | No Filament coupling | No change |
| larastan/larastan | ^3.0 | v3+ required by upgrade script | Already met |

**No third-party Filament plugins** — we only use the core `filament/filament` package. This is a huge advantage: no waiting on plugin authors to ship v4 support.

### Filament Surface Area

| Component | Count | Migration Complexity |
|-----------|-------|---------------------|
| Resources (full CRUD) | 24 | Low — automated script handles most changes |
| Custom Pages | 5 | Medium — blade templates need manual review |
| Widgets | 1 | Low |
| Relation Managers | 6 | Low — automated script |
| Custom blade templates | ~8 | Medium — Tailwind classes in views need custom theme |
| Admin Panel Provider | 1 | Low — config structure changes |

### Custom Pages (require manual attention)

1. **Dashboard.php** — Likely minimal changes
2. **BulkWineInventory.php** — Custom blade with KPI cards + table
3. **CostReports.php** — Custom blade with KPI cards + custom tables + margin report
4. **LotTraceability.php** — Custom blade with trace UI + timeline
5. **PhysicalCount.php** — Custom blade with status cards + table

These pages use Tailwind utility classes directly in blade views. In v4, Filament moves its own Tailwind classes into CSS `@apply` rules. Since we use Tailwind classes in our own views, we need a custom theme (via `php artisan make:filament-theme`) to ensure our classes are compiled.

### CSS / Theme Considerations

Our `app.css` contains:
- CSS custom property tokens (`--vs-*`) for future theme picker
- Component CSS classes (`.vs-kpi-bar`, `.vs-section`, etc.) — currently unused, reserved for theme picker
- Filament overrides (`.fi-section`, `.fi-sidebar`, `.fi-body`)

v4 changes:
- Custom themes use `@source` directives instead of `@config` for content scanning
- Filament override selectors may have changed — need to audit `.fi-*` selectors
- Our token system is compatible since it uses standard CSS custom properties

---

## Migration Plan

### Phase 1: Preparation (30 min)

1. Create a feature branch: `feature/filament-v4-migration`
2. Ensure all tests pass on current v3
3. Commit any uncommitted UI work (stat box / badge fixes)
4. Back up `composer.lock`

### Phase 2: Automated Upgrade (~1 hour)

```bash
# Install the upgrade tool
composer require filament/upgrade:"^4.0" -W --dev

# Run the automated migration script
vendor/bin/filament-v4

# Update the Filament dependency
composer require filament/filament:"^4.0" -W --no-update
composer update

# Optional: migrate to new directory structure
php artisan filament:upgrade-directory-structure-to-v4 --dry-run
# Review output, then:
php artisan filament:upgrade-directory-structure-to-v4

# Clean up
composer remove filament/upgrade
```

The automated script handles:
- `actions()` → `recordActions()` in tables
- Form/infolist injection parameter changes
- Navigation icon property type updates
- URL parameter name simplifications
- Method signature updates

### Phase 3: Manual Fixes (~2–4 hours)

1. **Custom theme setup** — Since we use Tailwind classes in blade views:
   ```bash
   php artisan make:filament-theme
   ```
   Update the generated theme CSS with `@source` directives for our custom blade paths and integrate our existing `--vs-*` token system.

2. **AdminPanelProvider review** — Update configuration for v4 changes:
   - Publish new config: `php artisan vendor:publish --tag=filament-config`
   - Set `default_filesystem_disk` to `public` to preserve v3 behavior
   - Review tenancy integration — v4's auto-scoping may simplify or conflict with stancl/tenancy middleware

3. **Custom page blade templates** — Audit each for deprecated Filament component usage:
   - `<x-filament::section>` — should still work but check API changes
   - `<x-filament-panels::page>` — verify wrapper component
   - Wire click handlers — verify Livewire compatibility

4. **Column/field `columnSpan` behavior** — v4 defaults to `lg` breakpoint targeting. Audit resources for any explicit `columnSpan()` calls that assumed full-width.

5. **Table filter UX** — Filters are now deferred by default. Decide per-table whether to keep deferred (better for TTB line items with many rows) or restore live filtering with `deferFilters(false)`.

6. **Run PHPStan/Larastan** to catch any remaining type mismatches:
   ```bash
   ./vendor/bin/phpstan analyse
   ```

### Phase 4: Testing (~2 hours)

1. Run full test suite: `composer test`
2. Manual smoke test of each custom page:
   - TTB Report Review — stat boxes, line item tables, drill-down panel
   - Bulk Wine Inventory — KPI cards, table interactions
   - Cost Reports — vintage summary, margin report tables
   - Lot Traceability — trace execution, timeline rendering
   - Physical Count — status cards, count session flow
3. Verify dark mode rendering across all pages
4. Check SPA navigation (`.spa()` mode) still works
5. Test tenant switching if applicable

### Phase 5: Replace Custom Blade Templates with Native Components (+1–2 days)

This is no longer optional — it's the primary reason for the migration. Our hand-rolled blade templates with Tailwind utility classes are actively causing rendering issues (Tailwind JIT not compiling unused classes, `/30` opacity tints invisible in dark mode, badge formatting inconsistencies). Filament's native component system handles all of this internally.

**TTB Report Review (`ttb-report-review.blade.php`):**
- Replace custom stat box grid with Filament `StatsOverviewWidget` or `Stat` entries — these handle colored backgrounds, dark mode, and responsive layout natively
- Replace custom HTML table with Filament `Table` component — wine-type column uses `TextColumn::badge()` which renders consistent pill badges with proper color theming out of the box
- Replace drill-down panel with a Filament `Modal` or `Infolist` section
- Status badge in header → `TextColumn::badge()` or a Filament `Badge` component

**Bulk Wine Inventory (`bulk-wine-inventory.blade.php`):**
- KPI cards → `StatsOverviewWidget` with `Stat::make()` entries
- Inventory table → Filament `Table` with sortable/filterable columns

**Cost Reports (`cost-reports.blade.php`):**
- KPI cards → `StatsOverviewWidget`
- Vintage summary and margin tables → Filament `Table` components

**Lot Traceability (`lot-traceability.blade.php`):**
- Trace steps → Filament `Infolist` with `RepeatableEntry` or custom `ViewEntry`
- Timeline → Filament `Infolist` section (or keep blade partial if the timeline is complex enough)

**Physical Count (`physical-count.blade.php`):**
- Status card → `StatsOverviewWidget` with conditional coloring
- Count table → Filament `Table`

**Wine-Type Badge Partial (`partials/wine-type-badge.blade.php`):**
- Delete entirely. Replace with `TextColumn::badge()->color(fn ($state) => match($state) { ... })` on each table that shows wine types. Filament handles the pill shape, dark mode colors, and consistent rendering.

---

## Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| stancl/tenancy incompatibility | Low | High | Test on branch first. stancl/tenancy v3.9 uses middleware hooks that should survive v4's changes. v4's auto-scoping may conflict — disable with config if needed. |
| Custom blade templates break | Medium | Medium | These are our own views, not Filament internals. The wrapper components (`<x-filament-panels::page>`, `<x-filament::section>`) are stable API surface. Manual audit covers this. |
| Dark mode regression | Medium | Medium | We just stabilized "Dark Grape" with Tailwind utility classes. v4's CSS restructuring shouldn't affect our classes since they're standard Tailwind, not Filament internals. Test thoroughly. |
| Deferred filters confuse users | Low | Low | Opt-in per table. Keep deferred for heavy tables, restore live for light ones. |
| `.fi-*` CSS overrides break | Medium | Low | Our app.css has `.fi-section`, `.fi-sidebar`, `.fi-body` overrides. v4 may rename or restructure these. Audit and update selectors. |

---

## Decision: New Directory Structure?

v4 offers a new directory structure:

**v3 (current):**
```
app/Filament/Resources/LotResource.php
app/Filament/Resources/LotResource/Pages/CreateLot.php
app/Filament/Resources/LotResource/Pages/EditLot.php
app/Filament/Resources/LotResource/Pages/ListLots.php
```

**v4 (optional):**
```
app/Filament/Lots/LotResource.php
app/Filament/Lots/CreateLot.php
app/Filament/Lots/EditLot.php
app/Filament/Lots/ListLots.php
```

**Recommendation: Adopt the new structure.** With 24 resources, the flatter layout makes navigation easier and the `--dry-run` flag lets us preview changes safely. The automated command handles the migration.

---

## Recommendation

**Proceed with migration before Task 7.** The risk is low (no third-party Filament plugins, Laravel 12 already meets requirements, automated tooling handles most changes), the payoff is high (unified Schema, better theming, auto-tenancy scoping), and doing it now prevents accumulating more v3-specific code that would need rework later.

Phase 5 (replacing custom blade templates with native Filament components) is the critical payoff. The stat box and badge rendering issues we've been fighting are symptoms of building UI outside Filament's component system. Once on v4, the custom blade templates get replaced with `StatsOverviewWidget`, `Table`, `TextColumn::badge()`, and `Infolist` — all of which handle dark mode, responsive layout, and color theming natively. No more hand-tuning Tailwind opacity values.

**Theme approach:** Focus on polished Light/Dark mode through Filament's native theming. The `--vs-*` CSS custom property tokens stay in `app.css` as foundation for a future theme picker, but that's Phase 8+ ambition. For now: make the default look great.
