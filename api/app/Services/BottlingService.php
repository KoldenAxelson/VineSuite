<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BottlingComponent;
use App\Models\BottlingRun;
use App\Models\Lot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * BottlingService — business logic for bottling runs.
 *
 * Bottling converts bulk wine (gallons) into case goods (bottles/cases).
 * On completion:
 * - Lot volume is deducted
 * - Cases produced is calculated
 * - SKU is auto-generated if not provided
 * - `bottling_completed` event is written
 * - Lot status can be set to 'bottled'
 *
 * Packaging material inventory deduction is stubbed for 04-inventory.md.
 */
class BottlingService
{
    public function __construct(
        protected EventLogger $eventLogger,
    ) {}

    /**
     * Create a bottling run with components.
     *
     * @param  array<string, mixed>  $data  Validated bottling run data with 'components' array
     * @param  string  $performedBy  UUID of the user
     */
    public function createBottlingRun(array $data, string $performedBy): BottlingRun
    {
        return DB::transaction(function () use ($data, $performedBy) {
            $components = $data['components'] ?? [];
            unset($data['components']);

            $data['performed_by'] = $performedBy;
            $data['status'] = 'planned';

            // Calculate cases produced
            $bottlesFilled = (int) $data['bottles_filled'];
            $bottlesPerCase = (int) ($data['bottles_per_case'] ?? 12);
            $data['cases_produced'] = intdiv($bottlesFilled, $bottlesPerCase);

            $run = BottlingRun::create($data);

            // Create component records
            foreach ($components as $component) {
                BottlingComponent::create([
                    'bottling_run_id' => $run->id,
                    'component_type' => $component['component_type'],
                    'product_name' => $component['product_name'],
                    'quantity_used' => $component['quantity_used'],
                    'quantity_wasted' => $component['quantity_wasted'] ?? 0,
                    'unit' => $component['unit'] ?? 'each',
                    'notes' => $component['notes'] ?? null,
                ]);
            }

            Log::info('Bottling run created', [
                'bottling_run_id' => $run->id,
                'lot_id' => $run->lot_id,
                'bottles_filled' => $bottlesFilled,
                'format' => $run->bottle_format,
                'tenant_id' => tenant('id'),
                'user_id' => $performedBy,
            ]);

            return $run->load(['components', 'lot', 'performer']);
        });
    }

    /**
     * Complete a bottling run — deducts lot volume and writes event.
     *
     * @throws ValidationException If run is already completed or lot has insufficient volume
     */
    public function completeBottlingRun(BottlingRun $run, string $performedBy): BottlingRun
    {
        if ($run->status === 'completed') {
            throw ValidationException::withMessages([
                'status' => ['This bottling run is already completed.'],
            ]);
        }

        return DB::transaction(function () use ($run, $performedBy) {
            $lot = Lot::findOrFail($run->lot_id);
            $volumeToDeduct = (float) $run->volume_bottled_gallons;
            $lotVolume = (float) $lot->volume_gallons;

            if ($volumeToDeduct > $lotVolume) {
                throw ValidationException::withMessages([
                    'volume' => [
                        "Lot \"{$lot->name}\" has only {$lotVolume} gallons available, but {$volumeToDeduct} gallons needed for bottling.",
                    ],
                ]);
            }

            // Deduct volume from lot
            $newVolume = $lotVolume - $volumeToDeduct;
            $lot->update([
                'volume_gallons' => $newVolume,
            ]);

            // Auto-generate SKU if not provided
            $sku = $run->sku;
            if (! $sku) {
                $varietyCode = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $lot->variety) ?? '', 0, 4));
                $sku = "{$varietyCode}-{$lot->vintage}-{$run->bottle_format}-".strtoupper(substr($run->id, 0, 6));
            }

            // Calculate final cases
            $bottlesFilled = (int) $run->bottles_filled;
            $bottlesPerCase = (int) $run->bottles_per_case;
            $casesProduced = intdiv($bottlesFilled, $bottlesPerCase);

            // Update run to completed
            $run->update([
                'status' => 'completed',
                'sku' => $sku,
                'cases_produced' => $casesProduced,
                'completed_at' => now(),
            ]);

            // If lot volume is now zero, optionally mark as bottled
            if ($newVolume <= 0) {
                $lot->update(['status' => 'bottled']);

                $this->eventLogger->log(
                    entityType: 'lot',
                    entityId: $lot->id,
                    operationType: 'lot_status_changed',
                    payload: [
                        'old_status' => 'in_progress',
                        'new_status' => 'bottled',
                        'reason' => 'bottling_completed',
                    ],
                    performedBy: $performedBy,
                    performedAt: now(),
                );
            }

            // Write bottling_completed event on the lot
            $run->load('components');
            $this->eventLogger->log(
                entityType: 'lot',
                entityId: $lot->id,
                operationType: 'bottling_completed',
                payload: [
                    'bottling_run_id' => $run->id,
                    'bottle_format' => $run->bottle_format,
                    'bottles_filled' => $bottlesFilled,
                    'bottles_breakage' => (int) $run->bottles_breakage,
                    'waste_percent' => (float) $run->waste_percent,
                    'volume_bottled_gallons' => $volumeToDeduct,
                    'cases_produced' => $casesProduced,
                    'sku' => $sku,
                    'old_lot_volume_gallons' => $lotVolume,
                    'new_lot_volume_gallons' => $newVolume,
                    'components' => $run->components->map(fn (BottlingComponent $c) => [
                        'component_type' => $c->component_type,
                        'product_name' => $c->product_name,
                        'quantity_used' => $c->quantity_used,
                        'quantity_wasted' => $c->quantity_wasted,
                    ])->all(),
                ],
                performedBy: $performedBy,
                performedAt: now(),
            );

            // TODO: Auto-deduct packaging materials from dry goods inventory (04-inventory.md)
            // TODO: Create case goods inventory entry (04-inventory.md)

            Log::info('Bottling run completed', [
                'bottling_run_id' => $run->id,
                'lot_id' => $lot->id,
                'sku' => $sku,
                'cases_produced' => $casesProduced,
                'volume_deducted' => $volumeToDeduct,
                'tenant_id' => tenant('id'),
                'user_id' => $performedBy,
            ]);

            return $run->fresh(['components', 'lot', 'performer']);
        });
    }
}
