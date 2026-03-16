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
| `label-compliance-engine.md` | **Absorb into Phase 3 (TTB Compliance)** | Real-time blend-to-label validation (75% varietal, 85% AVA, 95% vintage rules) is a natural extension of Task 06. The production data and blend composition already exist from Task 02. Adding a `LabelComplianceService` that runs validation against blend components is a modest addition to Phase 3 scope and yields a genuine differentiator. Could also be a sub-task within Task 02 (blending) that runs validation at blend finalization time. |
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
