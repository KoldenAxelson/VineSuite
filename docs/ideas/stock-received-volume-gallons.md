# stock_received Events Need volume_gallons

## Context

TTB compliance requires `volume_gallons` on all `stock_received` events. The Cellar App's `EventFactory` currently has no special handling for this operation type — the payload shape is caller-defined via `buildJsonObject {}`.

## When This Matters

If the Cellar App ever adds a stock receiving screen (not currently in the Task 8 spec), the event payload must include `volume_gallons` for the server-side TTB reporting pipeline to work correctly.

## What To Do

When building any UI that creates `stock_received` events, ensure the payload includes:

```json
{
  "volume_gallons": 300.0,
  "source": "purchase",
  ...
}
```

The server's `EventProcessor` reads `volume_gallons` from the payload to update TTB materialized tables. Missing it would cause silent TTB reporting gaps.

## Origin

Carry-over debt item from `docs/execution/handoffs/08-cellar-app-handoff.md`, item #4.
