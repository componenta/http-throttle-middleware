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
}
