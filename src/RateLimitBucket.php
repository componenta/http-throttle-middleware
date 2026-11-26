<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Throttle;

/** @internal */
final readonly class RateLimitBucket
{
    public function __construct(public int $expires, public int $count)
    {
        if ($expires < 1 || $count < 0) {
            throw new RateLimitStorageException('Invalid stored rate limit bucket.');
        }
    }

    public static function validatePolicy(int $limit, int $window): void
    {
        if ($limit < 1 || $window < 1) {
            throw new \InvalidArgumentException('Rate limit and window must be positive integers.');
        }
    }

    public static function identity(string $namespace, string $key, int $limit, int $window): string
    {
        return hash('sha256', serialize([$namespace, $key, $limit, $window]));
    }

    public static function fresh(int $now, int $window): self
    {
        if ($now < 1 || $window > PHP_INT_MAX - $now) {
            throw new \InvalidArgumentException('Invalid rate limit time or window.');
        }
        return new self($now + $window, 0);
    }

    public function consume(int $limit, int $now): array
    {
        if ($this->count > $limit || $this->expires <= $now) {
            throw new RateLimitStorageException('Invalid active rate limit bucket.');
        }
        $allowed = $this->count < $limit;
        $next = $allowed ? new self($this->expires, $this->count + 1) : $this;
        return [$next, new RateLimitResult($allowed, $limit, $limit - $next->count, $this->expires - $now)];
    }
}
