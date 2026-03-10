# Authentication & RBAC

> Last updated: —
> Relevant source: `api/app/Models/User.php`, role/permission models (not yet created)
> Architecture doc: Section 2.1, 2.2

---

## What This Is
Laravel Sanctum token auth for all clients. Scoped tokens per client type (Portal, Cellar App, POS, Widgets, Public API). Role-based access control within each tenant. No Passport, no OAuth — Sanctum throughout.

## How It Works
*To be written during Phase 1, Sub-Task 3 (Auth & RBAC).*

## Roles
Expected roles (to be confirmed during implementation):
- **Owner** — full access, billing, settings
- **Winemaker** — production, lab, compliance, reporting
- **Cellar Hand** — work orders, additions, transfers, barrel ops (no admin)
- **Tasting Room Manager** — POS, reservations, CRM (no production)
- **Tasting Room Staff** — POS operations only
- **Accountant** — reporting, COGS, integrations (read-heavy, no production ops)

## Gotchas
- Token abilities scope what a token CAN do (client-level). Roles scope what a USER can do (permission-level). Both must pass.
- Public API tokens (Pro tier) use Sanctum abilities as the permission system — no separate OAuth
- Cellar App stores token in platform keychain (iOS Keychain / Android EncryptedSharedPreferences)

## History
- To be populated as work progresses
