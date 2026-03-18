# Starter Prompt — Task 8: Cellar App

> Copy the block below into your first AI session for this phase.

---

You're picking up VineSuite, a winery SaaS platform. Phases 1–7 are complete (870+ PHP tests, 116 KMP JVM tests passing).

Before writing any code, read `docs/execution/handoffs/08-cellar-app-handoff.md`. That's your onboarding doc — it points to everything else you need. Also read `docs/README.md` and `docs/WORKFLOW.md` so you understand how the project is structured and how we work.

You're building Task 8: the native Cellar App — Android (Jetpack Compose) and iOS (SwiftUI) mobile apps for cellar floor operations, built on the KMP shared core in `shared/`. Offline-first, with QR barrel scanning, work order management, and real-time sync. The shared core (database, sync engine, API client, conflict resolution) is already built and tested. Your job is the native UI shell on top of it. Sub-tasks are sequenced — work them in order. **One sub-task at a time.** After each sub-task: write the INFO entry, run tests, then stop and check in with me before moving to the next one.
