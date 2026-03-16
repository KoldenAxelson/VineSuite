# {Subsystem Name}

> {One-line summary of what this subsystem does}

---

## What This Is
{One paragraph: what this subsystem does, why it exists, where it fits in the architecture.}

## How It Works
{The mechanics. Enough detail that an AI session can read this and correctly use/modify the subsystem without reading the full architecture doc. Include code snippets if the pattern is non-obvious. Break into sub-sections as needed.}

## Key Files
| File | Purpose |
|------|---------|
| `path/to/file` | {what it does} |

## Usage Patterns
{How other modules interact with this subsystem. Show the typical call pattern.}

```php
// Example
app(EventLogger::class)->record(
    entityType: 'lot',
    entityId: $lot->id,
    operationType: 'addition_made',
    payload: [...],
    performedBy: auth()->id(),
    performedAt: now(),
);
```

## Rules
{Non-negotiable constraints. Things an agent must not violate.}
- {Rule 1}
- {Rule 2}
