<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Throttle;

use const DIRECTORY_SEPARATOR;

/**
 * File-based fixed window rate limiter.
 *
 * Stores hit counts in individual files on the filesystem, one file per
 * rate limit key. This provides shared state across PHP processes without
 * external dependencies (Redis, APCu, etc.).
 *
 * Each file contains a single line: "{windowStart}:{count}".
 *
 * File locking (LOCK_EX) is used to prevent race conditions when multiple
 * processes access the same key concurrently. The lock is held for the
 * minimal duration required to read-increment-write.
 *
 * Stale files (expired windows) are lazily pruned using a probabilistic
 * strategy to avoid scanning the entire directory on every request.
 *
 * Fixed window algorithm: requests are counted within discrete time
 * windows of `$window` seconds. When a new window begins, the counter
 * resets to zero.
 *
 * @see https://datatracker.ietf.org/doc/draft-ietf-httpapi-ratelimit-headers/
 */
final class FileRateLimiter implements RateLimiterInterface
{
    /**
     * File prefix to identify rate limiter files during pruning.
     */
    private const string FILE_PREFIX = 'rl_';

    /**
     * Probability of triggering garbage collection (1 in N requests).
     *
     * On average, GC runs once per 100 requests. This amortizes the cost
     * of directory scanning across many requests.
     */
    private const int GC_DIVISOR = 100;

    /**
     * Maximum file age in seconds before GC considers it stale.
     *
     * Set to 2 hours - well above any typical rate limit window.
     * This avoids depending on the current request's $window value,
     * which may differ per route.
     */
    private const int GC_MAX_AGE = 7200;

    /**
     * @param string        $directory Writable directory for rate limit files.
     *                                 Must exist and be writable by the PHP process.
     * @param \Closure|null $clock     Optional clock function for testing.
     *                                 Returns current Unix timestamp.
     */
    public function __construct(
        private readonly string $directory,
        private readonly ?\Closure $clock = null,
    ) {}

    #[\Override]
    public function hit(string $key, int $limit, int $window): RateLimitResult
    {
        $now = $this->now();
        $file = $this->filePath($key);

        // Probabilistic garbage collection
        if (random_int(1, self::GC_DIVISOR) === 1) {
            $this->prune($now);
        }

        $handle = fopen($file, 'c+');

        if ($handle === false) {
            // Cannot open file - fail open (allow request)
            return new RateLimitResult(
                allowed: true,
                limit: $limit,
                remaining: $limit - 1,
                retryAfter: $window,
            );
        }

        $handle = $this->lockFile($file, $handle);

        if ($handle === false) {
            return new RateLimitResult(
                allowed: true,
                limit: $limit,
                remaining: $limit - 1,
                retryAfter: $window,
            );
        }

        try {
            $content = stream_get_contents($handle);
            [$windowStart, $count] = $this->parse($content, $now);

            // Window expired - reset
            if (($now - $windowStart) >= $window) {
                $windowStart = $now;
                $count = 0;
            }

            $count++;

            // Write back
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, "$windowStart:$count");
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $retryAfter = $window - ($now - $windowStart);

        if ($count > $limit) {
            return new RateLimitResult(
                allowed: false,
                limit: $limit,
                remaining: 0,
                retryAfter: $retryAfter,
            );
        }

        return new RateLimitResult(
            allowed: true,
            limit: $limit,
            remaining: $limit - $count,
            retryAfter: $retryAfter,
        );
    }

    /**
     * Acquires an exclusive lock on the file, handling concurrent deletion.
     *
     * After acquiring LOCK_EX, checks if the file still exists on disk.
     * A concurrent prune() may have unlinked the file between fopen() and
     * flock(). On Linux, the handle would point to a detached inode -
     * writes would succeed but data is lost on close.
     *
     * If the file was deleted, releases the stale handle, recreates the
     * file, and locks the new handle.
     *
     * @param string   $file   File path
     * @param resource $handle Open file handle
     *
     * @return resource|false Locked handle, or false on failure
     */
    private function lockFile(string $file, mixed $handle): mixed
    {
        flock($handle, LOCK_EX);

        if (file_exists($file)) {
            return $handle;
        }

        // File was unlinked - release stale handle and recreate
        flock($handle, LOCK_UN);
        fclose($handle);

        $handle = fopen($file, 'c+');

        if ($handle === false) {
            return false;
        }

        flock($handle, LOCK_EX);

        return $handle;
    }

    /**
     * Parses file content into [windowStart, count].
     *
     * Returns [$now, 0] if content is missing or corrupted,
     * effectively starting a fresh window.
     *
     * @return array{0: int, 1: int}
     */
    private function parse(string|false $content, int $now): array
    {
        if ($content === false || $content === '') {
            return [$now, 0];
        }

        $parts = explode(':', $content, 2);

        if (count($parts) !== 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
            return [$now, 0];
        }

        return [(int) $parts[0], (int) $parts[1]];
    }

    /**
     * Generates a filesystem-safe path for the given key.
     *
     * Keys are hashed with xxh128 to ensure consistent length and
     * avoid filesystem character restrictions.
     */
    private function filePath(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . self::FILE_PREFIX . hash('xxh128', $key);
    }

    /**
     * Removes stale rate limit files.
     *
     * A file is considered stale if its modification time exceeds
     * {@see GC_MAX_AGE} seconds. This is a fixed threshold independent
     * of the per-request $window value, which may vary across routes.
     *
     * Each file is locked (LOCK_EX) before deletion to prevent removing
     * a file that another process is currently reading/writing. On Linux,
     * unlink() without a lock would remove the directory entry while the
     * other process still holds the inode - causing data loss when the
     * handle is closed.
     */
    private function prune(int $now): void
    {
        $threshold = $now - self::GC_MAX_AGE;
        $pattern = $this->directory . DIRECTORY_SEPARATOR . self::FILE_PREFIX . '*';

        foreach (glob($pattern) as $file) {
            $handle = @fopen($file, 'c+');

            if ($handle === false) {
                continue;
            }

            try {
                // Non-blocking lock - skip if another process is using the file
                if (!flock($handle, LOCK_EX | LOCK_NB)) {
                    continue;
                }

                // Re-check mtime after acquiring lock - file may have been
                // updated between glob() and flock()
                $mtime = filemtime($file);

                if ($mtime !== false && $mtime < $threshold) {
                    @unlink($file);
                }
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    private function now(): int
    {
        if ($this->clock !== null) {
            return ($this->clock)();
        }

        return time();
    }
}
