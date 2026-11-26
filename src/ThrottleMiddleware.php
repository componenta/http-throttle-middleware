<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Throttle;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * HTTP request rate limiting middleware.
 *
 * Enforces a fixed window rate limit per client, identified by IP address.
 * When the limit is exceeded, the middleware short-circuits the pipeline
 * and returns a 429 Too Many Requests response per RFC 6585 §4.
 *
 * The middleware emits legacy X-RateLimit fields and standard Retry-After:
 *
 * - `X-RateLimit-Limit`:     Maximum requests per window
 * - `X-RateLimit-Remaining`: Remaining requests in current window
 * - `Retry-After`:           Seconds until the window resets (on 429 only,
 *                            per RFC 6585 §4 and RFC 9110 §10.2.3)
 *
 * The middleware accepts an optional key resolver to support custom
 * identification strategies (e.g., authenticated user ID, API key,
 * or composite keys).
 *
 * @see RFC 6585 §4            - 429 Too Many Requests
 * @see RFC 9110 §10.2.3       - Retry-After
 * @see draft-ietf-httpapi-ratelimit-headers - RateLimit header fields
 */
final class ThrottleMiddleware implements MiddlewareInterface
{
    /**
     * Request attribute name for overriding the rate limit per route.
     *
     * When set, the attribute value is expected to be an array with
     * 'limit' and/or 'window' keys. This allows per-route configuration
     * via route middleware or request attributes.
     */
    public const string ATTR_RATE_LIMIT = 'rate_limit';

    /**
     * @param RateLimiterInterface     $limiter         Rate limiter backend
     * @param ResponseFactoryInterface $responseFactory PSR-17 response factory
     * @param int                      $limit           Default max requests per window
     * @param int                      $window          Default window duration in seconds
     * @param \Closure|null            $keyResolver     Custom key resolver.
     *                                                  Receives ServerRequestInterface,
     *                                                  returns string key.
     *                                                  Default: client IP address.
     */
    public function __construct(
        private readonly RateLimiterInterface $limiter,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly int $limit = 60,
        private readonly int $window = 60,
        private readonly ?\Closure $keyResolver = null,
    ) {
        RateLimitBucket::validatePolicy($limit, $window);
    }

    #[\Override]
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $key = $this->resolveKey($request);
        [$limit, $window, $policy] = $this->resolveRateLimit($request);
        if ($policy !== null) {
            $key = RateLimitBucket::identity('policy:' . $policy, $key, $limit, $window);
        }

        $result = $this->limiter->hit($key, $limit, $window);

        if (!$result->allowed) {
            return $this->tooManyRequests($result);
        }

        $response = $handler->handle($request);

        return $this->addRateLimitHeaders($response, $result);
    }

    /**
     * Resolves the rate limit key for the request.
     *
     * Default strategy prefers the `client_ip` request attribute
     * (set by the trusted proxy middleware) over raw REMOTE_ADDR.
     * This ensures correct rate limiting behind reverse proxies
     * while remaining safe when no proxy middleware is configured.
     */
    private function resolveKey(ServerRequestInterface $request): string
    {
        if ($this->keyResolver !== null) {
            return ($this->keyResolver)($request);
        }

        $ip = $request->getAttribute('client_ip') ?? $request->getServerParams()['REMOTE_ADDR'] ?? null;
        if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return '0.0.0.0';
        }
        $binary = inet_pton($ip);
        return $binary === false ? '0.0.0.0' : (inet_ntop($binary) ?: '0.0.0.0');
    }

    /**
     * Resolves the effective rate limit for this request.
     *
     * Per-route overrides via request attributes take precedence
     * over the default configuration.
     *
     * @return array{int, int, ?string} [limit, window, policy]
     */
    private function resolveRateLimit(ServerRequestInterface $request): array
    {
        $override = $request->getAttribute(self::ATTR_RATE_LIMIT);

        if ($override === null) {
            return [$this->limit, $this->window, null];
        }
        if (!is_array($override)) {
            throw new \InvalidArgumentException('Rate limit override must be an array.');
        }
        $limit = array_key_exists('limit', $override) ? $override['limit'] : $this->limit;
        $window = array_key_exists('window', $override) ? $override['window'] : $this->window;
        $policy = array_key_exists('policy', $override) ? $override['policy'] : 'route-default';
        if (!is_int($limit) || !is_int($window) || !is_string($policy) || $policy === '') {
            throw new \InvalidArgumentException('Invalid rate limit override.');
        }
        RateLimitBucket::validatePolicy($limit, $window);
        return [$limit, $window, $policy];
    }

    /**
     * Creates a 429 Too Many Requests response.
     *
     * Per RFC 6585 §4, the server SHOULD send a Retry-After header
     * indicating how long the client should wait before retrying.
     *
     * Per RFC 9110 §10.2.3, the Retry-After value can be either an
     * HTTP-date or a delay in seconds. We use seconds for simplicity.
     *
     * @see RFC 6585 §4      - 429 Too Many Requests
     * @see RFC 9110 §10.2.3 - Retry-After
     */
    private function tooManyRequests(RateLimitResult $result): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(429);

        $response = $response
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Retry-After', (string) $result->retryAfter)
            ->withHeader('X-RateLimit-Limit', (string) $result->limit)
            ->withHeader('X-RateLimit-Remaining', '0');

        $response->getBody()->write('429 Too Many Requests');

        return $response;
    }

    /**
     * Adds rate limit headers to a successful response.
     *
     * These headers inform the client of their current rate limit status,
     * enabling adaptive behavior (e.g., backoff when remaining is low).
     */
    private function addRateLimitHeaders(
        ResponseInterface $response,
        RateLimitResult $result,
    ): ResponseInterface {
        return $response
            ->withHeader('X-RateLimit-Limit', (string) $result->limit)
            ->withHeader('X-RateLimit-Remaining', (string) $result->remaining);
    }
}
