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
