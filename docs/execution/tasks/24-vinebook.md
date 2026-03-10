# VineBook Directory

## Phase
Phase 8

## Dependencies
- `01-foundation.md` — API (VineBook Astro islands call the API)
- `11-ecommerce.md` — Store API (shop widget on subscriber pages)
- `12-reservations-events.md` — Availability API (booking widget)
- `10-wine-club.md` — Club API (signup widget)

## Goal
Build VineBook — a public winery directory powered by Astro (static site generator). Seeds with ~11,000 US bonded wineries from TTB public data, enriched with Yelp and Google Places data. Subscriber wineries get enhanced pages with live widgets (shop, book, join club). Non-subscriber pages are static stubs. The directory doubles as an SEO-powered acquisition funnel — winery owners find their own listing, claim it, and get upsold to the suite.

## Sub-Tasks

### 1. TTB data seed and winery stub pages
**Description:** Import all US bonded wineries from TTB public permit database. Create stub pages.
**Files to create:**
- `vinebook/` — Astro project scaffolding
- `api/app/Jobs/SeedTTBWineryDataJob.php`
- `vinebook/src/pages/wineries/[slug].astro`
**Acceptance criteria:**
- ~11,000 winery stub pages generated
- Each page: winery name, location, permit number
- Static HTML, CDN cached, fast TTFB
- Sitemap auto-generated

### 2. External data enrichment (Yelp + Google Places)
**Description:** Enrich winery pages with photos, ratings, hours, contact info from Yelp and Google Places APIs.
**Files to create:**
- `api/app/Jobs/EnrichWineryDataJob.php`
- `api/app/Services/YelpFusionService.php`
- `api/app/Services/GooglePlacesService.php`
**Acceptance criteria:**
- Rolling enrichment: ~370 wineries/day to stay within API limits
- Cached in central database, refreshed every 30 days
- Data: hours, rating, review count, photos, website URL, address
- Stale data served until refresh — never blocks renders

### 3. Subscriber enhanced pages with Astro islands
**Description:** Subscriber wineries get live interactive widgets on their VineBook page.
**Files to create:**
- `vinebook/src/components/ShopWidget.astro`
- `vinebook/src/components/BookingWidget.astro`
- `vinebook/src/components/ClubSignupWidget.astro`
- `vinebook/src/components/MemberPortalWidget.astro`
**Acceptance criteria:**
- Static shell (winery info, photos) rendered at build time
- Astro islands hydrate client-side, call Laravel API for live data
- Widgets: shop, book, join club, member portal
- Subscriber vs non-subscriber template switching

### 4. Regional and variety landing pages
**Description:** SEO-targeted pages for "wineries in [region]" and "[variety] wineries" queries.
**Files to create:**
- `vinebook/src/pages/regions/[region].astro`
- `vinebook/src/pages/varieties/[variety].astro`
**Acceptance criteria:**
- Regional pages for all major AVAs (Paso Robles, Napa, Sonoma, Willamette, etc.)
- Variety pages for major varietals
- Each page lists relevant wineries with links
- SEO: structured data (JSON-LD), meta descriptions, proper headings

### 5. Winery claim flow
**Description:** Non-subscriber winery owner finds their stub page and claims it. Verify ownership, enhance profile, upsell to suite.
**Files to create:**
- `api/app/Http/Controllers/VineBookClaimController.php`
**Acceptance criteria:**
- "Claim this winery" button on stub pages
- Verification: TTB permit number match or business email domain match
- Claimed winery gets enhanced free profile (upload photos, update hours, add description)
- Upsell CTA: "Get your online store, POS, and production tracking with VineSuite"

### 6. Astro build and deployment
**Description:** Configure Astro for production build and Cloudflare Pages deployment.
**Files to create:**
- `vinebook/astro.config.mjs`
- `.github/workflows/deploy-vinebook.yml`
**Acceptance criteria:**
- Nightly rebuild to catch enrichment data updates
- Deployed to Cloudflare Pages (free tier)
- Build time < 10 minutes for ~11,000 pages
- Core Web Vitals: green scores on all metrics

## Testing Notes
- **Build test:** Full site builds without errors. All pages render correctly.
- **Integration test:** Subscriber page loads live widget data from API.
- **SEO test:** Structured data validates. Sitemap includes all pages. robots.txt allows crawl.
