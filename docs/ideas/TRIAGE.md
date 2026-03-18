# Ideas Triage

At the start of each phase, before any code is written, review every idea in this directory and assign a disposition. This prevents good ideas from dying in a pile and bad ideas from sneaking into scope unchecked.

---

## Process

1. Open `docs/ideas/README.md` — scan the full index
2. For each idea, assign one of three dispositions:
   - **Absorb** — becomes a sub-task or modifies an existing sub-task in this phase
   - **Defer** — not this phase, with a target phase and reason
   - **Reject** — won't build, with a reason (so it doesn't get re-proposed)
3. Record the triage in this file under the relevant phase heading
4. If absorbing: create or update the sub-task in `docs/execution/tasks/` and note the source idea doc
5. If deferring: update the idea doc's urgency in `README.md` if it changed

Ideas can also arrive mid-phase. They get written to `docs/ideas/` as usual but don't enter scope until the next triage checkpoint. The only exception is if an idea reveals a design constraint that affects work already in progress — in that case, flag it immediately rather than waiting.

---

## Triage Record

### Phase 2 — Production Core

| Idea | Disposition | Rationale |
|------|-------------|-----------|
| `pricing-and-plan-tiers.md` | **Absorb** | PlanFeatureService and feature gating middleware are Phase 2 deliverables. Tier structure, volume limits, and upgrade/downgrade UX from this doc directly shape the implementation. |
| `progressive-onboarding.md` | **Absorb (as constraint)** | Not its own sub-task, but a design constraint on Filament resource visibility. Phase 2 builds the portal UI — this doc dictates that navigation should be tier-aware and not overwhelm new users with modules they haven't activated. |
| `gradual-migration-path.md` | **Absorb (as constraint)** | Influences how Phase 2 modules expose inbound data endpoints. Not a sub-task, but any API design should accommodate CSV import and webhook ingestion for side-by-side operation with existing tools. |
| `data-portability.md` | **Defer → Phase 4** | Export functionality requires the data models (lots, compliance, orders) to exist first. Can't export what isn't built yet. Revisit when production and compliance modules are complete. |
| `harvest-season-resilience.md` | **Defer → Phase 5** | Load testing and offline-first stress testing are pre-launch concerns. Premature to address before the cellar app and POS exist. |
| `customer-support-escalation.md` | **Defer → Post-launch** | Support infrastructure is irrelevant until there are customers. No architecture impact on current work. |
| `grape-marketplace.md` | **Defer → Phase 4+** | Requires lot management (Phase 3) and plan gating (Phase 2) to exist. Central-schema data model should be sketched during Phase 2 to avoid migration conflicts, but no code is written until Phase 4 at the earliest. |

### Phase 3 — Lab Analysis & Fermentation Tracking

| Idea | Disposition | Rationale |
|------|-------------|-----------|
| `gradual-migration-path.md` | **Absorb (as constraint)** | Sub-Task 3 (External Lab CSV Import) must support backdated `performed_at` timestamps for historical lab data import. Wineries running VineSuite alongside InnoVint will want to bring over past lab analyses — the importer can't assume everything happened today. |
| `data-portability.md` | **Absorb (as constraint)** | Not a sub-task. Design constraint on event payloads: `lab_analysis_entered`, `fermentation_data_entered`, `sensory_note_added` payloads should be self-contained (include lot name, variety alongside foreign keys) so they're readable without joins. Avoids a retrofit when export is built in Phase 4. |
| `pricing-and-plan-tiers.md` | **Defer** | Lab/fermentation tracking is documented as a Basic-tier feature. No gating implementation needed in Phase 3 — `PlanFeatureService` is a Phase 2 deliverable that hasn't been built yet. Phase 3 just builds the features; gating wraps them later. |
| `progressive-onboarding.md` | **Defer** | Carried forward as a standing constraint. Phase 3 Filament resources go under sensible navigation groups (Lab, Fermentation) but no new onboarding logic. |
| `harvest-season-resilience.md` | **Defer → Phase 5** | Fermentation tracking will be the heaviest write-volume feature during harvest, but load testing it is premature without the cellar app to generate real concurrent traffic. |
| `customer-support-escalation.md` | **Defer → Post-launch** | No overlap with Phase 3. |
| `grape-marketplace.md` | **Defer → Phase 4+** | No overlap. Lot management prerequisite is now met but compliance (Phase 6) and cross-tenant infrastructure are not. |

---

### Research-Driven Ideas — Initial Triage (2026-03-15)

These ideas emerged from the winery SaaS market research gap analysis. They haven't been triaged against a specific upcoming phase yet — this is the initial disposition to determine *when* each idea should enter the build pipeline. See `research-gap-analysis.md` for the full context behind each item.

| Idea | Disposition | Rationale |
|------|-------------|-----------|
| `label-compliance-engine.md` | **✅ Absorbed → Delivered in Phase 6, Sub-Task 10** | Originally triaged for Phase 3 (Task 06). Implemented during Phase 6 as `LabelComplianceService` with 4 TTB labeling rules, `LabelProfile` and `LabelComplianceCheck` models, Lot `source_ava` field. 18 tests. |
| `unified-tax-engine.md` | **Absorb (as constraint) → Phase 6** | Not its own task, but a cross-cutting service that needs to exist when the first sales channel goes live (POS, Phase 6). Federal excise with CBMA credit tracking, state excise by destination, and sales tax API integration (TaxJar/Avalara) should be a shared service in Task 09 that subsequent sales modules (11, 10, 22) consume. Design during Phase 3 when TTB tax classes are being built, implement during Phase 6. |
| `hobbyist-pipeline.md` | **Defer → Phase 7 or standalone sprint** | High strategic value as a growth engine (250k+ home winemakers), but fundamentally different onboarding experience from the commercial winery flow. Doesn't affect the architecture — the same schema-per-tenant model works, just with a different feature surface. Requires: simplified batch logging UI, built-in calculators, 200-gallon cap, community features. Could be built as a separate Filament panel or even a standalone lightweight app sharing the same API. **Design decision needed:** is this a separate product surface or a mode within the existing portal? |
| `water-sgma-tracking.md` | **Defer → Phase 7 (extend Task 17)** | Natural extension of Vineyard Management. Add water usage logging per block, well metering records, and SGMA compliance reporting as sub-tasks within Task 17. Beachhead-critical for Paso Robles but has no architectural impact on earlier phases. SIP Certified documentation can share the certification compliance framework from Task 06 sub-task 9. |
| `grower-tools.md` | **Defer → Phase 8+** | Requires a new tenant type (grower vs. winery) and cross-tenant data flow (grower's harvest data → winery's lot creation). This is architecturally non-trivial and should not be attempted until the core winery product is revenue-generating. The cross-tenant event flow (grower pushes harvest event → winery receives lot creation event) needs careful design around the schema-per-tenant isolation model. |
| `smart-allocation.md` | **Defer → Phase 8** | Requires inventory (Task 04), wine club (Task 10), eCommerce (Task 11), and wholesale (Task 22) to all exist. Phase 7 delivers the manual allocation worksheet as part of inventory/club management. Phase 8 adds the AI-powered optimization engine as an extension of Task 20 (AI Features). |
| `custom-crush-ap-portal.md` | **Defer → Phase 8+ (separate task)** | Task 21 sub-task 3 covers casual custom crush (tag lots with a client name). The full AP portal — where each holder is a real tenant with their own TTB bond, operational access, and compliance — is a separate, larger feature. Requires cross-tenant resource scheduling and facility billing. Keep sub-task 3 for the simple case; build the full AP portal when a real facility operator commits to piloting. Sketch the central-schema `FacilityRelationship` and `SharedResource` models early to reserve design space. One facility operator signing up = 10-40 new tenant signups via network effects. |
| `research-gap-analysis.md` | **Reference only** | Master comparison document. Not a feature — it's the analysis that generated the other idea docs. Keep as context for future triage sessions. |

#### Notes on Automated Churn Response Workflows

Not given its own idea doc. The research identifies that churn *scoring* (Task 20, sub-task 5) without automated *response* workflows misses the real value. Recommendation: when Task 13 (CRM/Email) and Task 18 (Notifications/Automation) are being built in Phase 7, add an explicit sub-task connecting churn risk scores to automated retention campaigns. The automation rules engine in Task 18 (tagged [PRO]) is the right place — add a trigger type: "churn risk score exceeds threshold → execute retention workflow."

#### Notes on Vineyard-Side AI / Harvest Prediction

Not given its own idea doc. The research highlights satellite imagery and digital twin technology, but this is expensive, complex, and low-priority for MVP. A more realistic near-term feature: weather API integration (frost alerts, heat spike warnings) and historical yield trending from VineSuite's own harvest data. Recommendation: add as a sub-task in Task 20 (AI Features) during Phase 8, or as an extension to Task 17 (Vineyard) if the data is rich enough by then.

---

### Phase 5 → Phase 6 Triage — Cost Accounting → TTB Compliance

| Idea | Disposition | Rationale |
|------|-------------|-----------|
| `label-compliance-engine.md` | **✅ Absorbed — Delivered as Sub-Task 10** | Implemented `LabelComplianceService` with all four rules (varietal 75%, AVA 85%, vintage 95%, conjunctive labeling). Models: `LabelProfile`, `LabelComplianceCheck`. Lot model extended with `source_ava`. 18 tests. |
| `unified-tax-engine.md` | **Absorb (as constraint)** | Phase 6 builds TTB wine type classification and DTC rules. Design WineTypeClassifier output to be consumable by a future tax service. Don't build the tax engine itself — that's Phase 9 (POS). |
| `data-portability.md` | **Defer → Phase 7+** | Export needs more modules. Cost data (Phase 5) and compliance data (Phase 6) both enrich exports. Revisit after Phase 6. |
| `pricing-and-plan-tiers.md` | **Defer** | Standing carry-forward. TTB compliance is Pro/Enterprise but plan gating not built. |
| `progressive-onboarding.md` | **Defer** | Standing carry-forward. Phase 6 resources go under "Compliance" nav group. |
| `gradual-migration-path.md` | **Defer** | No Phase 6 overlap. TTB reports generated from own event log. |
| `harvest-season-resilience.md` | **Defer → Phase 7+** | TTB report gen is monthly batch, not high-concurrency. |
| `customer-support-escalation.md` | **Defer → Post-launch** | No overlap. |
| `grape-marketplace.md` | **Defer → Phase 8+** | Prerequisites met but cross-tenant infra missing. |
| `hobbyist-pipeline.md` | **Defer → Phase 7+** | Home winemakers exempt from TTB (<200 gal/year). |
| `water-sgma-tracking.md` | **Defer → Phase 7 (Task 17)** | No overlap. |
| `grower-tools.md` | **Defer → Phase 8+** | Needs cross-tenant architecture. |
| `smart-allocation.md` | **Defer → Phase 8** | Needs inventory + sales channels. |
| `custom-crush-ap-portal.md` | **Defer → Phase 8+** | Phase 6 License model should accommodate multiple permit holders per facility for future AP portal. |
| `research-gap-analysis.md` | **Reference only** | No change. |

---

### Phase 6 → Phase 7 Triage — TTB Compliance → KMP Shared Core

Phase 7 (Task 07) is pure mobile infrastructure: Kotlin Multiplatform project scaffolding, SQLDelight local database, offline event outbox, Ktor API client, sync engine, and conflict resolution. No business features are being added — this is the shared foundation that the Cellar App (Task 08) and POS App (Task 09) will stand on.

**Result: No ideas absorbed.** Every idea in the backlog is either a business feature, a sales channel, or an operational tool. None of them intersect with the KMP shared core layer.

| Idea | Disposition | Rationale |
|------|-------------|-----------|
| `label-compliance-engine.md` | **✅ Delivered** | Completed in Phase 6, Sub-Task 10. No further action. |
| `unified-tax-engine.md` | **Defer → Phase 9 (POS)** | Tax calculation is a sales-channel concern. KMP sync engine carries events — it doesn't compute tax. |
| `data-portability.md` | **Defer → Phase 8+** | Export features need the apps to exist first. |
| `pricing-and-plan-tiers.md` | **Defer** | Standing carry-forward. Mobile apps will respect plan gating via API responses, not client-side logic. |
| `progressive-onboarding.md` | **Defer** | Standing carry-forward. Mobile onboarding UX is Task 08/09 concern, not the shared core. |
| `gradual-migration-path.md` | **Defer** | No KMP overlap. Migration is server-side. |
| `harvest-season-resilience.md` | **Absorb (as constraint)** | The sync engine's offline-first design and conflict resolution directly address harvest-season load. This isn't a new sub-task — it's the *purpose* of Task 07. Acceptance criteria already cover: 50-event offline queue test, retry with backoff, conflict surfacing. |
| `customer-support-escalation.md` | **Defer → Post-launch** | No overlap. |
| `grape-marketplace.md` | **Defer → Phase 8+** | Needs cross-tenant infra. |
| `hobbyist-pipeline.md` | **Defer → Phase 8+** | Separate product surface. KMP core is tenant-agnostic so hobbyist app could reuse it. |
| `water-sgma-tracking.md` | **Defer → Phase 7+ (Task 17)** | Vineyard module, not mobile core. |
| `grower-tools.md` | **Defer → Phase 8+** | Cross-tenant architecture needed. |
| `smart-allocation.md` | **Defer → Phase 8** | Needs sales channels. |
| `custom-crush-ap-portal.md` | **Defer → Phase 8+** | No overlap. |
| `bulk-wine-receipt-events.md` | **Defer → Phase 9+** | Technical debt from Phase 6. Bulk wine receipt events for TTB Part III. No overlap with KMP core — this is a server-side API/service concern. Natural fit when building POS or wholesale channels that handle bonded wine transfers. |
| `ttb-production-event-workflows.md` | **Defer → Phase 9+** | Technical debt from Phase 6. 18 TTB event types with calculator support but no emission path. Tier 1 events (sweetening, fortification, evaporation) should land with the first production-heavy sales channel. No KMP overlap. |
| `research-gap-analysis.md` | **Reference only** | No change. |

---

### Pre-Task 7 Triage — Filament v4 Migration (2026-03-17)

| Idea | Disposition | Rationale |
|------|-------------|-----------|
| `filament-v4-migration.md` | **Absorb → Execute before Task 7** | Filament v4 is stable (v4.5+). No third-party Filament plugins to block the upgrade. Laravel 12 and PHP 8.2 already meet requirements. Automated upgrade tooling handles most of the 24 resources. Key wins: unified Schema (halves form/infolist maintenance), auto tenancy scoping, PHP-based page layouts (reduces custom blade template debt), better theming support for planned theme picker. Estimated 2–3 day effort. Risk is low — custom blade templates use standard Tailwind classes and stable Filament component API. Doing this now prevents accumulating more v3-specific code before mobile apps (Task 7+) build against the admin API. |
