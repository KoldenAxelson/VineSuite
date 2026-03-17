# Ideas

Captured ideas, strategic considerations, and lessons from competitor analysis. These are not task files — they're reference material for future planning decisions. See `TRIAGE.md` for formal dispositions per phase.

## Index

### Original Ideas

| File | Summary | Status |
|------|---------|--------|
| `pricing-and-plan-tiers.md` | Freemium + 3 paid tiers, feature gating, upgrade/downgrade UX | 🟢 **Delivered** (Phase 2) — enum + helpers in code. `PlanFeatureService`, annual billing, Community Insights still needed. |
| `progressive-onboarding.md` | Don't overwhelm new users with all 25 modules on day one | 🔵 Absorbed as constraint (Phase 2) |
| `gradual-migration-path.md` | Let wineries adopt one module at a time, don't force rip-and-replace | 🔵 Absorbed as constraint (Phases 2-3) |
| `data-portability.md` | Make export easy — builds trust, counters competitor lock-in reputation | 🔵 Absorbed as constraint (Phase 3). Full export deferred. |
| `customer-support-escalation.md` | Tiered AI support system (FAQ → slim LLM → large LLM → human) | ⏳ Deferred → Post-launch. See Task 18 "Ideas to Evaluate". |
| `harvest-season-resilience.md` | Load test before first harvest, prove offline-first works under pressure | ⏳ Deferred → Phase 5 (pre-launch) |
| `grape-marketplace.md` | Tenant-to-tenant grape trading board with compliance auto-fill | ⏳ Deferred → Phase 4+ |

### Research-Driven Ideas (2026-03-15)

From the winery SaaS market research gap analysis. Start with `research-gap-analysis.md` for the full comparison, then drill into individual docs.

| File | Summary | Status |
|------|---------|--------|
| `research-gap-analysis.md` | Master gap analysis: research recommendations vs. pipeline coverage | 📋 **Reference only** — all items triaged |
| `label-compliance-engine.md` | Real-time blend-to-label validation (75% varietal, 85% AVA, 95% vintage) | 🟢 **Delivered** (Phase 6, Sub-Task 10). `LabelComplianceService`, `LabelProfile`, `LabelComplianceCheck`. 18 tests. |
| `unified-tax-engine.md` | Single service for federal excise (CBMA), state excise, sales tax | 🟡 Triaged → Phase 6 (Task 09). Not yet in spec. |
| `water-sgma-tracking.md` | Per-block water usage, well metering, SGMA compliance reporting | 🟡 Triaged → Phase 7 (Task 17). Not yet in spec. |
| `hobbyist-pipeline.md` | Free tier for 250k+ home winemakers as a conversion engine | ⏳ Deferred → Phase 7 or standalone sprint |
| `smart-allocation.md` | Algorithmic optimization of production across channels | ⏳ Deferred → Phase 8. See Task 20 "Ideas to Evaluate". |
| `grower-tools.md` | Standalone tools for grape growers with cross-tenant data flow | ⏳ Deferred → Phase 8+. See Task 17 "Ideas to Evaluate". |
| `custom-crush-ap-portal.md` | Full AP holder support with cross-tenant facility management | ⏳ Deferred → Phase 8+. See Task 21 "Ideas to Evaluate". |
