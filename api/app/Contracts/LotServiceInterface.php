<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Exceptions\Domain\InsufficientVolumeException;
use App\Models\Lot;

/**
 * LotServiceInterface
 *
 * Contract for lot management services.
 * Implementations: App\Services\LotService
 */
interface LotServiceInterface
{
    /**
     * Create a new lot.
     *
     * @param  array<string, mixed>  $data
     */
    public function createLot(array $data, string $performedBy): Lot;

    /**
     * Update an existing lot.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateLot(Lot $lot, array $data, string $performedBy): Lot;

    /**
     * Adjust lot volume by a delta (positive or negative).
     *
     * All volume mutations should go through this method to ensure
     * consistent event logging, invariant enforcement, and audit trail.
     *
     * @param  Lot  $lot  The lot to adjust
     * @param  float  $deltaGallons  Volume change (negative for deductions)
     * @param  string  $reason  Why the volume changed (e.g., 'bottling_completed', 'blend_finalization', 'transfer_executed')
     * @param  string  $performedBy  UUID of the user
     * @param  array<string, mixed>  $context  Additional event payload context
     * @return Lot The updated lot
     *
     * @throws InsufficientVolumeException If deduction would go below zero
     */
    public function adjustVolume(Lot $lot, float $deltaGallons, string $reason, string $performedBy, array $context = []): Lot;
}
