<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Throttle\Tests;

use Componenta\Http\Middleware\Throttle\CacheRateLimiter;
use Componenta\Http\Middleware\Throttle\FileRateLimiter;
use Componenta\Http\Middleware\Throttle\RateLimitStorageException;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

final class RateLimiterTest extends TestCase
{
    public function testCacheKeysArePsr16PortableAndFailedWritesAreNotAllowed(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::once())->method('get')->with(self::matchesRegularExpression('/^[0-9a-f]{64}$/D'))->willReturn(null);
        $cache->expects(self::once())->method('set')->with(
            self::matchesRegularExpression('/^[0-9a-f]{64}$/D'), ['expires' => 160, 'count' => 1], 60,
        )->willReturn(false);
        $limiter = new CacheRateLimiter($cache, 'route:', static fn(): int => 100);
        $this->expectException(RateLimitStorageException::class);
        $limiter->hit('2001:db8::1', 2, 60);
    }

    public function testFileWindowPersistsAndExpiresAndPoliciesAreIsolated(): void
    {
        $directory = sys_get_temp_dir() . '/componenta-rate-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $now = 100;
        try {
            $clock = static function () use (&$now): int { return $now; };
            $limiter = new FileRateLimiter($directory, $clock);
            self::assertTrue($limiter->hit('client', 2, 60)->allowed);
            self::assertSame(0, (new FileRateLimiter($directory, $clock))->hit('client', 2, 60)->remaining);
            self::assertFalse($limiter->hit('client', 2, 60)->allowed);
            self::assertTrue($limiter->hit('client', 2, 120)->allowed);
            $now = 159;
            self::assertSame(1, $limiter->hit('client', 2, 60)->retryAfter);
            $now = 160;
            self::assertTrue($limiter->hit('client', 2, 60)->allowed);
            $this->expectException(\InvalidArgumentException::class);
            $limiter->hit('client', 2, 0);
        } finally {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($directory);
        }
    }

    public function testCacheDenialDoesNotRewriteOrExtendAnActiveBucket(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::once())->method('get')->willReturn(['expires' => 160, 'count' => 2]);
        $cache->expects(self::never())->method('set');
        $result = (new CacheRateLimiter($cache, clock: static fn(): int => 159))->hit('client', 2, 60);
        self::assertFalse($result->allowed);
        self::assertSame(0, $result->remaining);
        self::assertSame(1, $result->retryAfter);
    }

    public function testCacheExpiredBucketResetsAndMalformedBucketFailsClosed(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::exactly(2))->method('get')->willReturnOnConsecutiveCalls(
            ['expires' => 100, 'count' => 3],
            ['expires' => '160', 'count' => 0],
        );
        $cache->expects(self::once())->method('set')->with(
            self::matchesRegularExpression('/^[0-9a-f]{64}$/D'), ['expires' => 160, 'count' => 1], 60,
        )->willReturn(true);
        $limiter = new CacheRateLimiter($cache, clock: static fn(): int => 100);
        self::assertSame(2, $limiter->hit('client', 3, 60)->remaining);
        $this->expectException(RateLimitStorageException::class);
        $limiter->hit('client', 3, 60);
    }

    public function testExplicitCollectionRemovesOnlyExpiredBuckets(): void
    {
        $directory = sys_get_temp_dir() . '/componenta-rate-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $now = 100;
        try {
            $clock = static function () use (&$now): int { return $now; };
            $limiter = new FileRateLimiter($directory, $clock);
            self::assertTrue($limiter->hit('short', 1, 60)->allowed);
            self::assertTrue($limiter->hit('long', 1, 86400)->allowed);
            self::assertSame(0, $limiter->collectExpired());
            $now = 160;
            self::assertSame(1, $limiter->collectExpired());
            self::assertSame(0, $limiter->collectExpired());
            self::assertFalse($limiter->hit('long', 1, 86400)->allowed);
            self::assertTrue($limiter->hit('short', 1, 60)->allowed);
        } finally {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($directory);
        }
    }

    public function testRelativeStoragePathIsBoundAtConstruction(): void
    {
        $directory = sys_get_temp_dir() . '/componenta-rate-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $cwd = getcwd();
        self::assertIsString($cwd);
        try {
            chdir(dirname($directory));
            $limiter = new FileRateLimiter(basename($directory), static fn(): int => 100);
            self::assertTrue($limiter->hit('client', 1, 60)->allowed);
            chdir($cwd);
            self::assertFalse($limiter->hit('client', 1, 60)->allowed);
        } finally {
            chdir($cwd);
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($directory);
        }
    }
}
