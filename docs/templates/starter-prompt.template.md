# Starter Prompt Template

> Copy the block below into your first AI session for a new phase. Fill in the blanks.

---

You're picking up VineSuite, a winery SaaS platform. Phases 1–{N-1} are complete ({test count}+ tests passing).

Before writing any code, read `docs/execution/handoffs/{NN}-{module}-handoff.md`. That's your onboarding doc — it points to everything else you need. Also read `docs/README.md` and `docs/WORKFLOW.md` so you understand how the project is structured and how we work.

You're building Phase {N}: {one-sentence description}. {X} sub-tasks, work them in order. **One sub-task at a time.** After each sub-task: write the INFO entry, run `make testsuite`, then stop and check in with me before moving to the next one.
