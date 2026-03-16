# VineSuite Development Workflow

How tasks move from spec to shipped code, and how knowledge is captured for future sessions.

AI assistants are stateless — every session starts from zero. This workflow turns each completed task into a reusable context packet that future sessions can load and immediately understand the current state.

---

## Directory Structure

```
docs/
├── README.md                        # Routing table — start here
├── architecture.md                  # Technical blueprint (condensed)
├── WORKFLOW.md                      # This file
├── execution/
│   ├── tasks/                       # TASK files — specs (input)
│   │   ├── 00-index.md
│   │   └── {NN}-{module}.md
│   ├── completed/                   # INFO files — completion records (output)
│   │   └── {NN}-{module}.info.md
│   └── phase-recaps/               # Phase summaries (synthesized at phase end)
│       └── phase-{N}-{name}.md
├── references/                      # Quick-load context for specific subsystems
├── guides/                          # Operational how-to docs
├── diagrams/                        # .mermaid and .jsx visualization files
├── templates/                       # Reusable document templates
├── business/                        # Pricing, revenue model, competitive analysis, glossary
├── ideas/                           # Feature ideas backlog (see ideas/TRIAGE.md)
└── refactors/                       # Active refactor specs
```

---

## The Task Lifecycle

Every sub-task follows this cycle. No exceptions.

```
1. LOAD    → AI reads: TASK file + relevant references (3-5 files max)
2. BUILD   → AI implements the sub-task
3. TEST    → AI writes tests, runs them, iterates
4. VERIFY  → Human confirms tests pass, reviews code
5. RECORD  → AI appends to the INFO file
6. UPDATE  → AI updates reference docs if patterns changed
```

### Step 1 — LOAD

**Always load:**
- `execution/tasks/{NN-module}.md` — the task spec
- `execution/completed/{NN-module}.info.md` — if it exists (prior sub-tasks)

**Load if relevant:**
- `references/{subsystem}.md` — if the task touches that subsystem
- Phase recap for any completed dependency phase

**Never load everything.** 3-5 context files per session. The reference docs exist so the AI doesn't need the full architecture doc every time.

### Step 2 — BUILD

Implement per the TASK spec's acceptance criteria. If you hit an uncovered decision: state it clearly, explain the tradeoff, ask the human, and record it in the INFO file.

### Step 3 — TEST

Write and run tests per the TASK file's Testing Notes and `guides/testing-and-logging.md`.

Quick version: every sub-task needs Tier 1 tests (money, data integrity, compliance, event log writes) and should have Tier 2 tests (API contracts, CRUD workflows, business rules). Tier 3 (simple accessors) is optional.

Tests must pass in Docker (`make test`).

### Step 4 — VERIFY (Human Gate)

Human reviews: tests pass, code makes sense, no architecture deviations, flagged decisions are acceptable. Signals: **approve**, **request changes**, or **flag for discussion**.

### Step 5 — RECORD (INFO File)

Append a completion entry to `execution/completed/{NN-module}.info.md`. Use `templates/info-file.template.md` for the format.

This is the most important step. The INFO file is the institutional memory — what future sessions load to understand what was built and why. Decisions are the most valuable part.

### Step 6 — UPDATE (Reference Docs)

If the sub-task established or changed a pattern others will follow, update `references/{topic}.md`. Reference docs reflect the current code, not the original spec.

---

## Phase Completion

When all sub-tasks for a phase are complete, synthesize a phase recap using `templates/phase-recap.template.md`.

The recap is NOT a copy-paste of INFO files. It's a condensed summary: what was delivered, architecture decisions, deviations, patterns, known debt, metrics. Future sessions load the **phase recap** instead of individual INFO files.

This is how context stays manageable: Phase 1's 15 INFO entries become one recap. By Phase 5 you have 5 recaps instead of 85 INFO entries.

---

## Ideas Triage

Before starting a new phase, triage the ideas backlog per `ideas/TRIAGE.md`. Each idea gets: **Absorb** (becomes a sub-task), **Defer** (with target phase), or **Reject** (with reason).

Ideas arriving mid-phase go into `ideas/` but don't enter scope until the next triage checkpoint — unless they reveal a design constraint affecting in-progress work.

---

## Context Loading Cheat Sheet

| Working On | Load These |
|---|---|
| Starting a new sub-task | TASK file + INFO file + relevant references |
| Debugging a module | INFO file + relevant reference docs |
| Starting a new phase | Phase recaps (all completed) + new TASK files + `ideas/TRIAGE.md` |
| Cross-module work | Both INFO files + architecture.md |
| Adding a new event type | `references/event-log.md` + module's TASK and INFO files |

---

## File Naming Conventions

| Type | Location | Pattern | Example |
|---|---|---|---|
| Task spec | `execution/tasks/` | `{NN}-{module}.md` | `05-cost-accounting.md` |
| Completion record | `execution/completed/` | `{NN}-{module}.info.md` | `04-inventory.info.md` |
| Phase recap | `execution/phase-recaps/` | `phase-{N}-{name}.md` | `phase-4-inventory.md` |
| Reference doc | `references/` | `{topic}.md` | `event-log.md` |
| Guide | `guides/` | `{topic}.md` | `testing-and-logging.md` |
| Diagram | `diagrams/` | `{topic}.mermaid` | `event-flow.mermaid` |
| Template | `templates/` | `{type}.template.md` | `info-file.template.md` |

---

## Rules

1. **Never skip the INFO file.** Code without a completion record is invisible to future sessions.
2. **Keep references current.** A stale reference doc is worse than none — it gives confident wrong context.
3. **Phase recaps are mandatory.** Don't start Phase N+1 without a recap for Phase N.
4. **Triage ideas at phase boundaries.** Every idea gets a disposition. See `ideas/TRIAGE.md`.
5. **Load narrow, not wide.** 3-5 context files per session.
6. **Decisions are the most valuable artifact.** Code is readable. Tests are verifiable. The *why* is invisible unless written down.
7. **INFO files are append-only.** Never edit a prior sub-task's entry. Add a new entry noting the change.
