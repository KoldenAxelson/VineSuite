<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\BlendTrial;

/**
 * BlendServiceInterface
 *
 * Contract for blend trial and blending process management services.
 * Implementations: App\Services\BlendService
 */
interface BlendServiceInterface
{
    /**
     * Create a new blend trial.
     *
     * @param  array<string, mixed>  $data
     */
    public function createTrial(array $data, string $createdBy): BlendTrial;

    /**
     * Finalize a blend trial and apply the blend.
     */
    public function finalizeTrial(BlendTrial $trial, string $performedBy): BlendTrial;
}
