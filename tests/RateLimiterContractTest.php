<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Throttle\Tests;

use Componenta\Http\Middleware\Throttle\CacheRateLimiter;
use Componenta\Http\Middleware\Throttle\FileRateLimiter;
use Componenta\Http\Middleware\Throttle\RateLimitResult;
use Componenta\Http\Middleware\Throttle\RateLimitStorageException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

final class RateLimiterContractTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/componenta-contract-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->directory);
    }

    public static function invalidPolicies(): iterable
    {
        foreach ([[0, 1], [-1, 1], [1, 0], [1, -1]] as $policy) {
            yield $policy;
        }
    }

    #[DataProvider('invalidPolicies')]
    public function testCacheRejectsInvalidPolicyBeforeStorage(int $limit, int $window): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::never())->method('get');
        $cache->expects(self::never())->method('set');
        $this->expectException(\InvalidArgumentException::class);
        (new CacheRateLimiter($cache))->hit('client', $limit, $window);
    }

    #[DataProvider('invalidPolicies')]
    public function testFileRejectsInvalidPolicy(int $limit, int $window): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FileRateLimiter($this->directory))->hit('client', $limit, $window);
    }

    public static function invalidClocks(): iterable
    {
        foreach ([0, -1, '100', 1.5, null, false] as $value) {
            yield [$value];
        }
    }

    #[DataProvider('invalidClocks')]
    public function testCacheRejectsInvalidClock(mixed $value): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::never())->method('get');
        $this->expectException(\UnexpectedValueException::class);
        (new CacheRateLimiter($cache, clock: static fn() => $value))->hit('client', 1, 1);
    }

    #[DataProvider('invalidClocks')]
    public function testFileRejectsInvalidClock(mixed $value): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new FileRateLimiter($this->directory, static fn() => $value))->hit('client', 1, 1);
    }

    public function testMinimumTimestampAndWindowAreAcceptedAndExactExpiryResetsBothBackends(): void
    {
        $now = 1;
        $clock = static function () use (&$now): int { return $now; };
        foreach ([new FileRateLimiter($this->directory, $clock), new CacheRateLimiter($this->memoryCache(), clock: $clock)] as $limiter) {
            $now = 1;
            $first = $limiter->hit('client', 1, 1);
            self::assertTrue($first->allowed);
            self::assertSame(0, $first->remaining);
            self::assertSame(1, $first->retryAfter);
            self::assertFalse($limiter->hit('client', 1, 1)->allowed);
            $now = 2;
            self::assertTrue($limiter->hit('client', 1, 1)->allowed);
        }
    }

    public function testCacheNamespaceKeyAndPolicyAreIndependentlyIsolated(): void
    {
        $cache = $this->memoryCache();
        $clock = static fn(): int => 100;
        $a = new CacheRateLimiter($cache, 'a:', $clock);
        $b = new CacheRateLimiter($cache, 'b:', $clock);
        self::assertTrue($a->hit('client', 1, 60)->allowed);
        self::assertFalse((new CacheRateLimiter($cache, 'a:', $clock))->hit('client', 1, 60)->allowed);
        self::assertTrue($b->hit('client', 1, 60)->allowed);
        self::assertTrue($a->hit('another', 1, 60)->allowed);
        self::assertTrue($a->hit('client', 1, 61)->allowed);
        self::assertSame(1, $a->hit('client', 2, 60)->remaining);
    }

    public static function invalidBuckets(): iterable
    {
        yield ['corrupt'];
        yield [[]];
        yield [['expires' => 160]];
        yield [['expires' => 160, 'count' => '1']];
        yield [['expires' => 0, 'count' => 1]];
        yield [['expires' => 160, 'count' => -1]];
        yield [['expires' => 160, 'count' => 3]];
    }

    #[DataProvider('invalidBuckets')]
    public function testInvalidCacheBucketNeverAllowsOrWrites(mixed $bucket): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn($bucket);
        $cache->expects(self::never())->method('set');
        $this->expectException(RateLimitStorageException::class);
        (new CacheRateLimiter($cache, clock: static fn(): int => 100))->hit('client', 2, 60);
    }

    public function testFileCorruptionFailsClosedAndMaintenanceDoesNotResetIt(): void
    {
        $limiter = new FileRateLimiter($this->directory, static fn(): int => 100);
        $limiter->hit('client', 1, 60);
        $files = glob($this->directory . '/rl-v2-*/*.bucket');
        self::assertIsArray($files);
        self::assertCount(1, $files);
        file_put_contents($files[0], 'invalid');
        self::assertSame(0, $limiter->collectExpired());
        self::assertFileExists($files[0]);
        $this->expectException(RateLimitStorageException::class);
        $limiter->hit('client', 1, 60);
    }

    public function testMaintenanceSkipsBusyShardAndRemovesAbandonedTemporaryFiles(): void
    {
        $now = 100;
        $clock = static function () use (&$now): int { return $now; };
        $limiter = new FileRateLimiter($this->directory, $clock);
        $limiter->hit('client', 1, 60);
        $files = glob($this->directory . '/rl-v2-*/*.bucket');
        self::assertIsArray($files);
        self::assertCount(1, $files);
        $shard = dirname($files[0]);
        $temporary = $shard . '/.rl-abandoned';
        file_put_contents($temporary, 'old temporary data');
        $lock = fopen($shard . '/.lock', 'c+b');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX));
        try {
            $now = 160;
            self::assertSame(0, $limiter->collectExpired());
            self::assertFileExists($temporary);
            self::assertFileExists($files[0]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        self::assertSame(1, $limiter->collectExpired());
        self::assertFileDoesNotExist($temporary);
        self::assertFileDoesNotExist($files[0]);
        self::assertFileExists($shard . '/.lock');
    }

    public function testFileStorageRejectsNonDirectoryAndMissingDirectory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FileRateLimiter($this->directory . '/missing');
    }

    public function testFileStorageRejectsRegularFileAsDirectory(): void
    {
        $path = $this->directory . '/file';
        file_put_contents($path, 'data');
        $this->expectException(\InvalidArgumentException::class);
        new FileRateLimiter($path);
    }

    public function testFileStorageCannotFollowBucketSymlink(): void
    {
        if (!function_exists('symlink') || PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Requires POSIX symlinks.');
        }
        $limiter = new FileRateLimiter($this->directory, static fn(): int => 100);
        $limiter->hit('client', 1, 60);
        $files = glob($this->directory . '/rl-v2-*/*.bucket');
        self::assertIsArray($files);
        self::assertCount(1, $files);
        $other = $this->directory . '/other';
        file_put_contents($other, '160:0');
        unlink($files[0]);
        self::assertTrue(symlink($other, $files[0]));
        try {
            $this->expectException(RateLimitStorageException::class);
            $limiter->hit('client', 1, 60);
        } finally {
            self::assertSame('160:0', file_get_contents($other));
        }
    }

    public static function invalidResults(): iterable
    {
        yield [true, 0, 0, 1];
        yield [true, 1, -1, 1];
        yield [true, 1, 2, 1];
        yield [true, 1, 0, -1];
        yield [false, 2, 1, 1];
    }

    #[DataProvider('invalidResults')]
    public function testResultInvariants(bool $allowed, int $limit, int $remaining, int $retryAfter): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RateLimitResult($allowed, $limit, $remaining, $retryAfter);
    }

    private function memoryCache(): CacheInterface
    {
        $values = [];
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturnCallback(static function (string $key, mixed $default = null) use (&$values): mixed {
            return $values[$key] ?? $default;
        });
        $cache->method('set')->willReturnCallback(static function (string $key, mixed $value) use (&$values): bool {
            $values[$key] = $value;
            return true;
        });
        return $cache;
    }
}
