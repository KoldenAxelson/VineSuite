<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to check if the authenticated user has one of the required roles.
 *
 * Usage in routes:
 *   ->middleware('role:owner,admin')
 *   ->middleware('role:winemaker,cellar_hand')
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Check the user's `role` column directly (fast check)
        if (in_array($user->role, $roles)) {
            return $next($request);
        }

        // Also check spatie roles as a fallback
        if ($user->hasAnyRole($roles)) {
            return $next($request);
        }

        return response()->json(['message' => 'Forbidden. Required role: ' . implode(' or ', $roles)], 403);
    }
}
