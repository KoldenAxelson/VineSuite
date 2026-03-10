# {Module Name}

## Phase
Phase {N}

## Dependencies
{List task file numbers this depends on, e.g. "01-foundation, 07-kmp-shared-core" — or "None" if first.}

## Goal
{One paragraph: what this module delivers, why it matters to a winery owner, and what can't be built without it. Write this for someone who hasn't read the architecture doc.}

## Data Models

### {Schema Scope — e.g. "Tenant Schema" or "Central Schema"}
- **ModelName** — `field` (type), `field` (type → foreign_key), `field` (enum: value1/value2), `created_at`, `updated_at`

{Repeat for each model. Use arrow notation for foreign keys: `user_id` (UUID → users). Mark nullable fields explicitly. Include enum values inline.}

---

## Sub-Tasks

### {N}. {Title}
**Description:** {What to build and why. One paragraph max.}
**Files to create/modify:**
- `api/app/Models/{Model}.php` — {one-line purpose}
- `api/app/Services/{Service}.php` — {one-line purpose}
- `api/database/migrations/xxxx_{name}.php`
- `api/routes/api.php` — add routes for {what}
- `api/tests/Feature/{Test}.php`
**Acceptance criteria:**
- {Concrete, testable statement — not "works correctly" but "returns 200 with JSON payload containing X"}
- {Another}
**Gotchas:**
- {Known pitfall, edge case, or architectural constraint that's easy to miss}

{Repeat for each sub-task. Number sequentially. Earlier sub-tasks in the same file may be dependencies for later ones — call this out in the Description if so.}

---

## API Endpoints

| Method | URI | Description | Auth | Tier |
|--------|-----|-------------|------|------|
| GET | `/api/v1/{resource}` | List all with pagination | Sanctum | Starter |
| POST | `/api/v1/{resource}` | Create new | Sanctum + role | Starter |
| GET | `/api/v1/{resource}/{id}` | Show single | Sanctum | Starter |
| PUT | `/api/v1/{resource}/{id}` | Update | Sanctum + role | Starter |
| DELETE | `/api/v1/{resource}/{id}` | Soft-delete | Sanctum + owner | Starter |

{Include every endpoint this module exposes. Mark tier gates (Starter/Growth/Pro). Mark role requirements. If an endpoint is internal-only (not public API), note it.}

---

## Events

| Operation Type | Entity Type | Payload Fields | Triggered By |
|---------------|-------------|----------------|--------------|
| `{entity}_created` | `{entity}` | `{field1}`, `{field2}`, ... | `{ServiceClass}` |
| `{entity}_updated` | `{entity}` | `changed_fields`, `old_values`, `new_values` | `{ServiceClass}` |

{Every state change must produce an event. This table is the contract — the handler that materializes CRUD state reads from these payloads. If a sub-task doesn't produce events, it's either a read-only feature or something is missing.}

---

## Testing Notes

{Refer to `docs/guides/testing-and-logging.md` for tier definitions. Summarize what's specific to THIS module:}

- **Tier 1 (must have):** {e.g. "Event log writes for every lot operation, inventory math accuracy, TTB report generation"}
- **Tier 2 (should have):** {e.g. "Filament resource CRUD flows, permission gate enforcement"}
- **Tier 3 (skip unless complex):** {e.g. "UI rendering, simple getters/setters"}
- **Compliance tests:** {if applicable — TTB, DTC shipping, payment PCI considerations}
- **Cross-module integration:** {if this module depends on another, describe the integration test}
