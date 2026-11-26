<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Throttle;

use Psr\SimpleCache\CacheInterface;

/**
 * Approximate fixed-window rate limiter. PSR-16 has no atomic read-modify-write
 * operation: concurrent requests can exceed the limit. Do not use for a strict
 * security boundary. Storage failures are propagated, never reported as allowed.
 */
final readonly class CacheRateLimiter implements RateLimiterInterface
{
    public function __construct(
        private CacheInterface $cache,
        private string $prefix = '',
        private ?\Closure $clock = null,
    ) {}

    #[\Override]
    public function hit(string $key, int $limit, int $window): RateLimitResult
    {
        RateLimitBucket::validatePolicy($limit, $window);
        $now = $this->clock === null ? time() : ($this->clock)();
        if (!is_int($now) || $now < 1) {
            throw new \UnexpectedValueException('Rate limit clock must return a positive integer timestamp.');
        }
        $cacheKey = RateLimitBucket::identity('componenta-throttle:' . $this->prefix, $key, $limit, $window);
        $value = $this->cache->get($cacheKey);
        if ($value === null) {
            $bucket = RateLimitBucket::fresh($now, $window);
        } else {
            if (!is_array($value) || !isset($value['expires'], $value['count'])
                || !is_int($value['expires']) || !is_int($value['count'])) {
                throw new RateLimitStorageException('Invalid cached rate limit bucket.');
            }
            $bucket = new RateLimitBucket($value['expires'], $value['count']);
            if ($bucket->expires <= $now) {
                $bucket = RateLimitBucket::fresh($now, $window);
            }
        }
        [$next, $result] = $bucket->consume($limit, $now);
        if ($result->allowed && !$this->cache->set($cacheKey, ['expires' => $next->expires, 'count' => $next->count], $result->retryAfter)) {
            throw new RateLimitStorageException('Could not persist rate limit bucket.');
        }
        return $result;
    }
}
