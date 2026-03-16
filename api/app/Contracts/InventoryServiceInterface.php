<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\StockMovement;

/**
 * InventoryServiceInterface
 *
 * Contract for inventory and stock movement management services.
 * Implementations: App\Services\InventoryService
 */
interface InventoryServiceInterface
{
    /**
     * Record inventory receipt.
     *
     * @param  array<string, mixed>  $options
     */
    public function receive(string $skuId, string $locationId, int $quantity, string $performedBy, array $options = []): StockMovement;

    /**
     * Record inventory sale/removal.
     *
     * @param  array<string, mixed>  $options
     */
    public function sell(string $skuId, string $locationId, int $quantity, string $performedBy, array $options = []): StockMovement;

    /**
     * Record inventory adjustment.
     *
     * @param  array<string, mixed>  $options
     */
    public function adjust(string $skuId, string $locationId, int $quantity, string $performedBy, array $options = []): StockMovement;

    /**
     * Transfer inventory between locations.
     *
     * @return array{from: StockMovement, to: StockMovement}
     */
    public function transfer(string $skuId, string $fromLocationId, string $toLocationId, int $quantity, string $performedBy, ?string $notes = null): array;
}
