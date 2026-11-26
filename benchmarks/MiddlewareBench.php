<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Throttle\Benchmarks;

use Componenta\Http\Middleware\Throttle\CacheRateLimiter;
use Componenta\Http\Middleware\Throttle\FileRateLimiter;
use Componenta\Http\Middleware\Throttle\ThrottleMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use PhpBench\Attributes as Bench;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\SimpleCache\CacheInterface;

#[Bench\BeforeMethods('setUp')]
#[Bench\AfterMethods('tearDown')]
#[Bench\Iterations(5)]
final class RateLimiterBench
{
    private string $directory;
    private CacheRateLimiter $cache;
    private FileRateLimiter $file;
    private ThrottleMiddleware $middleware;
    private ThrottleMiddleware $denied;
    private ServerRequestInterface $request;
    private RequestHandlerInterface $handler;

    public function setUp(): void
    {
        // Intentionally process-local fixture; measures limiter overhead rather than a Redis round trip.
        $cache = new class implements CacheInterface {
            private array $values = [];
            public function get(string $key, mixed $default = null): mixed { return $this->values[$key] ?? $default; }
            public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool { $this->values[$key] = $value; return true; }
            public function delete(string $key): bool { unset($this->values[$key]); return true; }
            public function clear(): bool { $this->values = []; return true; }
            public function getMultiple(iterable $keys, mixed $default = null): iterable { foreach ($keys as $key) { yield $key => $this->get($key, $default); } }
            public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool { foreach ($values as $key => $value) { $this->set($key, $value, $ttl); } return true; }
            public function deleteMultiple(iterable $keys): bool { foreach ($keys as $key) { $this->delete($key); } return true; }
            public function has(string $key): bool { return array_key_exists($key, $this->values); }
        };
        $clock = static fn(): int => 100;
        $this->cache = new CacheRateLimiter($cache, clock: $clock);
        $this->directory = sys_get_temp_dir() . '/componenta-bench-' . bin2hex(random_bytes(8));
        if (!mkdir($this->directory, 0700)) { throw new \RuntimeException('Cannot create benchmark directory.'); }
        $this->file = new FileRateLimiter($this->directory, $clock);
        $this->cache->hit('denied', 1, 60);
        $this->file->hit('denied', 1, 60);
        $factory = new Psr17Factory();
        $this->request = $factory->createServerRequest('GET', '/', ['REMOTE_ADDR' => '192.0.2.10']);
        $this->handler = new class($factory->createResponse(204)) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}
            public function handle(ServerRequestInterface $request): ResponseInterface { return $this->response; }
        };
        $this->middleware = new ThrottleMiddleware($this->cache, $factory, limit: PHP_INT_MAX);
        $this->denied = new ThrottleMiddleware($this->cache, $factory, limit: 1, keyResolver: static fn(): string => 'denied');
    }

    #[Bench\Revs(1000)]
    public function benchCacheAllowed(): void { $this->cache->hit('allowed', PHP_INT_MAX, 60); }
    #[Bench\Revs(1000)]
    public function benchCacheDenied(): void { $this->cache->hit('denied', 1, 60); }
    #[Bench\Revs(100)]
    public function benchFileAllowed(): void { $this->file->hit('allowed', PHP_INT_MAX, 60); }
    #[Bench\Revs(100)]
    public function benchFileDenied(): void { $this->file->hit('denied', 1, 60); }
    #[Bench\Revs(1000)]
    public function benchMiddlewareAllowed(): void { $this->middleware->process($this->request, $this->handler); }
    #[Bench\Revs(1000)]
    public function benchMiddlewareDenied(): void { $this->denied->process($this->request, $this->handler); }

    public function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }
}
