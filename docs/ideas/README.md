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

### Strategic Ideas (2026-03-17)

| File | Summary | Status |
|------|---------|--------|
| `multi-vertical-cellar-suite.md` | Single codebase serving wine, beer, spirits, cider via `business_type` tenant field. CellarSuite rebrand. TAM: ~24,500 US craft beverage producers. | ⏳ Deferred → Phase 9+ (architecture already supports it) |

### Infrastructure Ideas (2026-03-17)

| File | Summary | Status |
|------|---------|--------|
| `iot-sensor-integration.md` | Vine-to-bottle IoT: LoRaWAN sensors → ChirpStack → MQTT → Laravel → TimescaleDB. CO₂ safety as entry point, 3-wave rollout. | ⏳ Deferred → Phase 8 (3 pre-KMP prep items identified) |

### Technical Debt Ideas (2026-03-17)

From Phase 6 known debt items. These are implementation gaps where the consuming layer exists but the emission path does not.

| File | Summary | Status |
|------|---------|--------|
| `bulk-wine-receipt-events.md` | Bulk wine receipt events for TTB Part III (bonded premises, customs, other) | ⏳ Deferred → Phase 9+ (POS/sales channels) |
| `ttb-production-event-workflows.md` | 18 TTB event types with calculator support but no production UI to emit them | ⏳ Deferred → Phase 9+ (Tier 1 events with POS, Tier 2-3 later) |

### Completed Operational Docs (Relocated to `docs/guides/`)

| File | Summary | Status |
|------|---------|--------|
| `../guides/filament-v4-migration.md` | Filament v3 → v4 migration scope and code changes | ✅ **Delivered** (pre-Task 7) |
| `../guides/filament-v4-runbook.md` | CLI runbook and smoke test checklist for v4 migration | ✅ **Delivered** — all smoke tests passed |
| `../guides/filament-v4-plugins-install.md` | Plugin installation commands for v4-compatible packages | ✅ **Delivered** |
