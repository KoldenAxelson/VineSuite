# Foundation — COMPLETED

> **Status: COMPLETED** — This phase is historical. Agents should use phase recaps (in /sessions/practical-wizardly-albattani/mnt/VineSuite/docs/recaps/) instead.

## Quick Reference

**Phase:** 1
**Dependencies:** None — foundation for all modules.
**Core accomplishments:** Docker env, Laravel 12, multi-tenancy, auth/RBAC, event log, Filament portal shell, Stripe billing, CI/CD.

---

## Sub-Tasks (Completed)

1. **Docker Compose environment** — Laravel (PHP 8.4), PostgreSQL 16, Redis 7, Meilisearch, Mailpit, Horizon. Bind to `0.0.0.0:8000`. Use `linux/arm64` images (M2 Mac).

2. **Laravel 12 initialization** — Fresh install, PostgreSQL default, Redis cache/queue, Mailpit local mail. Test with real PostgreSQL (not SQLite).

3. **Multi-tenancy with stancl/tenancy** — Schema-per-tenant, central registry, subdomain + API token header identification. Test cross-tenant isolation.

4. **Authentication (Sanctum + RBAC)** — Sanctum tokens, spatie/laravel-permission, 7 roles (owner/admin/winemaker/cellar_hand/tasting_room_staff/accountant/read_only). Token scopes per client type.

5. **Team invitations** — Owner sends invite link, invitee sets password, auto-assigned role. 72h expiry, no duplicates per email.

6. **Event log table** — UUID PK, JSONB payload, unique idempotency_key, never modified. BRIN index on `performed_at`. This is the core system.

7. **Event sync API** — POST `/api/v1/events/sync` accepts batch, deduplicates by idempotency_key, triggers materialized state updates, idempotent.

8. **Winery profile** — Basic CRUD, unit preference (gallons/liters), TTB permit, fiscal year start, timezone. Auto-created on tenant provision.

9. **Filament shell** — v3.x only, tenant-aware auth, navigation groups (Production, Inventory, Compliance, Sales, Club, CRM, Settings).

10. **Stripe billing** — Laravel Cashier, Starter/Growth/Pro plans, plan changes, webhook sync (payment_succeeded, subscription_updated, subscription_deleted). Central-schema billing.

11. **Activity logging** — LogsActivity trait for audit trail, separate from event log (activities track system changes; events track winery operations).

12. **CI/CD pipeline** — GitHub Actions: PHPUnit + PostgreSQL service, Pint, PHPStan level 6, deploy to Forge on main after CI passes.

13. **API response envelope** — Standard `{ "data": ..., "meta": ..., "errors": [...] }` format. Validation errors 422, 404/500 clean JSON.

14. **Rate limiting + API versioning** — `/api/v1/` prefix. Portal 120 req/min, Mobile 60 req/min, Widget 30 req/min. Per-key throttling for widgets.

15. **Demo seeder** — Demo tenant "Paso Robles Cellars" with owner, 2 staff, realistic winery profile. Modular (each phase adds its own demo data).

---

## Remaining Gotchas

- **Idempotency key enforcement:** Critical for mobile offline sync. Duplicate submissions must be silently ignored or return existing event.
- **Event immutability:** No UPDATE or DELETE queries on events table. Enforce via trigger or application guard.
- **Sanctum token scopes:** Different limits per client type (portal vs mobile vs widget).
- **Filament pin:** Stay on v3.x—do not upgrade to v4 without explicit decision.

---

## Critical Tests

- Cross-tenant data access blocked.
- Events table immutability enforced.
- Stripe webhook lifecycle (create → upgrade/downgrade → cancel).
- CI PostgreSQL service matches production version (16).
