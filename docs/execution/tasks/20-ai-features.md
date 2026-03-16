# AI Features

## Phase
Phase 8

## Dependencies
- `01-foundation.md` — event log (AI analyzes event streams), queue system (Horizon)
- `19-reporting.md` — AI features build on reporting data aggregations
- `02-production-core.md` — lot/fermentation data for predictions
- `04-inventory.md` — stock data for demand forecasting
- `10-wine-club.md` — member data for churn scoring
- `05-cost-accounting.md` — COGS data for margin optimization

> **Pre-implementation check:** This spec predates completed phases. Before starting, load `CONVENTIONS.md` and review phase recaps for any dependency phases listed above. Patterns, service boundaries, and data model decisions may affect assumptions in this spec.

## Ideas to Evaluate

> Review these before starting this phase. If they fit, create additional sub-tasks.

- `ideas/smart-allocation.md` — AI-powered inventory allocation across channels with revenue optimization
- See TRIAGE.md note on "Vineyard-Side AI / Harvest Prediction" — weather API integration, historical yield trending

## Goal
Pro-tier AI features powered by Anthropic API (claude-sonnet-4-6). All AI is background jobs on a schedule — never real-time, never blocking core operations. Features: weekly business digest, demand forecasting, harvest timing suggestions, fermentation dry-down prediction, club churn risk scoring, and margin optimization flags. Token costs controlled via scheduled execution, pre-aggregated context prompts (not raw DB dumps), per-tenant weekly budgets, and 7-day result caching.

## Data Models

- **AIDigest** — `id` (UUID), `tenant_id`, `digest_type` (weekly_summary/demand_forecast/harvest_timing/fermentation_prediction/churn_scores/margin_flags), `period_start`, `period_end`, `content` (JSONB — structured AI output, not raw text), `raw_prompt` (TEXT — for debugging/tuning), `raw_response` (TEXT — for debugging), `input_token_count`, `output_token_count`, `model_used`, `generated_at`, `expires_at`, `created_at`

- **AIBudget** — `id`, `tenant_id`, `week_start`, `tokens_used`, `token_limit`, `jobs_run`, `jobs_skipped_budget`, `created_at`, `updated_at`

- **AIPromptTemplate** — `id`, `digest_type`, `system_prompt` (TEXT), `user_prompt_template` (TEXT — Blade-style with variables), `output_schema` (JSON — expected response structure), `version`, `is_active`, `created_at`, `updated_at`

## Sub-Tasks

### 1. AI job infrastructure and token budgeting
**Description:** Build the base class for AI jobs with context building, token budgeting, caching, retry logic, and error handling. This is the foundation all AI features run on.

**Files to create:**
- `api/app/Jobs/AI/BaseAIJob.php` — abstract base: build context, call API, parse response, store digest
- `api/app/Services/AI/AnthropicService.php` — wraps Anthropic API calls with retry, token counting, error handling
- `api/app/Services/AI/ContextBuilder.php` — pre-aggregates stats from reporting data into prompt-ready summaries
- `api/app/Services/AI/TokenBudgetService.php` — enforces per-tenant weekly budget
- `api/app/Models/AIDigest.php`
- `api/app/Models/AIBudget.php`
- `api/app/Models/AIPromptTemplate.php`
- `api/database/migrations/xxxx_create_ai_digests_table.php`
- `api/database/migrations/xxxx_create_ai_budgets_table.php`
- `api/database/migrations/xxxx_create_ai_prompt_templates_table.php`

**Acceptance criteria:**
- Only runs for Pro tier tenants (check tenant plan before executing)
- Per-tenant weekly token budget enforced (configurable, default ~50k tokens/week)
- Token budget exceeded → job skipped silently (no error, no notification, digest just doesn't appear)
- Results cached in ai_digests table for 7 days — re-request within window returns cached
- Prompt templates stored in DB (not hardcoded) — allows tuning without deploy
- Raw prompt and response stored on every digest (for debugging and prompt optimization)
- AI job failures are silent to the user — never blocks core functionality
- Retry: 2 attempts with 30-second backoff. After 2 failures, skip silently.
- Context builder outputs structured stats, NOT raw database rows

**Gotchas:** The Anthropic API can be slow (5-30 seconds per call). These are background jobs — that's fine, but set a 60-second timeout. Pre-aggregating context is critical for cost control — a winery with 10,000 orders should produce a context of ~2,000 tokens, not 100,000. The ContextBuilder is where most of the engineering effort goes. Store the raw prompt so you can replay it later when tuning.

### 2. Weekly business digest
**Description:** Natural language summary of the week's business — sales trends, inventory movements, club health, anomalies, and actionable recommendations.

**Files to create:**
- `api/app/Jobs/AI/GenerateWeeklyDigestJob.php`
- `api/app/Services/AI/Contexts/WeeklyDigestContext.php` — gathers: sales by channel (this week vs. last week vs. same week last year), inventory changes, club metrics, notable events
- `api/database/seeders/AIPromptTemplateSeeder.php` — seed the weekly digest prompt

**Acceptance criteria:**
- Scheduled: Sunday night, ready Monday morning (configurable per tenant timezone)
- Context includes: total revenue (by channel), top 5 SKUs, inventory alerts, club metrics (active members, churn this week, upcoming run), any notable production events
- Output structure (JSON): `summary` (2-3 paragraph overview), `highlights` (array of positive items), `concerns` (array of issues needing attention), `recommendations` (array of action items)
- Push notification to owner when digest is ready: "Your weekly digest is ready"
- Viewable in portal dashboard widget

**Gotchas:** The weekly digest is the "flagship" AI feature — it's what sells Pro tier. Quality matters more than quantity. The prompt should instruct the model to be specific and actionable ("Your Pinot Noir is selling 40% faster than last quarter — consider bottling the 2024 earlier") rather than generic ("Sales are up"). Comparison data (vs. prior period) is essential context.

### 3. Demand forecasting
**Description:** Per-SKU sell-through projections for the next 90 days based on sales velocity, seasonality, and known events (club runs, holidays).

**Files to create:**
- `api/app/Jobs/AI/DemandForecastJob.php`
- `api/app/Services/AI/Contexts/DemandForecastContext.php`

**Acceptance criteria:**
- Monthly run (1st of each month)
- Context includes: per-SKU monthly sales for last 12 months, current stock levels, upcoming club run dates, seasonal patterns
- Output structure (JSON per SKU): `projected_sales_90d`, `stock_out_risk` (low/medium/high), `reorder_suggestion`, `confidence` (0-1)
- High stock-out risk SKUs flagged in inventory dashboard

**Gotchas:** Demand forecasting with limited data (new wineries < 1 year) should caveat confidence levels. The AI should use seasonal patterns from industry data when the winery's own history is too short. Don't forecast SKUs with fewer than 10 total sales — not enough signal.

### 4. Fermentation dry-down prediction
**Description:** Given current fermentation curve data, predict days to dryness for active ferments.

**Files to create:**
- `api/app/Jobs/AI/FermentationPredictionJob.php`
- `api/app/Services/AI/Contexts/FermentationContext.php`

**Acceptance criteria:**
- Daily run during active ferments for Pro tenants (skip if no active ferments)
- Context: current fermentation curve (date, Brix readings), variety, yeast strain, temperature profile
- Output per active ferment: `predicted_days_to_dry`, `predicted_final_brix`, `anomaly_flag` (stuck fermentation detected), `confidence`
- Displayed on fermentation detail page alongside the chart

**Gotchas:** Fermentation prediction is more math than AI — consider whether a simple regression model would be more appropriate (and cheaper) than an LLM call. If the data fits a standard fermentation curve well, skip the API call and use the regression. Reserve the AI call for anomaly detection (stuck ferments, unusual curves).

### 5. Club churn risk scoring
**Description:** Score each active club member's churn probability based on engagement and behavior patterns.

**Files to create:**
- `api/app/Jobs/AI/ChurnRiskScoringJob.php`
- `api/app/Services/AI/Contexts/ChurnContext.php`

**Acceptance criteria:**
- Weekly run
- Context per member: tenure, skip frequency, purchase recency outside of club, visit frequency, email engagement (opens/clicks), payment failure history
- Output per member: `churn_risk` (0-1), `risk_level` (low/medium/high), `contributing_factors` (array), `suggested_action`
- High-risk members flagged in CRM with risk badge
- Sortable/filterable in member list by risk level

**Gotchas:** Churn scoring with fewer than 100 active members produces unreliable results — add a minimum member threshold. The AI should explicitly state confidence levels. Don't alarm the winery about "high churn risk" when the model doesn't have enough data to know.

### 6. Margin optimization flags
**Description:** Identify SKUs where the selling price is too close to COGS and suggest price review.

**Files to create:**
- `api/app/Jobs/AI/MarginOptimizationJob.php`
- `api/app/Services/AI/Contexts/MarginContext.php`

**Acceptance criteria:**
- Monthly run
- Context: per-SKU selling price (average across channels), COGS, margin %, sales volume, competitive context (if available)
- Flag SKUs where margin < 20% (configurable threshold)
- Output per flagged SKU: `current_margin`, `suggested_price_range`, `rationale`, `revenue_impact_estimate`
- Displayed in financial reports section

**Gotchas:** Price suggestions are sensitive — frame as "review suggested" not "change price to X." Some low-margin SKUs are intentionally loss leaders (entry-level wines that drive tasting room traffic). Let the winery dismiss flags with a "reviewed, keep current price" action.

### 7. AI prompt template management
**Description:** Admin UI for viewing and tuning AI prompt templates without code deploys.

**Files to create:**
- `api/app/Filament/Resources/AIPromptTemplateResource.php`

**Acceptance criteria:**
- View current prompts for each digest type
- Edit system prompt and user prompt template
- Version tracking (keep history of prompt changes)
- Test run: generate digest for a specific tenant using the draft prompt (without overwriting the cached result)
- Revert to previous version if new prompt produces worse results

## API Endpoints

| Method | Path | Description | Auth Scope |
|--------|------|-------------|------------|
| GET | `/api/v1/ai/digests` | List available digests | owner+ (Pro) |
| GET | `/api/v1/ai/digests/{type}` | Get latest digest of type | owner+ (Pro) |
| GET | `/api/v1/ai/digests/{type}/history` | Historical digests | owner+ (Pro) |
| GET | `/api/v1/ai/churn-scores` | Get member churn scores | owner+ (Pro) |
| GET | `/api/v1/ai/demand-forecast` | Get demand forecast | owner+ (Pro) |
| GET | `/api/v1/ai/budget` | View token usage | owner+ (Pro) |

## Events

| Event Name | Payload Fields | Materialized State Updated |
|------------|---------------|---------------------------|
| `ai_digest_generated` | tenant_id, digest_type, token_count | ai_digests |
| `ai_budget_exceeded` | tenant_id, tokens_used, token_limit | ai_budgets |
| `ai_job_failed` | tenant_id, digest_type, error | (none — silent failure) |

## Testing Notes
- **Unit tests:** ContextBuilder produces correct aggregated stats from known data. TokenBudgetService enforces limits correctly. Digest caching (returns cached within 7 days, regenerates after expiry).
- **Integration tests:** End-to-end with mock Anthropic API: build context → call API → parse response → store digest → retrieve via API endpoint. Budget enforcement: run jobs until budget exceeded → verify subsequent jobs skipped.
- **Critical:** AI must NEVER block core operations. Simulate Anthropic API timeout, 500 error, rate limit — verify the winery software works identically. Token budget must be per-tenant-per-week, not global. Verify Pro tier gate (non-Pro tenant gets 403 on AI endpoints).
