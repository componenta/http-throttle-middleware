# Componenta HTTP Throttle Middleware

PSR-15 fixed-window rate limiting middleware. It limits requests per key and adds rate limit headers to successful responses or returns `429 Too Many Requests`.

Use this package when an HTTP pipeline needs a simple fixed-window request limit by client IP, user id, API key, or another application-defined key.

## Boundary

This package only enforces request rate limits. It does not authenticate users, resolve trusted proxy headers, or provide a global config provider. If the application runs behind a proxy, place `componenta/http-trusted-proxy-middleware` before this middleware so the `client_ip` request attribute is available.

## Installation

```bash
composer require componenta/http-throttle-middleware
```

This package has no config provider. Register a limiter and middleware explicitly.

## Limiters

| Class | Storage |
|---|---|
| `CacheRateLimiter` | PSR-16 cache. |
| `FileRateLimiter` | Filesystem directory. |

Both implement `RateLimiterInterface::hit(string $key, int $limit, int $window): RateLimitResult`.

`CacheRateLimiter` works with any PSR-16 cache, but PSR-16 does not provide atomic increments. Under high concurrency a few extra requests can pass through between read and write. Use a backend-specific limiter if strict atomicity is required.

`FileRateLimiter` stores one lock-protected file per key in a writable directory. If it cannot open or lock a file, it fails open and allows the request instead of breaking the application request.

`RateLimitResult` contains:

| Property | Meaning |
|---|---|
| `allowed` | Whether the request may continue. |
| `limit` | Maximum requests in the current window. |
| `remaining` | Remaining requests in the current window. |
| `retryAfter` | Seconds until the window resets. |

## Middleware

```php
use Componenta\Http\Middleware\Throttle\ThrottleMiddleware;

$middleware = new ThrottleMiddleware(
    limiter: $limiter,
    responseFactory: $responseFactory,
    limit: 60,
    window: 60,
);
```

By default the key is `client_ip` request attribute or `REMOTE_ADDR`. A custom key resolver can use user id, API key, or another identifier.

Per-route overrides can be passed through the `rate_limit` request attribute with `limit` and `window` keys.

```php
$request = $request->withAttribute(ThrottleMiddleware::ATTR_RATE_LIMIT, [
    'limit' => 10,
    'window' => 60,
]);
```

Successful responses receive `X-RateLimit-Limit` and `X-RateLimit-Remaining` headers. Rejected responses have status `429`, body `429 Too Many Requests`, and headers `Retry-After`, `X-RateLimit-Limit`, and `X-RateLimit-Remaining: 0`.
