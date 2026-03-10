# VineSuite Development Workflow

How tasks move from spec to shipped code, and how knowledge is captured along the way.

---

## The Problem This Solves

AI assistants are stateless. Every session starts from zero. Without a disciplined workflow, you get:
- AI re-discovers decisions that were already made three sessions ago
- "It works on my machine" with no record of why things are the way they are
- Completion docs that nobody reads because they're too long or too vague to be useful
- Knowledge trapped in chat logs that expire

This workflow turns each completed task into a reusable context packet that future sessions (AI or human) can load in and immediately understand the current state of any module.

---

## Directory Structure

```
docs/
├── architecture.md                  # System blueprint (read-only, update rarely)
├── README.md                        # Business context and product overview
├── WORKFLOW.md                      # This file
│
├── execution/                       # Where work gets tracked
│   ├── tasks/                       # TASK files — the specs (input)
│   │   ├── 00-index.md
│   │   ├── 01-foundation.md
│   │   └── ...
│   ├── completed/                   # INFO files — task completion records (output)
│   │   ├── 01-foundation.info.md
│   │   └── ...
│   └── phase-recaps/               # Phase rollup docs (synthesized at phase end)
│       ├── phase-1-foundation.md
│       └── ...
│
├── references/                      # Quick-load context for AI sessions
│   ├── event-log.md                 # How events work, patterns, gotchas
│   ├── multi-tenancy.md             # Tenant lifecycle, schema isolation
│   ├── payments.md                  # Payment abstraction, Stripe patterns
│   ├── sync-engine.md              # KMP sync, conflict resolution, offline
│   └── ...                          # One per major subsystem
│
├── guides/                          # Human-facing operational guides
│   ├── local-dev-setup.md           # Docker, env, first run
│   ├── adding-a-new-module.md       # Patterns to follow for new features
│   ├── ttb-report-testing.md        # How to verify TTB compliance
│   ├── deployment.md                # Forge, CI/CD, rollback
│   └── ...
│
├── diagrams/                        # .mermaid files for system visualization
│   ├── system-overview.mermaid
│   ├── event-flow.mermaid
│   ├── sync-architecture.mermaid
│   ├── payment-flow.mermaid
│   └── ...
│
├── guides/                          # Human-facing operational guides
│   ├── testing-and-logging.md       # Test tiers, log levels, what to skip
│   ├── local-dev-setup.md           # Docker, env, first run
│   └── ...
│
└── templates/                       # Reusable document templates
    ├── sub-task.template.md         # Structure for task spec files
    ├── info-file.template.md        # Per-sub-task completion records
    ├── phase-recap.template.md      # Phase rollup summaries
    └── reference-doc.template.md    # Subsystem quick-reference docs
```

---

## The Task Lifecycle

Every sub-task follows this cycle. No exceptions, no shortcuts.

```
┌─────────────────────────────────────────────────────────────────┐
│                                                                 │
│  1. LOAD        AI reads: TASK file + relevant references       │
│       ↓                                                         │
│  2. BUILD       AI implements the sub-task                      │
│       ↓                                                         │
│  3. TEST        AI writes tests, runs them, iterates            │
│       ↓                                                         │
│  4. VERIFY      Human confirms tests pass, reviews code         │
│       ↓                                                         │
│  5. RECORD      AI appends to the INFO file                     │
│       ↓                                                         │
│  6. UPDATE      AI updates references if patterns changed       │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### Step 1 — LOAD (AI Context Priming)

Before writing any code, the AI session loads these files:

**Always load:**
- `docs/execution/tasks/{XX-module}.md` — the task spec being worked on
- `docs/execution/completed/{XX-module}.info.md` — if it exists (prior sub-tasks in this module)

**Load if relevant:**
- `docs/references/{subsystem}.md` — if the task touches that subsystem
- `docs/architecture.md` Section 3 — if anything touches the event log
- The phase recap for any completed dependency phase

**Never load everything.** The AI should load 3-5 files max. The reference docs exist specifically so the AI doesn't need to re-read the entire architecture doc every session.

### Step 2 — BUILD

AI implements the sub-task per the spec. Standard dev work — models, migrations, services, controllers, Filament resources, etc.

The TASK file's acceptance criteria define "done." The gotchas section prevents known pitfalls. If the AI encounters a decision not covered by the task spec, it should:
1. State the decision clearly
2. Explain the tradeoff
3. Ask the human before proceeding
4. Record the decision in the INFO file (Step 5)

### Step 3 — TEST

AI writes and runs tests per the TASK file's Testing Notes section **and** the tier system defined in `docs/guides/testing-and-logging.md`.

The quick version: every sub-task must have Tier 1 tests (money, data integrity, compliance, event log writes) and should have Tier 2 tests (API contracts, CRUD workflows, business rules). Tier 3 tests (simple accessors, framework behavior) are optional unless the code is unusually complex. The guide covers all three tiers in detail, plus logging levels, structured log formats, and language-specific tooling for PHP, Kotlin, and TypeScript.

Tests must pass in the Docker environment (`make test`). If tests require fixtures or seed data, add them.

### Step 4 — VERIFY (Human Gate)

Human reviews:
- Tests pass (`make test` output)
- Code makes sense (skim, not line-by-line — trust the tests)
- No obvious deviations from architecture patterns
- Any decisions flagged in Step 2 are acceptable

Human signals: **approve**, **request changes**, or **flag for discussion**.

If changes requested, cycle back to Step 2. If flagged for discussion, resolve before proceeding — the decision may affect downstream tasks.

### Step 5 — RECORD (INFO File)

AI appends a completion entry to `docs/execution/completed/{XX-module}.info.md`.

This is the most important step. The INFO file is the institutional memory. It's what future AI sessions load to understand what was built and why.

**INFO file format** (see `docs/templates/info-file.template.md`):

```markdown
## Sub-Task {N}: {Title}
**Completed:** {date}
**Status:** Done

### What Was Built
- Bullet list of files created/modified
- What each file does (one line)

### Key Decisions
- Any decisions made during implementation that weren't in the TASK spec
- Why that choice was made
- What alternative was considered and rejected

### Deviations from Spec
- Anything that differs from what the TASK file described
- Why the deviation was necessary
- Impact on downstream tasks (if any)

### Patterns Established
- New patterns introduced that future sub-tasks should follow
- Example: "All event handlers follow the {pattern} established in LotEventHandler"

### Test Summary
- Which test files were created
- What's covered
- Any known gaps or deferred test scenarios

### Open Questions
- Anything unresolved that a future session should be aware of
```

**What makes a good INFO entry:**
- A future AI session can read just this entry and understand the current state
- Decisions are explained, not just listed
- Patterns are named so they can be referenced ("follow the TransferService pattern")
- It's honest about gaps and deferred work

**What makes a bad INFO entry:**
- Just a list of files with no context
- "Implemented per spec" (useless — if it was per spec, why did we need the record?)
- Missing the key decisions (the most valuable part)

### Step 6 — UPDATE (Reference Docs)

If the sub-task established or changed a pattern that other modules will need to follow, update the relevant reference doc in `docs/references/`.

Examples of when to update:
- Sub-task 1 of 01-foundation creates the EventLogger service → write `docs/references/event-log.md` explaining how to use it
- A payment sub-task establishes the PaymentProcessor interface → update `docs/references/payments.md`
- A sync-related sub-task changes conflict resolution rules → update `docs/references/sync-engine.md`

Reference docs are living documents. They should always reflect the current state of the code, not the original spec. If the code diverged from the architecture doc, the reference doc reflects reality (and notes the divergence).

---

## Phase Completion

When all sub-tasks in all TASK files for a phase are complete, synthesize a phase recap.

### Phase Recap Contents

The recap is NOT a copy-paste of all INFO files. It's a condensed summary aimed at someone who needs to understand "what does Phase N give me and what should I know going forward."

```markdown
# Phase {N} Recap — {Name}

## What Was Delivered
- Feature summary in 3-5 bullets (what a winery owner would understand)

## Architecture Decisions Made
- Only decisions that affect downstream phases
- Link to the INFO file for full context

## Deviations from Original Spec
- Only deviations that change assumptions for future phases
- "We planned X but built Y because Z"

## Patterns Established
- Consolidated list of patterns future phases should follow
- Named and briefly described (detailed examples in reference docs)

## Known Debt
- Test gaps, deferred features, TODO items
- Ranked by impact on downstream work

## Reference Docs Updated
- Which reference docs were created or modified during this phase

## Metrics
- Sub-tasks completed: X/Y
- Test count: N (unit: A, integration: B)
- Files created: N
```

### What the Phase Recap Replaces

Once a phase recap exists, future AI sessions working on later phases should load the **phase recap** instead of individual INFO files from that phase. The recap is the compressed context. If they need detail on a specific decision, the recap links back to the relevant INFO entry.

This is how the context stays manageable as the project grows. Phase 1's 15 INFO entries become one recap. By Phase 5 you have 5 recaps instead of 85 INFO entries.

---

## Ideas Triage (Phase Gate)

Before starting a new phase, triage the ideas backlog. Strategic ideas live in `docs/ideas/` as markdown files — they describe *what* and *why* but not *how*. The triage process converts the right ideas into sub-tasks at the right time.

**When:** At the start of each phase, after the phase recap for the prior phase is complete but before any code is written.

**How:** Follow the process in `docs/ideas/TRIAGE.md`. Each idea gets one of three dispositions: **Absorb** (becomes a sub-task or design constraint), **Defer** (with a target phase), or **Reject** (with a reason). The triage record is appended to `TRIAGE.md` under the relevant phase heading.

**Rule:** Ideas that arrive mid-phase go into `docs/ideas/` but don't enter scope until the next triage checkpoint — unless they reveal a design constraint that affects work already in progress.

---

## Context Loading Cheat Sheet

Depending on what you're working on, here's what to load:

| Working On | Load These |
|---|---|
| Starting a new sub-task | TASK file + this module's INFO file + relevant references |
| Debugging a specific module | INFO file for that module + relevant reference docs |
| Starting a new phase | Phase recaps for all completed phases + new TASK files + `ideas/TRIAGE.md` |
| Cross-module work | INFO files for both modules + architecture.md dependency section |
| Fixing a bug in payments | `references/payments.md` + the payment-related source files |
| Adding a new event type | `references/event-log.md` + the module's TASK and INFO files |

---

## File Naming Conventions

| Type | Location | Pattern | Example |
|---|---|---|---|
| Task spec | `execution/tasks/` | `{NN}-{module}.md` | `02-production-core.md` |
| Completion record | `execution/completed/` | `{NN}-{module}.info.md` | `02-production-core.info.md` |
| Phase recap | `execution/phase-recaps/` | `phase-{N}-{name}.md` | `phase-1-foundation.md` |
| Reference doc | `references/` | `{topic}.md` | `event-log.md` |
| Guide | `guides/` | `{topic}.md` | `local-dev-setup.md` |
| Diagram | `diagrams/` | `{topic}.mermaid` | `event-flow.mermaid` |
| Template | `templates/` | `{type}.template.md` | `info-file.template.md` |

---

## Rules

1. **Never skip the INFO file.** Code without a completion record is invisible to future sessions.
2. **Keep references current.** A stale reference doc is worse than no reference doc — it gives the AI confident wrong context.
3. **Phase recaps are mandatory.** Don't start Phase N+1 without a recap for Phase N.
4. **Triage ideas at phase boundaries.** Every idea gets a disposition before the new phase begins. See `docs/ideas/TRIAGE.md`.
5. **Load narrow, not wide.** 3-5 context files per session. If you need more, the reference docs aren't doing their job.
6. **Decisions are the most valuable artifact.** Code is readable. Tests are verifiable. But the *why behind a choice* is invisible unless someone writes it down.
7. **INFO files are append-only.** Never edit a prior sub-task's entry. If something changes, add a new entry noting the change.
