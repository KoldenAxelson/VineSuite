<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base API resource that wraps responses in the standard envelope.
 *
 * All API resources should extend this class to ensure consistent
 * response format: { "data": ..., "meta": {}, "errors": [] }
 *
 * Usage:
 *   return new UserResource($user);
 *   return UserResource::collection($users);
 */
class BaseResource extends JsonResource
{
    /**
     * Customize the response to include the envelope.
     *
     * @param  array<string, mixed>  $resourceData
     * @return array<string, mixed>
     */
    public static function envelope(array $resourceData): array
    {
        return [
            'data' => $resourceData['data'] ?? $resourceData,
            'meta' => (object) ($resourceData['meta'] ?? []),
            'errors' => [],
        ];
    }

    /**
     * Customize the outgoing response.
     */
    public function withResponse(Request $request, JsonResponse $response): void
    {
        /** @var array<string, mixed> $original */
        $original = $response->getData(true);

        $response->setData(static::envelope($original));
    }
}
