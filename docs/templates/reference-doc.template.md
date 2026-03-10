# {Subsystem Name}

> Last updated: YYYY-MM-DD
> Relevant source: `api/app/Services/{file}.php` (or wherever the primary code lives)

---

## What This Is
{One paragraph: what this subsystem does, why it exists, where it fits in the architecture.}

## How It Works
{The mechanics. Enough detail that an AI session can read this and correctly use/modify the subsystem without reading the full architecture doc. Include code snippets if the pattern is non-obvious.}

## Key Files
| File | Purpose |
|------|---------|
| `path/to/file` | {what it does} |

## Usage Patterns
{How other modules interact with this subsystem. Show the typical call pattern.}

```php
// Example: logging an event
app(EventLogger::class)->record(
    entityType: 'lot',
    entityId: $lot->id,
    operationType: 'addition_made',
    payload: [...],
    performedBy: auth()->id(),
    performedAt: now(),
);
```

## Gotchas
- {Common mistake and how to avoid it}
- {Edge case to watch for}

## History
- {YYYY-MM-DD}: Created during Phase {N}, Sub-Task {M}
- {YYYY-MM-DD}: Updated — {what changed and why}
