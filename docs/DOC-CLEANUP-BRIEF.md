# Documentation Restructure Brief

> **For:** A fresh agent session
> **Goal:** Restructure `/docs` so agents load the minimum context needed per task, not everything upfront
> **Owner input:** Konrad (solo founder, 15+ years dev experience, prefers .md files)

---

## The Problem

The current `/docs` folder is ~16,000 lines of markdown. When an agent starts a task, it has no way to know what's relevant without reading large files upfront. The README alone is 315 lines and mixes business context (pricing tiers, competitive analysis, glossary) with technical architecture pointers. `architecture.md` is 881 lines. Most of that context is wasted tokens on any given task.

The codebase is a Laravel 12 + PostgreSQL + KMP winery SaaS platform (multi-tenant, schema-per-tenant). We've completed Phases 1-4 (Foundation, Production Core, Lab & Fermentation, Inventory). Phase 5 (Cost Accounting & COGS) is next.

---

## The Desired Pattern: Lazy-Loading Docs

Instead of front-loading docs into context, agents should:

1. **Read a lightweight index first** — a compact README that acts as a routing table
2. **Pull specific docs only when relevant** — "working on lab imports? read `references/lab-import-guide.md`"
3. **Never load business/marketing context for coding tasks** — pricing, competitive analysis, glossary are not needed when writing a migration

### The README as a Routing Table

The new `docs/README.md` should be **short** (under 80 lines ideally). It should contain:

- 2-3 sentence project summary (what this is, tech stack, current phase)
- A **doc index** — one line per doc with: filename, what it covers, when to load it
- Rules of engagement (e.g., "got an idea? append to `ideas/`", "finished a refactor? delete its entry from `refactors/`")
- Nothing else. No pricing tables, no competitive analysis, no glossary, no revenue models.

The index entries should look something like:
```
- `references/event-log.md` — Event log patterns and EventLogger usage. Load when writing any service that creates events.
- `references/multi-tenancy.md` — Schema-per-tenant patterns. Load when touching tenant isolation, migrations, or central vs tenant DB.
- `guides/testing-and-logging.md` — Test conventions, groups, logging patterns. Load when writing or debugging tests.
```

### Where Business Context Goes

The current README has pricing tiers, revenue models, competitive analysis, target customer profile, glossary, and development philosophy all in one file. These are valuable but should not be loaded for coding tasks.

Suggested restructure:
- `docs/business/README.md` — Pricing, revenue model, competitive landscape, target customer
- `docs/business/glossary.md` — Wine industry terms (useful reference but rarely needed during coding)
- Keep the development philosophy ("don't ship in July", "offline is first-class") in the main README or a short `docs/PRINCIPLES.md` — these ARE relevant to coding decisions

---

## Current File Inventory

Scan these and decide what to keep, merge, trim, or relocate:

```
docs/
├── README.md              (315 lines — bloated, needs splitting)
├── architecture.md        (881 lines — likely needs trimming or splitting)
├── WORKFLOW.md            (301 lines — review for relevance)
├── diagrams/              (9 mermaid/jsx files — keep as-is, these are cheap)
├── execution/
│   ├── completed/         (5 files — historical, rarely loaded)
│   ├── phase-recaps/      (4 files — important, used when starting new phase)
│   └── tasks/             (26 files — task specs for all 25 phases + index)
├── guides/                (4 files — how-to docs, good candidates for lazy-load)
├── ideas/                 (15+ files — already well-structured with README + TRIAGE)
├── legacy/                (8 files — old handoff docs, probably deletable)
├── marketing/             (1 file — should move to business/)
├── refactors/             (4 files — active, well-structured, leave alone)
├── references/            (7 files — the sweet spot for lazy-loading)
└── templates/             (4 files — keep as-is)
```

---

## Specific Decisions to Make

1. **`legacy/` folder** — Contains old phase handoff docs and task generation prompts from before the current structure existed. Read them and decide: is there anything here not captured in `phase-recaps/`? If not, delete the folder.

2. **`architecture.md`** — 881 lines is too long to load in full. Consider splitting into:
   - A short architecture overview (stack, key patterns, 50-80 lines) that goes in the main README or a small `ARCHITECTURE.md`
   - Detailed sections become reference docs (e.g., the event log section → already exists as `references/event-log.md`, so maybe just delete the duplicate from architecture.md)

3. **`WORKFLOW.md`** — Review whether this is still accurate post-Phase 4. If yes, keep but trim. If it duplicates what's in phase-recaps or templates, consolidate.

4. **`execution/completed/`** — These are INFO files from completed phases. If phase-recaps cover the same ground, these can go.

5. **`execution/tasks/`** — 25 task files for future phases. These are the "load one at a time" tasks Konrad mentioned. They should stay, but the index (`00-index.md`) should be the routing mechanism. Consider whether the index needs updating.

6. **Task files for completed phases** (01-04) — Should move to `execution/completed/` or be deleted since phase-recaps exist.

---

## Constraints

- Output format is `.md` (not .docx, not Notion, not anything else)
- The refactors/ folder was JUST restructured — don't touch it unless consolidating is obvious
- The ideas/ folder is already well-structured — don't touch unless obvious cleanup needed
- Don't delete anything without checking if its content is captured elsewhere first
- The primary audience for these docs is AI agents (Claude), not humans reading a wiki
- Token efficiency is the #1 goal — every line should earn its place

---

## Definition of Done

- [ ] `docs/README.md` is under 80 lines and functions as a routing table
- [ ] Business context (pricing, revenue, competitors, glossary) lives in `docs/business/`
- [ ] `architecture.md` is either trimmed to <200 lines or split into focused reference docs
- [ ] `legacy/` is either deleted or its unique content absorbed elsewhere
- [ ] No doc contains large blocks of content duplicated in another doc
- [ ] Every reference doc has a one-line entry in the README index with a "when to load" hint
- [ ] Running `wc -l docs/**/*.md` shows a meaningful reduction from the current 16,135 lines
