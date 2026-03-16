<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Transfer;

/**
 * TransferServiceInterface
 *
 * Contract for transfer (lot movement) management services.
 * Implementations: App\Services\TransferService
 */
interface TransferServiceInterface
{
    /**
     * Execute a transfer of wine between containers or locations.
     *
     * @param  array<string, mixed>  $data
     */
    public function executeTransfer(array $data, string $performedBy): Transfer;
}
