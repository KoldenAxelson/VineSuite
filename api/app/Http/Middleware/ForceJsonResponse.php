<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces all API requests to accept and return JSON.
 *
 * Applied to /api/* routes only. Ensures that:
 * 1. The request Accept header is set to application/json
 * 2. Laravel's exception handler renders JSON errors (not HTML)
 *
 * Filament (portal) routes are excluded — they use HTML/Livewire.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
