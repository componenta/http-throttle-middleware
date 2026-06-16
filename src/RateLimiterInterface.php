<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Throttle;

/**
 * Rate limiter contract.
 *
 * Implementations track request counts per key within a time window
 * and determine whether the current request should be allowed.
 *
 * The key typically identifies the client (e.g., IP address, user ID,
 * or a combination). The window defines the time period over which
 * the limit applies.
 */
interface RateLimiterInterface
{
    /**
     * Attempts to consume one token from the rate limit bucket.
     *
     * Returns a result indicating whether the request is allowed and
     * metadata for constructing rate limit response headers.
     *
     * @param string $key    Identifier for the rate limit bucket
     * @param int    $limit  Maximum number of requests per window
     * @param int    $window Time window in seconds
     */
    public function hit(string $key, int $limit, int $window): RateLimitResult;
}
