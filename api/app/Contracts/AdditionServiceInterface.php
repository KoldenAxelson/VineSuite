<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Addition;

/**
 * AdditionServiceInterface
 *
 * Contract for addition (wine component) management services.
 * Implementations: App\Services\AdditionService
 */
interface AdditionServiceInterface
{
    /**
     * Create a new addition to a lot.
     *
     * @param  array<string, mixed>  $data
     */
    public function createAddition(array $data, string $performedBy): Addition;

    /**
     * Get the running total of SO2 for a lot.
     */
    public function getSo2RunningTotal(string $lotId): float;
}
