<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * Standardized API response envelope.
 *
 * All API responses follow: { "data": ..., "meta": {}, "errors": [] }
 *
 * Usage:
 *   ApiResponse::success($user)
 *   ApiResponse::success($users, meta: ['page' => 1, 'total' => 50])
 *   ApiResponse::created($invitation)
 *   ApiResponse::message('Invitation sent.')
 *   ApiResponse::error('Not found.', 404)
 *   ApiResponse::validationError(['email' => ['The email field is required.']])
 */
class ApiResponse
{
    /**
     * Success response with data.
     *
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $headers
     */
    public static function success(mixed $data = null, int $status = 200, array $meta = [], array $headers = []): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => (object) $meta,
            'errors' => [],
        ], $status, $headers);
    }

    /**
     * 201 Created response.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function created(mixed $data = null, array $meta = []): JsonResponse
    {
        return static::success($data, 201, $meta);
    }

    /**
     * Success response with just a message (no resource data).
     *
     * @param  array<string, mixed>  $meta
     */
    public static function message(string $message, int $status = 200, array $meta = []): JsonResponse
    {
        return response()->json([
            'data' => null,
            'meta' => (object) array_merge(['message' => $message], $meta),
            'errors' => [],
        ], $status);
    }

    /**
     * Error response.
     *
     * @param  string|array<int, array<string, mixed>>  $errors
     */
    public static function error(string|array $errors, int $status = 400): JsonResponse
    {
        if (is_string($errors)) {
            $errors = [['message' => $errors]];
        }

        return response()->json([
            'data' => null,
            'meta' => (object) [],
            'errors' => $errors,
        ], $status);
    }

    /**
     * 422 Validation error response with field-level details.
     *
     * @param  array<string, array<int, string>>  $fieldErrors
     */
    public static function validationError(array $fieldErrors): JsonResponse
    {
        $errors = [];
        foreach ($fieldErrors as $field => $messages) {
            foreach ($messages as $message) {
                $errors[] = [
                    'field' => $field,
                    'message' => $message,
                ];
            }
        }

        return response()->json([
            'data' => null,
            'meta' => (object) [],
            'errors' => $errors,
        ], 422);
    }

    /**
     * Paginated response — extracts pagination meta from a LengthAwarePaginator.
     *
     * @param  \Illuminate\Pagination\LengthAwarePaginator<int, mixed>  $paginator
     * @param  array<string, mixed>  $extraMeta
     */
    public static function paginated(\Illuminate\Pagination\LengthAwarePaginator $paginator, array $extraMeta = []): JsonResponse
    {
        return response()->json([
            'data' => $paginator->items(),
            'meta' => (object) array_merge([
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ], $extraMeta),
            'errors' => [],
        ]);
    }
}
