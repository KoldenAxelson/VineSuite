# VineSuite UI/UX Design Guide

> Living reference for all visual and interaction patterns in the VineSuite admin portal.
> Pairs with `docs/DESIGN.md` (research/strategy) — this document is the **implementation spec**.

---

## 1. Design philosophy

VineSuite follows three governing principles borrowed from Linear and InnoVint:

**Calm by default, loud only when earned.** Color, motion, and prominence are reserved for things that genuinely need attention. A screen full of purple badges and bold numbers tells the user nothing — semantic color on the one metric that's off-target tells them everything.

**Desktop-first data entry, mobile-aware scanning.** The portal is a widescreen workstation tool. Layouts optimize for 1280px+ with graceful degradation to tablet and phone. Mobile gets purpose-built views (cellar scanning, work order completion), not shrunken desktop layouts.

**One good way, not infinite flexibility.** Opinionated component patterns mean every page feels like the same product. Developers pick from a small kit of approved layouts rather than inventing new card shapes per page.

---

## 2. Theme architecture

### 2.1 CSS custom properties (design tokens)

All visual values flow through CSS custom properties defined in `resources/css/app.css`. Nothing in blade templates should contain raw hex codes, hardcoded `text-blue-600`, or one-off `bg-gray-50` for semantic purposes. Instead, templates reference token-backed utility classes.

The token hierarchy:

```
Primitive tokens     →  Raw color values (grape-50, grape-600, etc.)
Semantic tokens      →  Purpose-mapped (--vs-surface, --vs-text-primary, --vs-accent)
Component tokens     →  Scoped overrides (--vs-kpi-value, --vs-table-header-bg)
```

### 2.2 Theme presets

Themes swap the semantic token layer. The primitive palette stays constant; only the mapping changes. Each preset is a single CSS class applied to `<html>` or `<body>`.

**Shipped presets:**

| Preset | Class | Personality |
|---|---|---|
| Vineyard (default) | `.theme-vineyard` | Warm neutrals, purple accent. Clean, professional. |
| Matrix | `.theme-matrix` | Black background, phosphor green text, monospace feel. |
| Island Fresh | `.theme-island` | Bright teals, coral accents, airy white space. |
| Cellar Dark | `.theme-cellar` | Deep charcoal, amber/gold accents. Dim-light friendly. |
| Harvest Gold | `.theme-harvest` | Cream backgrounds, rich burgundy and gold. Classic winery. |

Theme selection lives in **Settings → Appearance** and persists per-user via a `theme` column on the user model (or a user_preferences JSON column). No auto-detection — the user explicitly picks.

### 2.3 Token reference

```css
/* ── Surface & background ─────────────────────────────── */
--vs-bg-page:            /* Page canvas behind everything */
--vs-bg-surface:         /* Card / section background */
--vs-bg-surface-alt:     /* Alternating row, nested card */
--vs-bg-surface-hover:   /* Hover state on interactive surfaces */
--vs-bg-inset:           /* Recessed areas (code blocks, KPI sub-labels) */

/* ── Text ─────────────────────────────────────────────── */
--vs-text-primary:       /* Headings, values, primary labels */
--vs-text-secondary:     /* Descriptions, sub-labels */
--vs-text-tertiary:      /* Timestamps, captions, disabled */
--vs-text-on-accent:     /* Text sitting on accent-colored backgrounds */

/* ── Borders & separators ─────────────────────────────── */
--vs-border:             /* Default card/table borders */
--vs-border-subtle:      /* Very light row separators */
--vs-ring-focus:         /* Focus ring on inputs/buttons */

/* ── Accent (primary action color) ────────────────────── */
--vs-accent:             /* Primary buttons, active nav, links */
--vs-accent-hover:       /* Hover on accent elements */
--vs-accent-subtle:      /* Light accent tint for badges/chips */

/* ── Semantic status ──────────────────────────────────── */
--vs-success:            /* Positive values, verified, completed */
--vs-success-subtle:     /* Background tint for success badges */
--vs-warning:            /* Needs attention, in progress */
--vs-warning-subtle:
--vs-danger:             /* Errors, critical variances, overdue */
--vs-danger-subtle:
--vs-info:               /* Informational badges, neutral highlights */
--vs-info-subtle:

/* ── Sidebar ──────────────────────────────────────────── */
--vs-sidebar-bg:
--vs-sidebar-text:
--vs-sidebar-text-active:
--vs-sidebar-accent:
```

---

## 3. Layout system

### 3.1 The content width problem

Filament is configured with `maxContentWidth('full')`. This is correct for tables but causes small elements (KPI stat cards, single-line timestamps) to balloon across 1920px+ screens. The fix is **not** reducing max-width globally but constraining content zones within the full-width canvas.

### 3.2 Responsive grid strategy

Every page follows one of three layout archetypes:

**Archetype A — KPI bar + data table (most pages)**
```
┌─────────────────────────────────────────────────────┐
│  KPI  │  KPI  │  KPI  │  KPI  │  KPI  │  (flex)    │  ← auto-fit, max 5 visible
├─────────────────────────────────────────────────────┤
│                                                     │
│  Filament table (full width)                        │
│                                                     │
└─────────────────────────────────────────────────────┘
```

**Archetype B — split panels (traceability, drill-downs)**
```
┌───────────────────────┬─────────────────────────────┐
│                       │                             │
│  Selector / tree      │  Detail / timeline          │
│  (sidebar, 320-400px) │  (fills remaining width)    │
│                       │                             │
└───────────────────────┴─────────────────────────────┘
```

**Archetype C — vertical stack with sections (TTB review, settings)**
```
┌─────────────────────────────────────────────────────┐
│  Header bar (title, status badge, action button)    │
├─────────────────────────────────────────────────────┤
│  Alert / flag banner (conditional)                  │
├──────────────────────────┬──────────────────────────┤
│  Summary section A       │  Summary section B       │
├──────────────────────────┴──────────────────────────┤
│  Detail table (full width)                          │
└─────────────────────────────────────────────────────┘
```

### 3.3 Breakpoints

| Token | Width | Behavior |
|---|---|---|
| `sm` | 640px | Stack everything to single column |
| `md` | 768px | KPI cards → 2-col, tables get horizontal scroll |
| `lg` | 1024px | KPI cards → 3-col, split panels activate |
| `xl` | 1280px | Full layout. KPI cards → auto-fit row |
| `2xl` | 1536px+ | KPI cards cap at intrinsic width, don't stretch further |

### 3.4 KPI card grid — the fix

The current pattern is `grid grid-cols-1 md:grid-cols-5` which forces 5 equal columns on any screen ≥768px. A small "Active Lots: 12" stat card stretching to 350px on an ultrawide looks absurd.

**New pattern:**
```html
<div class="vs-kpi-bar">
    <!-- cards auto-size to content, wrap naturally -->
</div>
```

Backed by:
```css
.vs-kpi-bar {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1rem;
    max-width: 1200px; /* prevents balloon on ultrawides */
}

@media (max-width: 640px) {
    .vs-kpi-bar {
        grid-template-columns: repeat(2, 1fr);
    }
}
```

This means 5 KPIs on a 1440px screen, 3 on a 1024px tablet, 2 on a phone — all without manual breakpoint classes per page.

---

## 4. Component patterns

### 4.1 KPI stat card

Every KPI card must answer three questions: **What is it? What's the number? Is that good or bad?**

Structure:
```html
<div class="vs-kpi-card">
    <span class="vs-kpi-label">Vessel Volume</span>
    <span class="vs-kpi-value">2,847 gal</span>
    <!-- optional: trend or comparison -->
    <span class="vs-kpi-trend vs-kpi-trend--up">+3.2%</span>
</div>
```

Rules:
- Label is **always** `text-sm`, secondary color, uppercase tracking
- Value is **always** `text-2xl font-semibold`, tabular figures
- Trend indicator uses semantic color (green up, red down, neutral gray for flat)
- No `<x-filament::section>` wrapper for KPI cards — it adds too much padding/chrome for a stat chip
- Values that represent currency always use `$` prefix; volumes always include unit suffix (`gal`, `L`)
- Round to whole numbers in KPI display. Decimal precision belongs in tables.

### 4.2 Section card

For grouped content (tables, forms, detail panels). Uses Filament's `<x-filament::section>` **or** a standardized `vs-section` div for custom pages that need more control.

```html
<div class="vs-section">
    <div class="vs-section-header">
        <h3 class="vs-section-title">Bulk Wine by Lot</h3>
        <p class="vs-section-description">Active and aging lots with vessel variance.</p>
    </div>
    <div class="vs-section-body">
        {{ $this->table }}
    </div>
</div>
```

Rules:
- Title: `text-base font-semibold` — never `text-lg font-bold` (too heavy for a section within a page)
- Description: `text-sm`, tertiary text color, 1 line max
- Card background uses `--vs-bg-surface`, rounded-xl, subtle shadow (`shadow-sm`)
- **No** double-nesting: never put a `vs-section` inside an `<x-filament::section>`

### 4.3 Data table

Filament's table builder handles 80% of cases. For custom tables (TTB line items, cost vintage summary, margin report):

```html
<table class="vs-table">
    <thead>
        <tr>
            <th class="vs-th vs-th--left">Description</th>
            <th class="vs-th vs-th--right">Gallons</th>
        </tr>
    </thead>
    <tbody>
        <tr class="vs-tr">
            <td class="vs-td">Opening Inventory</td>
            <td class="vs-td vs-td--mono vs-td--right">1,240</td>
        </tr>
    </tbody>
</table>
```

Rules:
- Numeric columns: right-aligned, `font-variant-numeric: tabular-nums`, monospace optional
- Text columns: left-aligned
- Row hover: `--vs-bg-surface-hover`
- Row borders: `--vs-border-subtle`, only between rows (no top/bottom border on the table itself)
- Header: `text-xs uppercase tracking-wider`, secondary text color, lighter weight
- Interactive rows (clickable for drill-down): add `cursor-pointer` and a slightly more visible hover

### 4.4 Status badge

Small inline indicator for states like draft/reviewed/filed, in_progress/completed/cancelled.

```html
<span class="vs-badge vs-badge--warning">Draft</span>
<span class="vs-badge vs-badge--success">Verified</span>
<span class="vs-badge vs-badge--danger">ERROR</span>
```

Rules:
- Always pair color with text (never color alone — accessibility)
- Use subtle background tint + darker text, not solid fills
- Sizes: `text-xs px-2 py-0.5 rounded-full font-medium`
- Maximum 5 badge variants: `success`, `warning`, `danger`, `info`, `neutral`

### 4.5 Alert / flag banner

For attention-needed items (TTB review flags, variance warnings).

```html
<div class="vs-alert vs-alert--warning">
    <div class="vs-alert-icon"><!-- heroicon --></div>
    <div class="vs-alert-content">
        <h4 class="vs-alert-title">Items Requiring Review</h4>
        <ul class="vs-alert-list">
            <li>Volume variance exceeds 2% on Line 4</li>
        </ul>
    </div>
</div>
```

Rules:
- Border-left accent (4px solid) using the semantic color
- Background uses the `--subtle` variant of the semantic color
- Never use `<ul class="list-disc">` — use custom styled list markers or none at all
- Dismiss action optional for informational alerts, never for compliance warnings

### 4.6 Timeline

Used for lot traceability's "Complete Timeline" and event history views.

```html
<div class="vs-timeline">
    <div class="vs-timeline-item">
        <div class="vs-timeline-dot"></div>
        <div class="vs-timeline-content">
            <div class="vs-timeline-header">
                <span class="vs-timeline-title">Transfer to Tank 12</span>
                <span class="vs-timeline-date">Mar 7, 2025 2:15 PM</span>
            </div>
            <span class="vs-timeline-meta">lot_transfer</span>
        </div>
    </div>
</div>
```

Rules:
- Vertical line: `--vs-border` color, 2px wide, positioned left
- Dot: 12px circle, filled with `--vs-accent` for the most recent event, `--vs-border` for older ones
- Content card: `--vs-bg-surface-alt` background
- On mobile (< 640px): remove the vertical line, stack items as simple cards with date above

### 4.7 Page header bar

For pages that need a prominent title + status + action (TTB review, physical count detail).

```html
<div class="vs-page-header">
    <div class="vs-page-header-info">
        <h2 class="vs-page-header-title">TTB Form 5120.17 — January 2025</h2>
        <div class="vs-page-header-meta">
            <span>Generated: Jan 31, 2025 4:00 PM</span>
            <span class="vs-badge vs-badge--warning">Draft</span>
        </div>
    </div>
    <div class="vs-page-header-actions">
        <button class="vs-btn vs-btn--primary">Approve for Filing</button>
    </div>
</div>
```

Rules:
- Title: `text-xl font-semibold` — not `text-2xl font-bold` (the page already has Filament's breadcrumb heading)
- Flex layout: info left, actions right; wraps on mobile
- Surface: `--vs-bg-surface`, same card styling as sections

---

## 5. Color system

### 5.1 Vineyard (default) palette

**Neutrals (warm-shifted slate):**
```
--vs-gray-50:   #f8f9fa
--vs-gray-100:  #f1f3f5
--vs-gray-200:  #e9ecef
--vs-gray-300:  #dee2e6
--vs-gray-400:  #adb5bd
--vs-gray-500:  #868e96
--vs-gray-600:  #6c757d
--vs-gray-700:  #495057
--vs-gray-800:  #343a40
--vs-gray-900:  #212529
```

**Accent (purple, inherited from current brand):**
```
--vs-purple-50:  #faf5ff
--vs-purple-100: #f3e8ff
--vs-purple-500: #8b5cf6
--vs-purple-600: #7c3aed
--vs-purple-700: #6d28d9
```

**Semantic mapping (Vineyard theme):**
```css
.theme-vineyard {
    --vs-bg-page:          var(--vs-gray-50);
    --vs-bg-surface:       #ffffff;
    --vs-bg-surface-alt:   var(--vs-gray-100);
    --vs-bg-surface-hover: var(--vs-gray-50);
    --vs-bg-inset:         var(--vs-gray-100);

    --vs-text-primary:     var(--vs-gray-900);
    --vs-text-secondary:   var(--vs-gray-600);
    --vs-text-tertiary:    var(--vs-gray-400);
    --vs-text-on-accent:   #ffffff;

    --vs-border:           var(--vs-gray-200);
    --vs-border-subtle:    var(--vs-gray-100);

    --vs-accent:           var(--vs-purple-600);
    --vs-accent-hover:     var(--vs-purple-700);
    --vs-accent-subtle:    var(--vs-purple-50);

    --vs-success:          #059669;
    --vs-success-subtle:   #ecfdf5;
    --vs-warning:          #d97706;
    --vs-warning-subtle:   #fffbeb;
    --vs-danger:           #dc2626;
    --vs-danger-subtle:    #fef2f2;
    --vs-info:             #2563eb;
    --vs-info-subtle:      #eff6ff;

    --vs-sidebar-bg:       var(--vs-gray-800);
    --vs-sidebar-text:     var(--vs-gray-400);
    --vs-sidebar-text-active: #ffffff;
    --vs-sidebar-accent:   var(--vs-purple-500);
}
```

### 5.2 Matrix preset

```css
.theme-matrix {
    --vs-bg-page:          #0a0a0a;
    --vs-bg-surface:       #111111;
    --vs-bg-surface-alt:   #1a1a1a;
    --vs-bg-surface-hover: #1f1f1f;
    --vs-bg-inset:         #0d0d0d;

    --vs-text-primary:     #00ff41;
    --vs-text-secondary:   #00cc33;
    --vs-text-tertiary:    #008f11;
    --vs-text-on-accent:   #000000;

    --vs-border:           #1a3a1a;
    --vs-border-subtle:    #112211;

    --vs-accent:           #00ff41;
    --vs-accent-hover:     #33ff66;
    --vs-accent-subtle:    #002200;

    --vs-success:          #00ff41;
    --vs-success-subtle:   #002200;
    --vs-warning:          #ffcc00;
    --vs-warning-subtle:   #221a00;
    --vs-danger:           #ff0040;
    --vs-danger-subtle:    #220008;
    --vs-info:             #00ccff;
    --vs-info-subtle:      #001a22;

    --vs-sidebar-bg:       #050505;
    --vs-sidebar-text:     #008f11;
    --vs-sidebar-text-active: #00ff41;
    --vs-sidebar-accent:   #00ff41;
}
```

### 5.3 Island Fresh preset

```css
.theme-island {
    --vs-bg-page:          #f0fdfa;
    --vs-bg-surface:       #ffffff;
    --vs-bg-surface-alt:   #f0fdfa;
    --vs-bg-surface-hover: #ccfbf1;
    --vs-bg-inset:         #e6fffa;

    --vs-text-primary:     #134e4a;
    --vs-text-secondary:   #2d6a6a;
    --vs-text-tertiary:    #5f9ea0;
    --vs-text-on-accent:   #ffffff;

    --vs-border:           #99f6e4;
    --vs-border-subtle:    #ccfbf1;

    --vs-accent:           #0d9488;
    --vs-accent-hover:     #0f766e;
    --vs-accent-subtle:    #ccfbf1;

    --vs-success:          #059669;
    --vs-success-subtle:   #ecfdf5;
    --vs-warning:          #ea580c;
    --vs-warning-subtle:   #fff7ed;
    --vs-danger:           #e11d48;
    --vs-danger-subtle:    #fff1f2;
    --vs-info:             #0284c7;
    --vs-info-subtle:      #f0f9ff;

    --vs-sidebar-bg:       #0f766e;
    --vs-sidebar-text:     #99f6e4;
    --vs-sidebar-text-active: #ffffff;
    --vs-sidebar-accent:   #f0abfc;
}
```

### 5.4 Cellar Dark preset

```css
.theme-cellar {
    --vs-bg-page:          #18181b;
    --vs-bg-surface:       #27272a;
    --vs-bg-surface-alt:   #303036;
    --vs-bg-surface-hover: #3f3f46;
    --vs-bg-inset:         #1e1e22;

    --vs-text-primary:     #fafaf9;
    --vs-text-secondary:   #a8a29e;
    --vs-text-tertiary:    #78716c;
    --vs-text-on-accent:   #1c1917;

    --vs-border:           #3f3f46;
    --vs-border-subtle:    #2e2e33;

    --vs-accent:           #f59e0b;
    --vs-accent-hover:     #d97706;
    --vs-accent-subtle:    #422006;

    --vs-success:          #34d399;
    --vs-success-subtle:   #022c22;
    --vs-warning:          #fbbf24;
    --vs-warning-subtle:   #422006;
    --vs-danger:           #f87171;
    --vs-danger-subtle:    #450a0a;
    --vs-info:             #60a5fa;
    --vs-info-subtle:      #172554;

    --vs-sidebar-bg:       #0f0f11;
    --vs-sidebar-text:     #78716c;
    --vs-sidebar-text-active: #f59e0b;
    --vs-sidebar-accent:   #f59e0b;
}
```

### 5.5 Harvest Gold preset

```css
.theme-harvest {
    --vs-bg-page:          #fefcf3;
    --vs-bg-surface:       #ffffff;
    --vs-bg-surface-alt:   #fef9ee;
    --vs-bg-surface-hover: #fef3cd;
    --vs-bg-inset:         #fdf6e3;

    --vs-text-primary:     #3c1518;
    --vs-text-secondary:   #6b3a3e;
    --vs-text-tertiary:    #a07070;
    --vs-text-on-accent:   #ffffff;

    --vs-border:           #e8d5b7;
    --vs-border-subtle:    #f0e6d2;

    --vs-accent:           #7f1d1d;
    --vs-accent-hover:     #991b1b;
    --vs-accent-subtle:    #fef2f2;

    --vs-success:          #166534;
    --vs-success-subtle:   #f0fdf4;
    --vs-warning:          #b45309;
    --vs-warning-subtle:   #fffbeb;
    --vs-danger:           #b91c1c;
    --vs-danger-subtle:    #fef2f2;
    --vs-info:             #1e40af;
    --vs-info-subtle:      #eff6ff;

    --vs-sidebar-bg:       #3c1518;
    --vs-sidebar-text:     #d4a574;
    --vs-sidebar-text-active: #fef3cd;
    --vs-sidebar-accent:   #c09553;
}
```

---

## 6. Typography

### 6.1 Type scale

| Role | Size | Weight | Token |
|---|---|---|---|
| Page heading | 20px (`text-xl`) | 600 (semibold) | Handled by Filament |
| Section title | 16px (`text-base`) | 600 | `vs-section-title` |
| KPI value | 24px (`text-2xl`) | 600 | `vs-kpi-value` |
| KPI label | 14px (`text-sm`) | 500 | `vs-kpi-label` |
| Body text | 14px (`text-sm`) | 400 | Default |
| Table header | 12px (`text-xs`) | 500, uppercase | `vs-th` |
| Table cell | 14px (`text-sm`) | 400 | `vs-td` |
| Caption / meta | 12px (`text-xs`) | 400 | `vs-text-tertiary` |
| Badge | 12px (`text-xs`) | 500 | `vs-badge` |

### 6.2 Numeric formatting

- All numbers in tables and KPI cards use `font-variant-numeric: tabular-nums`
- Currency: `$1,240` (no decimals in KPIs, 2 decimals in tables if needed)
- Volume: `2,847 gal` — always include the unit
- Percentages: `12.4%` — one decimal in tables, whole number in KPI cards
- Dates: `Mar 7, 2025` (short month, no leading zero on day)
- Timestamps: `Mar 7, 2025 2:15 PM` (12-hour, no seconds)

### 6.3 Font

Instrument Sans (already configured). It works well for this use case — humanist, slightly warm, good tabular figure support. No change needed.

---

## 7. Spacing

### 7.1 Base unit

8px grid. All spacing derives from multiples of 8:

| Token | Value | Usage |
|---|---|---|
| `--vs-space-1` | 4px | Inline gaps, tight icon spacing |
| `--vs-space-2` | 8px | Inner padding of badges/chips |
| `--vs-space-3` | 12px | Table cell padding |
| `--vs-space-4` | 16px | Card inner padding, gap between related items |
| `--vs-space-5` | 20px | — |
| `--vs-space-6` | 24px | Section padding, gap between sections |
| `--vs-space-8` | 32px | Page-level vertical rhythm |

### 7.2 Section gaps

- Between KPI bar and first section: `24px` (`space-6`)
- Between sibling sections: `24px` (`space-6`)
- Between section header and body content: `16px` (`space-4`)
- Between an alert banner and the next section: `24px` (`space-6`)

---

## 8. Responsive behavior

### 8.1 KPI cards

| Screen | Behavior |
|---|---|
| < 640px | 2 columns, cards stack |
| 640–1023px | 3 columns |
| 1024–1279px | auto-fit, usually 4 |
| 1280px+ | auto-fit, max 5, capped width |

### 8.2 Split panels (lot traceability)

| Screen | Behavior |
|---|---|
| < 768px | Full stack — selector, then backward, then forward, then timeline |
| 768px+ | 2-col for backward/forward traces; timeline full-width below |

### 8.3 Summary stat grids (TTB sections A & B)

| Screen | Behavior |
|---|---|
| < 640px | 2 columns |
| 640–1023px | 3 columns |
| 1024px+ | 4 columns, capped at `max-width: 960px` |

### 8.4 Tables

- Always wrapped in `overflow-x-auto`
- On < 768px: horizontal scroll enabled, minimum column widths preserved
- Never let tables collapse into unreadable single-column cards on mobile — horizontal scroll is better than losing data structure

---

## 9. Page-by-page application

### 9.1 Bulk Wine Inventory

**Current issues:**
- KPI cards use `<x-filament::section>` which adds excessive padding for stat chips
- `grid-cols-1 md:grid-cols-5` doesn't adapt between tablet and ultrawide

**Target layout:** Archetype A. Replace section-wrapped KPIs with `vs-kpi-bar` + `vs-kpi-card`. Table section stays as-is (Filament table builder).

### 9.2 TTB Report Review

**Current issues:**
- Mixes raw `bg-white` divs with Filament sections
- 8 summary stats in a `grid-cols-2 md:grid-cols-4` — good column count but cards are too wide on ultrawide
- Hardcoded status colors (`text-yellow-600`, `text-blue-600`, `text-green-600`) instead of semantic tokens
- Custom table doesn't match Filament table visual style

**Target layout:** Archetype C. Page header bar with status badge. Flag banner using `vs-alert`. Summary grids capped at `max-width: 960px`. Custom tables styled with `vs-table` classes.

### 9.3 Cost Reports

**Current issues:**
- Same KPI card problem as Bulk Wine Inventory
- Two custom tables (vintage summary + margin report) each in their own `<x-filament::section>` — correct approach, but table styling differs from Filament's native tables

**Target layout:** Archetype A. KPI bar at top, then Filament table, then vintage summary and margin tables in `vs-section` cards with `vs-table` styling for visual consistency.

### 9.4 Lot Traceability

**Current issues:**
- Selector panel, lot info panel, backward/forward traces, and timeline are all `bg-white dark:bg-gray-800` raw divs — no consistent card treatment
- Timeline vertical line is `w-0.5 bg-gray-200` — needs to use token
- Two-column backward/forward split goes from 1-col directly to 2-col at `md` — works fine

**Target layout:** Archetype B. Selector + lot info as `vs-section`. Forward/backward as twin `vs-section` cards. Timeline as `vs-timeline` component.

### 9.5 Physical Count

**Current issues:**
- Detail view KPI cards again use `<x-filament::section>` — same balloon problem
- Mixes summary card approach with Filament table

**Target layout:** Archetype A. KPI bar for count session metrics, Filament table for line items.

---

## 10. Interaction patterns

### 10.1 Drill-down

When clicking a table row reveals detail (TTB source events, physical count lines):

- Detail panel slides in below the table or beside it (not a modal — modals break scanning context)
- Panel has a clear close button (top-right X) and a visible border or elevated shadow to distinguish from surrounding content
- Previous row stays highlighted in the table to maintain orientation

### 10.2 Loading states

- Tables: Filament handles this natively (skeleton/spinner)
- Custom panels: Use a subtle pulse animation on the card background, never a full-page overlay
- Livewire transitions: Wire:loading on action buttons disables and shows a spinner icon

### 10.3 Empty states

Every section that could be empty needs a designed empty state:

```html
<div class="vs-empty-state">
    <x-heroicon-o-beaker class="vs-empty-state-icon" />
    <h3 class="vs-empty-state-title">No lots yet</h3>
    <p class="vs-empty-state-description">Create your first lot to start tracking production.</p>
    <a href="{{ route('filament.portal.resources.lots.create') }}" class="vs-btn vs-btn--primary vs-btn--sm">
        Create Lot
    </a>
</div>
```

---

## 11. Theme implementation roadmap

### Phase 1: Token foundation
1. Define all CSS custom properties in `app.css` under `.theme-vineyard` (default)
2. Create utility classes (`.vs-kpi-bar`, `.vs-kpi-card`, `.vs-section`, `.vs-table`, `.vs-badge`, `.vs-alert`, `.vs-timeline`, `.vs-empty-state`) that consume tokens
3. Add `theme-vineyard` class to the `<html>` tag via Filament render hook

### Phase 2: Template refactor
4. Refactor all 5 custom blade templates to use the new utility classes
5. Remove all hardcoded Tailwind color classes from blade templates
6. Verify Filament's built-in components (tables, forms, nav) honor the token overrides via `.fi-*` class targeting

### Phase 3: Theme switcher
7. Add `theme` column to users table (string, default: `vineyard`)
8. Create Settings > Appearance page with theme preview cards
9. Apply theme class server-side in a Filament render hook reading `auth()->user()->theme`
10. Add all 5 presets to `app.css`

### Phase 4: Polish
11. Test all 5 themes across all pages
12. Ensure Filament modals, notifications, and dropdowns inherit theme tokens
13. Test mobile layouts across all breakpoints
14. Add smooth transition (`transition: background-color 0.2s, color 0.2s`) on theme switch

---

## 12. File map

| File | Purpose |
|---|---|
| `resources/css/app.css` | Token definitions, theme presets, utility classes |
| `app/Providers/Filament/AdminPanelProvider.php` | Panel config, render hook for theme class |
| `resources/views/filament/pages/*.blade.php` | Custom page templates (refactored) |
| `resources/views/filament/widgets/*.blade.php` | Widget templates |
| `app/Filament/Pages/Settings/Appearance.php` | Theme picker page (new) |
| `database/migrations/*_add_theme_to_users.php` | Theme column migration (new) |
| `docs/guides/design-guide.md` | This document |
