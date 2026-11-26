<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Throttle\Tests;

use Componenta\Http\Middleware\Throttle\RateLimiterInterface;
use Componenta\Http\Middleware\Throttle\RateLimitResult;
use Componenta\Http\Middleware\Throttle\ThrottleMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ThrottleMiddlewareTest extends TestCase
{
    public function testMappedAndOrdinaryIpv4ShareQuotaAndDeniedRequestSkipsHandler(): void
    {
        $factory = new Psr17Factory();
        $limiter = $this->limiter();
        $middleware = new ThrottleMiddleware($limiter, $factory, limit: 1, window: 60);
        $handler = $this->handler();
        $first = $factory->createServerRequest('GET', '/', ['REMOTE_ADDR' => '192.0.2.1']);
        $second = $factory->createServerRequest('GET', '/', ['REMOTE_ADDR' => '::ffff:c000:201']);
        self::assertSame(204, $middleware->process($first, $handler)->getStatusCode());
        $denied = $middleware->process($second, $handler);
        self::assertSame(429, $denied->getStatusCode());
        self::assertSame('no-store', $denied->getHeaderLine('Cache-Control'));
        self::assertSame('60', $denied->getHeaderLine('Retry-After'));
        self::assertSame(1, $handler->calls);
    }

    public function testIndependentNamedPoliciesDoNotShareQuota(): void
    {
        $factory = new Psr17Factory();
        $limiter = $this->limiter();
        $handler = $this->handler();
        $a = new ThrottleMiddleware($limiter, $factory, limit: 1, policy: 'a');
        $b = new ThrottleMiddleware($limiter, $factory, limit: 1, policy: 'b');
        $request = $factory->createServerRequest('GET', '/', ['REMOTE_ADDR' => '192.0.2.1']);
        self::assertSame(204, $a->process($request, $handler)->getStatusCode());
        self::assertSame(204, $b->process($request, $handler)->getStatusCode());
        self::assertSame(429, $a->process($request, $handler)->getStatusCode());
    }

    public function testEmptyResolverKeyIsRejected(): void
    {
        $factory = new Psr17Factory();
        $middleware = new ThrottleMiddleware($this->limiter(), $factory, keyResolver: static fn(): string => '');
        $this->expectException(\UnexpectedValueException::class);
        $middleware->process($factory->createServerRequest('GET', '/'), $this->handler());
    }

    private function limiter(): RateLimiterInterface
    {
        return new class implements RateLimiterInterface {
            /** @var array<string, int> */
            private array $counts = [];
            public function hit(string $key, int $limit, int $window): RateLimitResult
            {
                $count = $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
                return new RateLimitResult($count <= $limit, $limit, max(0, $limit - $count), $window);
            }
        };
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public int $calls = 0;
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ++$this->calls;
                return (new Psr17Factory())->createResponse(204);
            }
        };
    }
}
