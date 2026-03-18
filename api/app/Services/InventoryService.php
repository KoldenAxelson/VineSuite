<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\InventoryServiceInterface;
use App\Models\CaseGoodsSku;
use App\Models\Location;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Support\LogContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * InventoryService — the single entry point for all stock level mutations.
 *
 * All stock changes go through this service. It:
 * - Creates an immutable StockMovement ledger entry
 * - Atomically updates the StockLevel using SELECT FOR UPDATE
 * - Writes events to the event log via EventLogger
 * - Handles transfers between locations as paired movements
 *
 * NEVER update StockLevel directly — always go through this service.
 */
class InventoryService implements InventoryServiceInterface
{
    public function __construct(
        private readonly EventLogger $eventLogger,
    ) {}

    /**
     * Record stock received at a location (positive inflow).
     *
     * @param  array{reference_type?: string, reference_id?: string, notes?: string}  $options
     */
    public function receive(
        string $skuId,
        string $locationId,
        int $quantity,
        string $performedBy,
        array $options = [],
    ): StockMovement {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Receive quantity must be positive.');
        }

        return $this->recordMovement(
            skuId: $skuId,
            locationId: $locationId,
            movementType: 'received',
            quantity: $quantity,
            performedBy: $performedBy,
            eventType: 'stock_received',
            options: $options,
        );
    }

    /**
     * Record stock sold from a location (negative outflow).
     *
     * @param  array{reference_type?: string, reference_id?: string, notes?: string}  $options
     */
    public function sell(
        string $skuId,
        string $locationId,
        int $quantity,
        string $performedBy,
        array $options = [],
    ): StockMovement {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Sell quantity must be positive (it will be negated internally).');
        }

        return $this->recordMovement(
            skuId: $skuId,
            locationId: $locationId,
            movementType: 'sold',
            quantity: -$quantity,
            performedBy: $performedBy,
            eventType: 'stock_sold',
            options: $options,
        );
    }

    /**
     * Record a stock adjustment (positive or negative).
     *
     * @param  array{reference_type?: string, reference_id?: string, notes?: string}  $options
     */
    public function adjust(
        string $skuId,
        string $locationId,
        int $quantity,
        string $performedBy,
        array $options = [],
    ): StockMovement {
        $options['reference_type'] = $options['reference_type'] ?? 'adjustment';

        return $this->recordMovement(
            skuId: $skuId,
            locationId: $locationId,
            movementType: 'adjusted',
            quantity: $quantity,
            performedBy: $performedBy,
            eventType: 'stock_adjusted',
            options: $options,
        );
    }

    /**
     * Transfer stock from one location to another.
     *
     * Creates two movements: negative at source, positive at destination.
     * Both are wrapped in a single transaction with row-level locks.
     *
     * @return array{from: StockMovement, to: StockMovement}
     */
    public function transfer(
        string $skuId,
        string $fromLocationId,
        string $toLocationId,
        int $quantity,
        string $performedBy,
        ?string $notes = null,
    ): array {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Transfer quantity must be positive.');
        }

        if ($fromLocationId === $toLocationId) {
            throw new \InvalidArgumentException('Cannot transfer to the same location.');
        }

        return DB::transaction(function () use ($skuId, $fromLocationId, $toLocationId, $quantity, $performedBy, $notes) {
            $transferId = (string) Str::uuid();

            // Outflow from source
            $fromMovement = $this->recordMovementInTransaction(
                skuId: $skuId,
                locationId: $fromLocationId,
                movementType: 'transferred',
                quantity: -$quantity,
                performedBy: $performedBy,
                referenceType: 'transfer',
                referenceId: $transferId,
                notes: $notes,
            );

            // Inflow to destination
            $toMovement = $this->recordMovementInTransaction(
                skuId: $skuId,
                locationId: $toLocationId,
                movementType: 'transferred',
                quantity: $quantity,
                performedBy: $performedBy,
                referenceType: 'transfer',
                referenceId: $transferId,
                notes: $notes,
            );

            // Single event for the transfer
            $sku = CaseGoodsSku::find($skuId);
            $fromLocation = Location::find($fromLocationId);
            $toLocation = Location::find($toLocationId);

            $this->eventLogger->log(
                entityType: 'stock_movement',
                entityId: $transferId,
                operationType: 'stock_transferred',
                payload: [
                    'sku_id' => $skuId,
                    'wine_name' => $sku?->wine_name,
                    'from_location_id' => $fromLocationId,
                    'from_location_name' => $fromLocation?->name,
                    'to_location_id' => $toLocationId,
                    'to_location_name' => $toLocation?->name,
                    'quantity' => $quantity,
                ],
                performedBy: $performedBy,
                performedAt: now(),
            );

            Log::info('Stock transferred between locations', LogContext::with([
                'transfer_id' => $transferId,
                'sku_id' => $skuId,
                'from_location_id' => $fromLocationId,
                'to_location_id' => $toLocationId,
                'quantity' => $quantity,
            ], $performedBy));

            return ['from' => $fromMovement, 'to' => $toMovement];
        });
    }

    /**
     * Record a movement and atomically update the stock level.
     *
     * Wraps the operation in a transaction with SELECT FOR UPDATE locking
     * to prevent race conditions from concurrent POS sales.
     *
     * @param  array{reference_type?: string, reference_id?: string, notes?: string}  $options
     */
    private function recordMovement(
        string $skuId,
        string $locationId,
        string $movementType,
        int $quantity,
        string $performedBy,
        string $eventType,
        array $options = [],
    ): StockMovement {
        return DB::transaction(function () use ($skuId, $locationId, $movementType, $quantity, $performedBy, $eventType, $options) {
            $movement = $this->recordMovementInTransaction(
                skuId: $skuId,
                locationId: $locationId,
                movementType: $movementType,
                quantity: $quantity,
                performedBy: $performedBy,
                referenceType: $options['reference_type'] ?? null,
                referenceId: $options['reference_id'] ?? null,
                notes: $options['notes'] ?? null,
            );

            // Write event
            $sku = CaseGoodsSku::find($skuId);
            $location = Location::find($locationId);

            $this->eventLogger->log(
                entityType: 'stock_movement',
                entityId: $movement->id,
                operationType: $eventType,
                payload: [
                    'sku_id' => $skuId,
                    'wine_name' => $sku?->wine_name,
                    'location_id' => $locationId,
                    'location_name' => $location?->name,
                    'movement_type' => $movementType,
                    'quantity' => $quantity,
                    'reference_type' => $options['reference_type'] ?? null,
                    'reference_id' => $options['reference_id'] ?? null,
                ],
                performedBy: $performedBy,
                performedAt: now(),
            );

            Log::info('Stock movement recorded', LogContext::with([
                'movement_id' => $movement->id,
                'sku_id' => $skuId,
                'location_id' => $locationId,
                'movement_type' => $movementType,
                'quantity' => $quantity,
            ], $performedBy));

            return $movement;
        });
    }

    /**
     * Create the movement record and update the stock level.
     *
     * MUST be called within an existing DB::transaction.
     * Uses SELECT FOR UPDATE to lock the StockLevel row.
     */
    private function recordMovementInTransaction(
        string $skuId,
        string $locationId,
        string $movementType,
        int $quantity,
        string $performedBy,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $notes = null,
    ): StockMovement {
        // Lock or create the stock level row
        /** @var StockLevel|null $stockLevel */
        $stockLevel = StockLevel::query()
            ->where('sku_id', $skuId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();

        if (! $stockLevel) {
            $stockLevel = StockLevel::create([
                'sku_id' => $skuId,
                'location_id' => $locationId,
                'on_hand' => 0,
                'committed' => 0,
            ]);
        }

        // Update on_hand
        $stockLevel->on_hand += $quantity;
        $stockLevel->save();

        // Create the movement ledger entry
        return StockMovement::create([
            'sku_id' => $skuId,
            'location_id' => $locationId,
            'movement_type' => $movementType,
            'quantity' => $quantity,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'performed_by' => $performedBy,
            'performed_at' => now(),
            'notes' => $notes,
        ]);
    }
}
