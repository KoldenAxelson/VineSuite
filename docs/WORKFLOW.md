# VineSuite Development Workflow

```
docs/
├── README.md                  # Routing table — start here
├── architecture.md            # Technical blueprint (condensed)
├── WORKFLOW.md                # This file
├── execution/
│   ├── tasks/{NN}-{module}.md         # Task specs (input)
│   ├── completed/{NN}-{module}.info.md # Completion records (output)
│   └── phase-recaps/phase-{N}-{name}.md
├── references/                # Subsystem context (load per-task)
├── guides/                    # How-to docs
├── business/                  # Pricing, revenue, competitors, glossary
├── templates/                 # INFO file, phase recap, reference doc templates
├── ideas/                     # Feature backlog (see ideas/TRIAGE.md)
└── refactors/                 # Active refactor specs
```

---

## Task Lifecycle

```
LOAD → BUILD → TEST → VERIFY → RECORD → UPDATE
```

**LOAD:** Read TASK file + INFO file (if exists) + relevant `references/`. Load phase recaps for dependency phases. 3-5 files max.

**BUILD:** Implement per TASK spec acceptance criteria. Uncovered decisions → state tradeoff, ask human, record in INFO.

**TEST:** Per TASK Testing Notes + `guides/testing-and-logging.md`. Tier 1 required (money, data integrity, compliance, events). Tier 2 expected (API, CRUD, business rules). Tier 3 optional. Must pass via `make test`.

**VERIFY:** Human gate. Approve / request changes / flag for discussion.

**RECORD:** Append to `execution/completed/{NN-module}.info.md`. Use `templates/info-file.template.md`. Capture decisions — they're the highest-value artifact.

**UPDATE:** If new pattern established → update `references/{topic}.md`. Reference docs reflect current code, not original spec.

---

## Phase Completion

Synthesize recap using `templates/phase-recap.template.md`. Condensed summary (not copy-paste of INFO files): deliverables, decisions, deviations, patterns, debt, metrics. Future sessions load recaps instead of individual INFO files.

## Ideas Triage

Before each new phase: triage `ideas/TRIAGE.md`. Each idea → **Absorb** / **Defer** (with target phase) / **Reject** (with reason). Mid-phase ideas go to `ideas/` but don't enter scope.

---

## Context Loading

| Task | Load |
|---|---|
| New sub-task | TASK + INFO + relevant references |
| Debugging | INFO + reference docs |
| New phase | All phase recaps + TASK files + `ideas/TRIAGE.md` |
| Cross-module | Both INFO files + `architecture.md` |
| New event type | `references/event-log.md` + module TASK/INFO |

## File Naming

| Type | Pattern | Example |
|---|---|---|
| Task spec | `execution/tasks/{NN}-{module}.md` | `05-cost-accounting.md` |
| Completion | `execution/completed/{NN}-{module}.info.md` | `04-inventory.info.md` |
| Phase recap | `execution/phase-recaps/phase-{N}-{name}.md` | `phase-4-inventory.md` |
| Reference | `references/{topic}.md` | `event-log.md` |
| Guide | `guides/{topic}.md` | `testing-and-logging.md` |

---

## Rules

1. Never skip the INFO file.
2. Keep references current. Stale context is worse than no context.
3. Phase recaps mandatory before starting next phase.
4. Triage ideas at phase boundaries.
5. Load narrow (3-5 files), not wide.
6. Record decisions — code is readable, the *why* isn't.
7. INFO files are append-only.
