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

`CacheRateLimiter` uses portable 64-character hashed PSR-16 keys. It is approximate: concurrent requests can exceed the limit because PSR-16 lacks atomic read-modify-write operations. Do not use it as a strict security boundary. Use an atomic backend for strict enforcement.

`FileRateLimiter` stores buckets in a private local directory with stable per-shard locks and atomic replacement. Storage failures throw instead of allowing the request. Expired files are pruned using their stored expiry by explicit maintenance. The request path never scans the directory. A relative directory is resolved once at construction. Do not share the directory with untrusted users or use a filesystem without reliable flock and rename semantics.

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

Per-route overrides can be passed through the `rate_limit` request attribute with positive integer `limit` and `window` keys and an optional non-empty string `policy` identifying the route quota. Use distinct policies for independently limited routes with identical settings.

```php
$request = $request->withAttribute(ThrottleMiddleware::ATTR_RATE_LIMIT, [
    'policy' => 'login',
    'limit' => 10,
    'window' => 60,
]);
```

Successful responses receive `X-RateLimit-Limit` and `X-RateLimit-Remaining` headers. Rejected responses have status `429`, body `429 Too Many Requests`, and headers `Retry-After`, `X-RateLimit-Limit`, and `X-RateLimit-Remaining: 0`.

## Migration and failure handling

This update changes the bucket storage format and cache keys, resetting old quotas. Deploy consistently across workers; retire old files separately. Configure an application error handler for `RateLimitStorageException`; choose any fail-open behavior explicitly at the application boundary. `X-RateLimit-*` are legacy fields, not the IETF RateLimit draft. Responses with status 429 include `Cache-Control: no-store`.

Run tests with `composer test`.

Set the optional middleware `policy:` name when independent middleware instances share a limiter and have identical limit/window settings. Route policy identifiers are nested under that name. A custom key resolver must return a non-empty string. IPv4 and IPv4-mapped IPv6 client addresses share a quota.

## File storage maintenance

Schedule `$limiter->collectExpired()` periodically in a separate worker or CLI job. The method returns the number of expired bucket files removed, cleans abandoned temporary writes, never deletes stable lock files, and skips busy entries. It is not called automatically by `hit()`. Configure storage capacity and monitoring according to client/key cardinality. Do not put personalized quota headers into a shared response cache, and run enforcement before any cache hit that must count against the quota.
