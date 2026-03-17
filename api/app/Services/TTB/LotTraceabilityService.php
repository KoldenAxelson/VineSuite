<?php

declare(strict_types=1);

namespace App\Services\TTB;

use App\Models\Event;
use App\Models\Lot;

/**
 * LotTraceabilityService — builds the full chain from grape intake to final sale.
 *
 * Queries the event log to build one-step-back / one-step-forward lot traces.
 * Required for FDA traceability and recall scenarios.
 *
 * Trace chain: grape source → lot → blend → bottle → order
 */
class LotTraceabilityService
{
    /**
     * Build the full trace for a lot.
     *
     * @return array{
     *     lot: array{id: string, name: string, variety: string|null, vintage: int|null},
     *     backward: array<int, array{type: string, description: string, event_id: string, performed_at: string|null, payload: array<string, mixed>}>,
     *     forward: array<int, array{type: string, description: string, event_id: string, performed_at: string|null, payload: array<string, mixed>}>,
     *     timeline: array<int, array{type: string, description: string, event_id: string, performed_at: string|null}>,
     * }
     */
    public function trace(string $lotId): array
    {
        $lot = Lot::find($lotId);

        $lotInfo = [
            'id' => $lotId,
            'name' => ($lot !== null ? $lot->name : 'Unknown'),
            'variety' => $lot?->variety,
            'vintage' => $lot?->vintage,
        ];

        $backward = $this->traceBackward($lotId);
        $forward = $this->traceForward($lotId);
        $timeline = $this->buildTimeline($lotId);

        return [
            'lot' => $lotInfo,
            'backward' => $backward,
            'forward' => $forward,
            'timeline' => $timeline,
        ];
    }

    /**
     * Trace backward — where did this lot's grapes/wine come from?
     *
     * @return array<int, array{type: string, description: string, event_id: string, performed_at: string|null, payload: array<string, mixed>}>
     */
    public function traceBackward(string $lotId): array
    {
        $steps = [];

        // 1. Lot creation — grape source info
        $creationEvent = Event::ofType('lot_created')
            ->forEntity('lot', $lotId)
            ->first();

        if ($creationEvent !== null) {
            $steps[] = [
                'type' => 'lot_created',
                'description' => 'Lot created — '.($creationEvent->payload['name'] ?? 'Unknown'),
                'event_id' => $creationEvent->id,
                'performed_at' => $creationEvent->performed_at->toIso8601String(),
                'payload' => $creationEvent->payload,
            ];
        }

        // 2. Check if this lot was created from a blend
        $blendEvents = Event::ofType('blend_finalized')
            ->forEntity('lot', $lotId)
            ->get();

        foreach ($blendEvents as $blendEvent) {
            $steps[] = [
                'type' => 'blend_source',
                'description' => 'Created from blend — '.($blendEvent->payload['component_count'] ?? '?').' components',
                'event_id' => $blendEvent->id,
                'performed_at' => $blendEvent->performed_at->toIso8601String(),
                'payload' => $blendEvent->payload,
            ];

            // Recursively trace blend source lots
            $sourceIds = $blendEvent->payload['source_lot_ids'] ?? [];
            foreach ($sourceIds as $sourceId) {
                $sourceLot = Lot::find($sourceId);
                if ($sourceLot !== null) {
                    $steps[] = [
                        'type' => 'blend_component',
                        'description' => 'Blend source: '.$sourceLot->name,
                        'event_id' => $blendEvent->id,
                        'performed_at' => $blendEvent->performed_at->toIso8601String(),
                        'payload' => ['source_lot_id' => $sourceId, 'source_lot_name' => $sourceLot->name],
                    ];
                }
            }
        }

        return $steps;
    }

    /**
     * Trace forward — where did this lot's wine go?
     *
     * @return array<int, array{type: string, description: string, event_id: string, performed_at: string|null, payload: array<string, mixed>}>
     */
    public function traceForward(string $lotId): array
    {
        $steps = [];

        // 1. Blends this lot was used in
        $blendUseEvents = Event::where('operation_type', 'blend_finalized')
            ->whereJsonContains('payload->source_lot_ids', $lotId)
            ->get();

        foreach ($blendUseEvents as $event) {
            $steps[] = [
                'type' => 'used_in_blend',
                'description' => 'Used in blend',
                'event_id' => $event->id,
                'performed_at' => $event->performed_at->toIso8601String(),
                'payload' => $event->payload,
            ];
        }

        // 2. Bottling runs
        $bottlingEvents = Event::ofType('bottling_completed')
            ->get()
            ->filter(fn (Event $e) => ($e->payload['lot_id'] ?? $e->entity_id) === $lotId);

        foreach ($bottlingEvents as $event) {
            $steps[] = [
                'type' => 'bottled',
                'description' => sprintf(
                    'Bottled — %s gal, %s bottles',
                    $event->payload['volume_bottled_gallons'] ?? '?',
                    $event->payload['bottles'] ?? '?',
                ),
                'event_id' => $event->id,
                'performed_at' => $event->performed_at->toIso8601String(),
                'payload' => $event->payload,
            ];
        }

        // 3. Sales / removals
        $soldEvents = Event::ofType('stock_sold')
            ->get()
            ->filter(fn (Event $e) => ($e->payload['lot_id'] ?? '') === $lotId);

        foreach ($soldEvents as $event) {
            $steps[] = [
                'type' => 'sold',
                'description' => 'Wine sold',
                'event_id' => $event->id,
                'performed_at' => $event->performed_at->toIso8601String(),
                'payload' => $event->payload,
            ];
        }

        return $steps;
    }

    /**
     * Build a complete timeline for the lot (all events, chronological).
     *
     * @return array<int, array{type: string, description: string, event_id: string, performed_at: string|null}>
     */
    public function buildTimeline(string $lotId): array
    {
        $events = Event::forEntity('lot', $lotId)
            ->orderBy('performed_at')
            ->get();

        return $events->map(fn (Event $e) => [
            'type' => $e->operation_type,
            'description' => $this->describeEvent($e),
            'event_id' => $e->id,
            'performed_at' => $e->performed_at->toIso8601String(),
        ])->toArray();
    }

    /**
     * Generate a human-readable description for an event.
     */
    private function describeEvent(Event $event): string
    {
        $payload = $event->payload;

        return match ($event->operation_type) {
            'lot_created' => 'Lot created — '.($payload['name'] ?? 'Unknown'),
            'lot_volume_adjusted' => sprintf('Volume adjusted by %s gal', $payload['adjustment'] ?? '?'),
            'blend_finalized' => 'Blend finalized',
            'bottling_completed' => sprintf('Bottled %s gal (%s bottles)', $payload['volume_bottled_gallons'] ?? '?', $payload['bottles'] ?? '?'),
            'transfer_executed' => sprintf('Transferred from %s to %s', $payload['from_vessel'] ?? '?', $payload['to_vessel'] ?? '?'),
            'addition_created' => 'Addition: '.($payload['product_name'] ?? $payload['addition_type'] ?? 'Unknown'),
            default => ucfirst(str_replace('_', ' ', $event->operation_type)),
        };
    }
}
