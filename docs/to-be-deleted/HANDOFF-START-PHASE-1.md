# VineSuite — Phase 1 Handoff Prompt

> Copy everything below the line into your first AI session to begin work on Phase 1.
> This prompt assumes the AI can create, edit, and delete files in the VineSuite workspace.
> The AI does NOT have an interactive question tool — if it has questions, it must append them to the end of its response and wait for your reply.

---

## Who You Are

You are building VineSuite, a winery SaaS platform. The project has been fully planned — architecture, task specs, workflow, and templates are all in place. Your job now is to execute, starting with Phase 1: Foundation.

You do not need to plan. The planning is done. You need to build, test, and record.

## Before You Write Any Code

Read these files in this order. Do not skip any of them.

1. `docs/WORKFLOW.md` — This defines the entire development lifecycle you must follow: LOAD → BUILD → TEST → VERIFY → RECORD → UPDATE. It tells you how to write INFO files, when to update reference docs, and how context management works across sessions. This is your operating manual.

2. `docs/execution/tasks/01-foundation.md` — This is the task spec for Phase 1. It contains 15 sub-tasks in sequence, each with a description, files to create, acceptance criteria, and gotchas. Work through them top-to-bottom.

3. `docs/guides/testing-and-logging.md` — This defines the three testing tiers (what must be tested, what should be tested, what to skip) and logging standards (log levels, structured format, what to never log). Every sub-task you complete must follow these standards.

4. `docs/architecture.md` — Read Section 3 (Event Log) and Section 4 (Multi-Tenancy) closely. The event log is the single most important data structure in the system. Every winery operation writes an immutable event; materialized CRUD tables are derived from these events via handlers. Multi-tenancy uses schema-per-tenant via `stancl/tenancy`. You will implement both of these in Phase 1.

5. `docs/references/event-log.md`, `docs/references/multi-tenancy.md`, `docs/references/auth-rbac.md` — These are reference stubs. They're mostly empty now. As you build Sub-Tasks 3, 4, and 6, you will populate these with real patterns, code examples, and gotchas based on what you actually implement.

## The Templates You Must Use

All templates are in `docs/templates/`. Read them before you need them.

- `sub-task.template.md` — The structure every task file follows. You won't need to create new task files in Phase 1, but understanding the structure helps you read the spec correctly.
- `info-file.template.md` — **You will use this after every sub-task.** After completing a sub-task and having the human verify it, create (or append to) `docs/execution/completed/01-foundation.info.md` following this template exactly. The INFO file is the institutional memory — future sessions depend on it.
- `reference-doc.template.md` — Use this when populating the reference stubs in `docs/references/`. Follow the structure: What This Is, How It Works, Key Files, Usage Patterns (with real code), Gotchas, History.

## What Already Exists in the Workspace

The monorepo skeleton is scaffolded but mostly empty:

```
VineSuite/
├── api/                    ← Laravel 12 goes here (Phase 1, Sub-Task 2)
├── shared/                 ← KMP shared core (Phase 4, ignore for now)
├── apps/cellar/            ← Cellar app (Phase 5, ignore)
├── apps/pos/               ← POS app (Phase 6, ignore)
├── widgets/                ← Web Components (Phase 7, ignore)
├── vinebook/               ← Astro directory (Phase 8, ignore)
├── migration-workbench/    ← Separate Laravel app (Phase 8, ignore)
├── docker-compose.yml      ← Exists but needs to be verified/updated in Sub-Task 1
├── Makefile                ← Dev commands (make up, make test, etc.)
└── docs/                   ← All documentation lives here
```

The `docker-compose.yml` and `Makefile` at the root were created during planning and may need adjustment when you actually stand up the Docker environment. Treat them as starting points, not sacred.

## Your First Sub-Task

Start with **Sub-Task 1: Docker Compose development environment** from `docs/execution/tasks/01-foundation.md`.

Before you begin building, tell the human:
- What you're about to do (one sentence)
- What files you'll create or modify
- Whether there are any questions or decisions that need human input before proceeding

Then build it. Then test it. Then ask the human to verify. Then write the INFO entry. Then move to Sub-Task 2.

## Human Steps Required

Some sub-tasks require things the AI cannot do alone. Here's what to expect:

**Sub-Task 1 (Docker):** The human needs to run `docker compose up -d` on their machine and confirm services start. You can write the files, but you can't run Docker.

**Sub-Task 2 (Laravel init):** The human needs to run `composer create-project laravel/laravel api` inside the Docker container (or on their machine with PHP 8.4+). You can then configure the resulting project.

**Sub-Task 4 (Auth/RBAC):** The human needs a Stripe test API key for `.env`. Remind them to get one from https://dashboard.stripe.com/test/apikeys if they don't have one yet. This is also needed for Sub-Task 10 (Stripe billing).

**Sub-Task 10 (Stripe billing):** The human needs to:
- Set up Stripe test products and prices for the three plans (Starter $99/mo, Growth $249/mo, Pro $499/mo)
- Configure the Stripe webhook endpoint in the Stripe dashboard to point at the local dev URL (using Stripe CLI or ngrok)
- Provide the webhook signing secret for `.env`

**Sub-Task 12 (CI/CD):** The human needs to:
- Create the GitHub repository if it doesn't exist
- Add repository secrets for Forge deploy hook, Stripe keys, etc.
- Verify the first CI run passes

For all other sub-tasks, you can build and test autonomously. When in doubt about whether something needs human action, state what you need at the end of your response.

## Critical Rules

1. **Follow the sub-task order.** They're sequenced for a reason. Sub-Task 6 (Event Log) depends on Sub-Task 2 (Laravel) and Sub-Task 3 (Multi-Tenancy). Don't skip ahead.

2. **Write the INFO file after every sub-task.** Not at the end of the session. Not in a batch. After each one. The template is in `docs/templates/info-file.template.md`. Append to `docs/execution/completed/01-foundation.info.md`.

3. **Populate reference docs as you go.** When you build the event log (Sub-Task 6), update `docs/references/event-log.md` with real code examples from what you actually wrote. When you build multi-tenancy (Sub-Task 3), update `docs/references/multi-tenancy.md`. When you build auth (Sub-Task 4), update `docs/references/auth-rbac.md`.

4. **Test per the tier system.** `docs/guides/testing-and-logging.md` defines three tiers. Phase 1 is almost entirely Tier 1 (money, data integrity, auth, event log). Every sub-task needs tests. Use Pest, test against real PostgreSQL (not SQLite), mock Stripe with `Http::fake()`.

5. **Log structured, not interpolated.** Use `Log::info('message', ['key' => 'value'])` format. Include `tenant_id` in every tenant-scoped log. See the logging guide for the full standard.

6. **The tech stack is locked.** Laravel 12, PHP 8.4+, PostgreSQL 16, Redis 7, Filament v3, Sanctum (not Passport), Pest (not PHPUnit syntax), `stancl/tenancy` for multi-tenancy, `spatie/laravel-permission` for RBAC, Laravel Cashier for Stripe billing. Do not substitute anything.

7. **If you hit a decision not covered by the spec,** describe the options, state your recommendation, explain the tradeoff, and ask the human. Then record the decision in the INFO file regardless of which way it goes.

8. **If you can't do something,** say so clearly at the end of your response. Don't silently skip it. The human would rather know "I need you to run this command" than discover later that a step was missed.

## Questions Format

Since you don't have an interactive question tool, append any questions to the end of your response under a clear heading:

```
---
## Questions Before Proceeding
1. [Your question here]
2. [Your question here]
```

The human will answer in their next message. Don't block on questions unless the answer fundamentally changes what you're about to build — if it's a minor detail, state your assumption, proceed, and note it in the INFO file.

## Go

Read the five files listed above. Then begin Sub-Task 1.
