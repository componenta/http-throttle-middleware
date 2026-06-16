<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Throttle;

use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 cache-based fixed window rate limiter.
 *
 * Stores hit counts in any PSR-16 (Simple Cache) compatible backend:
 * Redis, Memcached, APCu, database, filesystem, etc.
 *
 * Each rate limit key maps to a cache entry containing the hit count.
 * The cache TTL is set to the window duration, so the entry expires
 * automatically when the window resets - no manual garbage collection
 * is needed.
 *
 * Note on atomicity: PSR-16 does not provide atomic increment operations.
 * Under high concurrency, a small number of extra requests may slip
 * through during the get->increment->set gap (TOCTOU race). This is an
 * acceptable trade-off for most applications. For strict atomicity,
 * use a backend-specific implementation (e.g., Redis INCR).
 *
 * @see https://www.php-fig.org/psr/psr-16/
 * @see https://datatracker.ietf.org/doc/draft-ietf-httpapi-ratelimit-headers/
 */
final readonly class CacheRateLimiter implements RateLimiterInterface
{
    /**
     * Cache key prefix to avoid collisions with other cache users.
     */
    private const string KEY_PREFIX = 'rl:';

    /**
     * @param CacheInterface $cache  PSR-16 cache backend
     * @param string         $prefix Optional additional prefix for key namespacing.
     *                               Useful when multiple rate limiters share the
     *                               same cache pool (e.g., "api:", "login:").
     */
    public function __construct(
        private CacheInterface $cache,
        private string $prefix = '',
    ) {}

    #[\Override]
    public function hit(string $key, int $limit, int $window): RateLimitResult
    {
        $now = time();
        $cacheKey = self::KEY_PREFIX . $this->prefix . $key;

        /** @var array{count: int, windowStart: int}|null $bucket */
        $bucket = $this->cache->get($cacheKey);

        // Reset bucket if missing, corrupted, or window expired
        if (
            !is_array($bucket)
            || !isset($bucket['count'], $bucket['windowStart'])
            || ($now - $bucket['windowStart']) >= $window
        ) {
            $bucket = [
                'count' => 0,
                'windowStart' => $now,
            ];
        }

        $bucket['count']++;

        // TTL = remaining time in the current window
        $elapsed = $now - $bucket['windowStart'];
        $ttl = max(1, $window - $elapsed);

        $this->cache->set($cacheKey, $bucket, $ttl);

        $retryAfter = $window - $elapsed;

        if ($bucket['count'] > $limit) {
            return new RateLimitResult(
                allowed: false,
                limit: $limit,
                remaining: 0,
                retryAfter: $retryAfter,
            );
        }

        return new RateLimitResult(
            allowed: true,
            limit: $limit,
            remaining: $limit - $bucket['count'],
            retryAfter: $retryAfter,
        );
    }
}
