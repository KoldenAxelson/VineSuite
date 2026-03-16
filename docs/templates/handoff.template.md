# Phase {N} Handoff — {Phase Name}

> Phases 1–{N-1} complete. {test count}+ tests passing, PHPStan level 6 (zero errors), Pint (zero style issues).

## Read Before Coding

1. `docs/README.md` — doc routing table
2. `docs/CONVENTIONS.md` — code patterns (treat as law)
3. `docs/WORKFLOW.md` — dev lifecycle
4. `docs/execution/tasks/{NN}-{module}.md` — your task spec (the "Before starting" block at the top has phase-specific pointers)

## What's Relevant From Previous Phases

{2-3 sentences per phase, focusing ONLY on what this phase needs to know. Not an inventory — just the models, services, or patterns this phase touches.}

## Carry-Over Debt

{Bullet list of unfinished items from prior phases that affect this phase's work. If none, say "None blocking."}

## Phase-Specific Notes

{Anything an agent needs to know that doesn't live in the task file or conventions. Integration gotchas, non-obvious model relationships, fields that exist but aren't wired yet. Keep it short — if it belongs in the task file, put it there instead.}

## Rules

- Follow sub-task order. They're sequenced for dependencies.
- Write the INFO file after every sub-task: `docs/execution/completed/{NN}-{module}.info.md`
- Don't break existing tests. Run `make testsuite`, not just new tests.
- New ideas go to `docs/ideas/`, not into scope.
- Tech stack is locked.

## Go

Start with Sub-Task 1 of `docs/execution/tasks/{NN}-{module}.md`.
