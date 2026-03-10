# Data Portability & Export-First Trust

> Status: Idea — needs a sub-task added to an early phase
> Created: 2026-03-10
> Competitor lesson: vintrace (blocked departures), Commerce7 (limited export)

---

## The Problem
vintrace reportedly threw up hurdles when customers tried to leave. Commerce7's data export capabilities are limited. In a small industry where winemakers talk constantly, locking customers in doesn't build loyalty — it builds resentment and bad word-of-mouth. One angry winemaker at an AVA meeting can undo months of sales effort.

Task 25 (Migration Workbench) handles migration IN. Nothing in the current plan handles migration OUT.

## Proposed Approach
Make data export a first-class feature, not an afterthought.

**Full data export:** One-click export of everything — lots, vessels, events, customers, orders, club members, lab data — in a documented, open format (JSON + CSV). Available to every tier, not gated behind a plan.

**Event log export:** The event log is the source of truth. Exporting it in a portable format means any future system could theoretically replay a winery's entire history. This is a powerful trust signal: "Your data is yours. You can take it anywhere."

**No retention games:** When a winery cancels, their data stays accessible for 90 days for export. After that, the tenant schema is archived (not deleted) for an additional period per legal requirements, then purged. No "pay us to get your data back."

**Export format documentation:** Publish the export schema publicly. If a competitor wants to build an import tool for VineSuite exports, let them. The confidence this gives customers is worth more than any lock-in strategy.

## Architecture Compatibility
The event-sourced architecture makes this almost trivial. The event log is already a complete, ordered record of everything that happened. Exporting it is a query + JSON serialization. Materialized tables (lots, vessels, etc.) can be exported as CSV alongside the event stream. Schema-per-tenant means the export is a clean, isolated dump of one schema.

No architectural changes needed. This is a feature to build, not a redesign.

## When to Address
A basic "export my data" button should exist by Phase 5 (Starter tier launch). Wineries evaluating VineSuite will ask "what happens if I want to leave?" and the answer needs to be concrete, not a promise.

Suggested placement: add as a sub-task to Task 19 (Reporting) or as a standalone feature in Phase 2's Filament resources.

## Open Questions
- Should export include binary assets (label images, barrel photos) or just data?
- What format is most useful to wineries? JSON is complete but not spreadsheet-friendly. CSV loses nested relationships. Offer both?
- Should the export include a README explaining the schema for whoever imports it next?
