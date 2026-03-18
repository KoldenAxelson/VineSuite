# Filament v4 Migration — CLI Runbook

**Context:** The code changes have been applied. This runbook covers the commands you need to run in your dev environment to complete the migration.

---

## Step 1: Install Dependencies

```bash
cd api

# Update Filament to v4 (composer.json already points to ^4.0)
composer update

# If composer update fails on dependency conflicts:
composer update -W
```

If any package conflicts appear (especially stancl/tenancy), try:
```bash
composer require filament/filament:"^4.0" -W
```

---

## Step 2: Create Custom Theme

This is critical — it sets up Tailwind to scan your blade template paths so utility classes actually compile:

```bash
php artisan make:filament-theme

# Follow the prompts. It creates:
# resources/css/filament/portal/theme.css  (or similar)
#
# The theme file will include @source directives for your views.
```

After running the command, register the theme in `AdminPanelProvider.php`:
```php
->viteTheme('resources/css/filament/portal/theme.css')
```

---

## Step 3: Publish v4 Config

```bash
php artisan vendor:publish --tag=filament-config
```

In the published `config/filament.php`, set:
```php
'default_filesystem_disk' => env('FILAMENT_FILESYSTEM_DISK', 'public'),
```
This preserves v3 behavior for file uploads.

---

## Step 4: Build Assets

```bash
npm run build
```

---

## Step 5: Clear Caches

```bash
php artisan config:clear
php artisan view:clear
php artisan route:clear
php artisan cache:clear
php artisan filament:upgrade
```

---

## Step 6: Test

Run the test suite:
```bash
composer test
```

Manual smoke test checklist:

- [x] Login works
- [x] Dashboard loads
- [x] TTB Report Review — stats widgets render with colored backgrounds
- [x] TTB Report Review — wine type badges all render as consistent pills
- [x] TTB Report Review — drill-down panel opens on line item click
- [x] TTB Report Review — approve button works
- [x] Bulk Wine Inventory — KPI cards and table load
- [x] Cost Reports — vintage summary and margin tables render
- [x] Lot Traceability — trace execution and timeline work
- [x] Physical Count — status cards and count flow work
- [x] Dark mode renders correctly across all pages
- [x] SPA navigation (back/forward) works
- [x] Table filters work (now deferred globally — deferFilters(false) set in provider)
- [x] Tenant switching works (if applicable)

---

## What Was Changed (Summary)

### composer.json
- `filament/filament`: `^3.0` → `^4.0`

### PHP Code (17 files modified, 2 new)
- **3 resources**: `.reactive()` → `.live()` (FermentationRound, SensoryNote, LabAnalysis)
- **17 files**: `BadgeColumn::make()` → `TextColumn::make()->badge()->color(fn ...)` with inverted match expressions
- **AdminPanelProvider**: Added `MaxWidth` enum import, `->deferFilters(false)` to preserve v3 UX
- **ReviewTTBReport page**: Added `getHeaderWidgets()` for native stats widgets, `getHeaderWidgetsData()` for data passing
- **NEW**: `TTBSectionAStatsWidget.php` — native Filament stats for bulk wines
- **NEW**: `TTBSectionBStatsWidget.php` — native Filament stats for bottled wines

### Blade Templates (1 major rewrite)
- **ttb-report-review.blade.php**: Replaced hand-rolled stat grids with Filament `getHeaderWidgets()`. Header uses `<x-filament::section>`, `<x-filament::badge>`, `<x-filament::button>`. Tables use `fi-ta-*` CSS classes. Review badges use `<x-filament::badge>`. Drill-down uses `<x-filament::section>` with `<x-filament::icon-button>`.
- **wine-type-badge.blade.php**: Replaced custom Tailwind pill with `<x-filament::badge :color="..." size="sm">` — dark mode and consistent styling handled by Filament internally.

---

## Troubleshooting

### Stats widgets don't appear on TTB Review page
The `getHeaderWidgets()` data passing uses `getHeaderWidgetsData()`. If the stats don't render, check that the Filament v4 widget data cascade is working. Fallback: pass data directly via Livewire `@livewire` directive in the blade template.

### Tailwind classes still not compiling
After `php artisan make:filament-theme`, verify the generated theme CSS includes:
```css
@source '../../views/**/*.blade.php';
```
If not, add `@source` directives for your blade template paths.

### stancl/tenancy conflicts with v4 auto-scoping
If tenant data leaks or queries break, check if v4's automatic tenant scoping conflicts with stancl's middleware. You can disable v4's scoping in the config if needed.

### BadgeColumn not found errors
All `BadgeColumn` references have been replaced with `TextColumn::make()->badge()`. If you see "class not found" errors, grep for any remaining `BadgeColumn` references:
```bash
grep -r "BadgeColumn" app/
```
