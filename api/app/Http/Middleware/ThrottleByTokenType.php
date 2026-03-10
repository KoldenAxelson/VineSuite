<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate-limits API requests based on the Sanctum token's client type.
 *
 * Token names are formatted as "client_type|device_name" (set in LoginController).
 * The client type prefix determines the rate limit tier.
 *
 * Portal tokens: 120 requests/minute
 * Mobile tokens (cellar_app, pos_app): 60 requests/minute
 * Widget tokens: 30 requests/minute
 * Public API tokens: 60 requests/minute
 * Unauthenticated (fallback by IP): 30 requests/minute
 *
 * Rate limit headers are always included:
 *   X-RateLimit-Limit, X-RateLimit-Remaining, Retry-After (on 429)
 */
class ThrottleByTokenType
{
    /**
     * Rate limits per client type (requests per minute).
     *
     * @var array<string, int>
     */
    public const LIMITS = [
        'portal' => 120,
        'cellar_app' => 60,
        'pos_app' => 60,
        'widget' => 30,
        'public_api' => 60,
    ];

    /**
     * Default limit for unauthenticated or unknown client types.
     */
    public const DEFAULT_LIMIT = 30;

    /**
     * Decay time in seconds (1 minute).
     */
    private const DECAY_SECONDS = 60;

    public function __construct(private readonly RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->resolveKey($request);
        $maxAttempts = $this->resolveMaxAttempts($request);

        if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
            return $this->buildTooManyAttemptsResponse($key, $maxAttempts);
        }

        $this->limiter->hit($key, self::DECAY_SECONDS);

        $response = $next($request);

        return $this->addRateLimitHeaders($response, $key, $maxAttempts);
    }

    /**
     * Build a unique throttle key from the user's token or IP.
     */
    private function resolveKey(Request $request): string
    {
        $user = $request->user();

        if ($user) {
            $clientType = $this->resolveClientType($request);

            // Per-user throttle: tenant + user + client type
            return sprintf(
                'throttle:%s:%s:%s',
                tenant('id') ?? 'central',
                $user->id,
                $clientType,
            );
        }

        // Unauthenticated — throttle by IP
        return 'throttle:ip:'.($request->ip() ?? 'unknown');
    }

    /**
     * Extract the client type from the Sanctum token name.
     *
     * Token names are formatted as "client_type|device_name" by LoginController.
     * Falls back to 'unknown' if the token name doesn't follow this format.
     */
    private function resolveClientType(Request $request): string
    {
        $user = $request->user();

        if ($user === null) {
            return 'unknown';
        }

        $tokenName = $user->currentAccessToken()->name ?? '';

        // Token name format: "client_type|device_name"
        $clientType = explode('|', $tokenName, 2)[0];

        if (array_key_exists($clientType, self::LIMITS)) {
            return $clientType;
        }

        return 'unknown';
    }

    /**
     * Resolve the max attempts per minute for the current request.
     */
    private function resolveMaxAttempts(Request $request): int
    {
        $clientType = $this->resolveClientType($request);

        return self::LIMITS[$clientType] ?? self::DEFAULT_LIMIT;
    }

    /**
     * Add rate limit headers to the response.
     */
    private function addRateLimitHeaders(Response $response, string $key, int $maxAttempts): Response
    {
        $remaining = $this->limiter->remaining($key, $maxAttempts);

        $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, $remaining));

        return $response;
    }

    /**
     * Return a 429 response in the API envelope format.
     */
    private function buildTooManyAttemptsResponse(string $key, int $maxAttempts): Response
    {
        $retryAfter = $this->limiter->availableIn($key);

        $response = ApiResponse::error('Too many requests. Please try again later.', 429);

        $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', '0');
        $response->headers->set('Retry-After', (string) $retryAfter);

        return $response;
    }
}
