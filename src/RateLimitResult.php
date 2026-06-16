<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Throttle;

/**
 * Result of a rate limit check.
 *
 * Contains all information needed to construct IETF rate limit headers
 * per the RateLimit Fields draft (draft-ietf-httpapi-ratelimit-headers).
 *
 * @see https://datatracker.ietf.org/doc/draft-ietf-httpapi-ratelimit-headers/
 */
final readonly class RateLimitResult
{
    /**
     * @param bool $allowed    Whether the request is permitted
     * @param int  $limit      Maximum requests per window
     * @param int  $remaining  Remaining requests in current window
     * @param int  $retryAfter Seconds until the window resets (for 429 responses)
     */
    public function __construct(
        public bool $allowed,
        public int $limit,
        public int $remaining,
        public int $retryAfter,
    ) {}
}
