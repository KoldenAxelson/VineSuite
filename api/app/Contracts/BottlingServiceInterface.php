<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\BottlingRun;

/**
 * BottlingServiceInterface
 *
 * Contract for bottling run management services.
 * Implementations: App\Services\BottlingService
 */
interface BottlingServiceInterface
{
    /**
     * Create a new bottling run.
     *
     * @param  array<string, mixed>  $data
     */
    public function createBottlingRun(array $data, string $performedBy): BottlingRun;

    /**
     * Complete a bottling run.
     */
    public function completeBottlingRun(BottlingRun $run, string $performedBy): BottlingRun;
}
