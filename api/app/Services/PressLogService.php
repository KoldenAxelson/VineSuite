<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lot;
use App\Models\PressLog;
use App\Support\LogContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PressLogService — business logic for pressing operations.
 *
 * Pressing converts grape must to juice. A single pressing may produce
 * multiple fractions (free run, light press, heavy press), each of which
 * can optionally become a child lot with its own event stream.
 *
 * Yield is calculated as: (total_juice_gallons / fruit_weight_kg) * 100
 * This gives a weight-to-volume ratio useful for winery benchmarking.
 */
class PressLogService
{
    public function __construct(
        private readonly EventLogger $eventLogger,
    ) {}

    /**
     * Log a pressing operation.
     *
     * @param  array<string, mixed>  $data  Validated press log data
     * @param  string  $performedBy  UUID of the user
     */
    public function logPressing(array $data, string $performedBy): PressLog
    {
        return DB::transaction(function () use ($data, $performedBy) {
            $parentLot = Lot::findOrFail($data['lot_id']);

            // Calculate yield percent
            $fruitWeight = (float) $data['fruit_weight_kg'];
            $totalJuice = (float) $data['total_juice_gallons'];
            $yieldPercent = $fruitWeight > 0 ? ($totalJuice / $fruitWeight) * 100 : 0;

            $data['yield_percent'] = round($yieldPercent, 4);
            $data['performed_by'] = $performedBy;
            $data['performed_at'] = $data['performed_at'] ?? now();

            // Create child lots for fractions that request them
            $fractions = $data['fractions'];
            $childLotIds = [];

            foreach ($fractions as $i => $fraction) {
                if (! empty($fraction['create_child_lot']) && $fraction['create_child_lot'] === true) {
                    $childLot = $this->createFractionChildLot(
                        parentLot: $parentLot,
                        fraction: $fraction,
                        performedBy: $performedBy,
                    );
                    $fractions[$i]['child_lot_id'] = $childLot->id;
                    $childLotIds[] = [
                        'fraction' => $fraction['fraction'],
                        'child_lot_id' => $childLot->id,
                        'child_lot_name' => $childLot->name,
                        'volume_gallons' => $fraction['volume_gallons'],
                    ];
                } else {
                    $fractions[$i]['child_lot_id'] = $fraction['child_lot_id'] ?? null;
                }
                // Remove the create_child_lot flag before storing
                unset($fractions[$i]['create_child_lot']);
            }

            $data['fractions'] = $fractions;

            $pressLog = PressLog::create($data);

            // Write pressing_logged event on the parent lot
            $this->eventLogger->log(
                entityType: 'lot',
                entityId: $pressLog->lot_id,
                operationType: 'pressing_logged',
                payload: [
                    'press_log_id' => $pressLog->id,
                    'press_type' => $pressLog->press_type,
                    'fruit_weight_kg' => (float) $pressLog->fruit_weight_kg,
                    'total_juice_gallons' => (float) $pressLog->total_juice_gallons,
                    'yield_percent' => (float) $pressLog->yield_percent,
                    'fraction_count' => count($fractions),
                    'child_lots' => $childLotIds,
                    'pomace_weight_kg' => $pressLog->pomace_weight_kg ? (float) $pressLog->pomace_weight_kg : null,
                    'pomace_destination' => $pressLog->pomace_destination,
                ],
                performedBy: $performedBy,
                performedAt: $pressLog->performed_at,
            );

            Log::info('Pressing logged', LogContext::with([
                'press_log_id' => $pressLog->id,
                'lot_id' => $pressLog->lot_id,
                'press_type' => $pressLog->press_type,
                'fruit_weight_kg' => $pressLog->fruit_weight_kg,
                'total_juice_gallons' => $pressLog->total_juice_gallons,
                'yield_percent' => $pressLog->yield_percent,
                'child_lots_created' => count($childLotIds),
            ], $performedBy));

            return $pressLog->load(['lot', 'vessel', 'performer']);
        });
    }

    /**
     * Create a child lot for a press fraction.
     *
     * Child lots inherit variety, vintage, and source from the parent.
     * Their name includes the fraction type (e.g., "Lot 2024-CS — Free Run").
     */
    /** @param  array<string, mixed>  $fraction */
    private function createFractionChildLot(Lot $parentLot, array $fraction, string $performedBy): Lot
    {
        $fractionLabel = str_replace('_', ' ', ucwords($fraction['fraction'], '_'));

        $childLot = Lot::create([
            'name' => "{$parentLot->name} — {$fractionLabel}",
            'variety' => $parentLot->variety,
            'vintage' => $parentLot->vintage,
            'source_type' => $parentLot->source_type,
            'source_details' => $parentLot->source_details,
            'volume_gallons' => $fraction['volume_gallons'],
            'status' => 'in_progress',
            'parent_lot_id' => $parentLot->id,
        ]);

        $this->eventLogger->log(
            entityType: 'lot',
            entityId: $childLot->id,
            operationType: 'lot_created',
            payload: [
                'name' => $childLot->name,
                'variety' => $childLot->variety,
                'vintage' => $childLot->vintage,
                'source_type' => $childLot->source_type,
                'initial_volume_gallons' => (float) $fraction['volume_gallons'],
                'parent_lot_id' => $parentLot->id,
                'press_fraction' => $fraction['fraction'],
            ],
            performedBy: $performedBy,
            performedAt: now(),
        );

        return $childLot;
    }
}
