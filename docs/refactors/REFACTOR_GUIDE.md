# Refactor Guide

This folder tracks code improvements that don't change functionality — structural cleanup, design pattern fixes, and architecture hygiene.

## Priority Tiers

| File | When to do it | Risk of deferral |
|------|---------------|------------------|
| `HIGH.md` | Before the next phase of development begins | Will create compounding tech debt or audit gaps |
| `MEDIUM.md` | Before the codebase rigidifies (more consumers, more modules) | Harder to fix later but won't break anything today |
| `LOW.md` | Whenever convenient, even after the codebase is mature | Minimal risk; quality-of-life improvements |

## How to Use This Folder

**Adding items:** Describe the problem, where it lives, why it matters, and sketch the fix. Link to specific files and line ranges when possible. Every item should be actionable — if someone reads it cold, they should be able to start working immediately.

**Completing items:** Remove the entry entirely. Don't mark it done, don't strikethrough it, don't move it to an archive section. A completed refactor is a deleted entry. This keeps the files short and prevents token/attention waste for both humans and AI agents scanning for work.

**Promoting/demoting items:** If circumstances change (a new module makes a MEDIUM item suddenly urgent), move it to the appropriate file. Cut from one, paste into the other.

**Empty files:** If a priority tier has no items, leave a single line: `No items.` This confirms someone reviewed it recently rather than it being forgotten.

## Principles

- **Refactors don't change behavior.** If it adds a feature or fixes a bug, it belongs in the task pipeline, not here.
- **Every entry should cite specific files.** Vague entries like "clean up services" are useless.
- **Prefer small, incremental refactors** over sweeping rewrites. Each item should be completable in a single sitting.
- **Tests pass before and after.** If a refactor breaks tests, it wasn't a refactor.
