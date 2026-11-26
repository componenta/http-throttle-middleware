<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Throttle\Tests;

use Componenta\Http\Middleware\Throttle\RateLimiterInterface;
use Componenta\Http\Middleware\Throttle\RateLimitResult;
use Componenta\Http\Middleware\Throttle\ThrottleMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertSame('text/plain; charset=utf-8', $denied->getHeaderLine('Content-Type'));
        self::assertSame('1', $denied->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('0', $denied->getHeaderLine('X-RateLimit-Remaining'));
        self::assertSame('429 Too Many Requests', (string) $denied->getBody());
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

    public function testRoutePoliciesAndDefaultPolicyAreIndependent(): void
    {
        $factory = new Psr17Factory();
        $middleware = new ThrottleMiddleware($this->limiter(), $factory, limit: 1, policy: 'global');
        $handler = $this->handler();
        $request = $factory->createServerRequest('GET', '/', ['REMOTE_ADDR' => '192.0.2.1']);
        foreach ([$request, $request->withAttribute('rate_limit', ['policy' => 'a']), $request->withAttribute('rate_limit', ['policy' => 'b'])] as $variant) {
            $response = $middleware->process($variant, $handler);
            self::assertSame(204, $response->getStatusCode());
            self::assertSame('1', $response->getHeaderLine('X-RateLimit-Limit'));
            self::assertSame('0', $response->getHeaderLine('X-RateLimit-Remaining'));
            self::assertSame(429, $middleware->process($variant, $handler)->getStatusCode());
        }
        self::assertSame(3, $handler->calls);
    }

    public static function invalidOverrides(): iterable
    {
        foreach (['bad', ['limit' => '1'], ['window' => '60'], ['policy' => ''], ['policy' => 1], ['limit' => 0], ['window' => 0], ['limit' => null]] as $override) {
            yield [$override];
        }
    }

    #[DataProvider('invalidOverrides')]
    public function testInvalidRouteOverrideDoesNotConsumeQuota(mixed $override): void
    {
        $factory = new Psr17Factory();
        $limiter = $this->createMock(RateLimiterInterface::class);
        $limiter->expects(self::never())->method('hit');
        $middleware = new ThrottleMiddleware($limiter, $factory);
        $this->expectException(\InvalidArgumentException::class);
        $middleware->process($factory->createServerRequest('GET', '/')->withAttribute('rate_limit', $override), $this->handler());
    }

    public function testDefaultKeyPrefersTrustedAttributeAndNormalizesIpv6(): void
    {
        $factory = new Psr17Factory();
        $limiter = $this->createMock(RateLimiterInterface::class);
        $limiter->expects(self::once())->method('hit')->with('2001:db8::1', 60, 60)
            ->willReturn(new RateLimitResult(true, 60, 59, 60));
        $request = $factory->createServerRequest('GET', '/', ['REMOTE_ADDR' => '192.0.2.1'])
            ->withAttribute('client_ip', '2001:0db8:0:0:0:0:0:1');
        self::assertSame(204, (new ThrottleMiddleware($limiter, $factory))->process($request, $this->handler())->getStatusCode());
    }

    public function testEmptyConstructorPolicyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ThrottleMiddleware($this->limiter(), new Psr17Factory(), policy: '');
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
