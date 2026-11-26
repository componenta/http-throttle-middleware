<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Throttle;

/**
 * Atomic fixed-window limiter for cooperating processes on a local filesystem.
 * Stable per-shard locks are never removed; expired data files are pruned only
 * while holding the corresponding lock. The directory must be private.
 * Invoke collectExpired() from a maintenance process; hit() never scans it.
 */
final readonly class FileRateLimiter implements RateLimiterInterface
{
    private string $directory;

    public function __construct(
        string $directory,
        private ?\Closure $clock = null,
    ) {
        $resolved = realpath($directory);
        if ($resolved === false || !is_dir($resolved) || !is_writable($resolved)) {
            throw new \InvalidArgumentException('Rate limit directory must exist and be writable.');
        }
        $this->directory = $resolved;
    }

    #[\Override]
    public function hit(string $key, int $limit, int $window): RateLimitResult
    {
        RateLimitBucket::validatePolicy($limit, $window);
        $id = RateLimitBucket::identity('componenta-throttle-file-v2', $key, $limit, $window);
        $shard = $this->shard(substr($id, 0, 2));
        $lock = $this->lock($shard);
        try {
            $now = $this->now();
            $file = $shard . DIRECTORY_SEPARATOR . $id . '.bucket';
            clearstatcache(true, $file);
            if (is_link($file)) {
                throw new RateLimitStorageException('Rate limit bucket must not be a symbolic link.');
            }
            $bucket = is_file($file) ? $this->read($file) : RateLimitBucket::fresh($now, $window);
            if ($bucket->expires <= $now) {
                $bucket = RateLimitBucket::fresh($now, $window);
            }
            [$next, $result] = $bucket->consume($limit, $now);
            if ($result->allowed) {
                $this->write($file, $next);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $result;
    }

    private function now(): int
    {
        $now = $this->clock === null ? time() : ($this->clock)();
        if (!is_int($now) || $now < 1) {
            throw new \UnexpectedValueException('Rate limit clock must return a positive integer timestamp.');
        }
        return $now;
    }

    private function shard(string $id): string
    {
        $path = rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'rl-v2-' . $id;
        if (!is_dir($path) && !@mkdir($path, 0700) && !is_dir($path)) {
            throw new RateLimitStorageException('Could not create rate limit shard.');
        }
        if (is_link($path)) {
            throw new RateLimitStorageException('Rate limit shard must not be a symbolic link.');
        }
        return $path;
    }

    /** @return resource */
    private function lock(string $shard)
    {
        $handle = @fopen($shard . DIRECTORY_SEPARATOR . '.lock', 'c+b');
        if ($handle === false) {
            throw new RateLimitStorageException('Could not open rate limit lock.');
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new RateLimitStorageException('Could not acquire rate limit lock.');
        }
        return $handle;
    }

    private function read(string $file): RateLimitBucket
    {
        $data = @file_get_contents($file, false, null, 0, 64);
        if ($data === false || preg_match('/^([1-9][0-9]{0,18}):([0-9]{1,19})$/D', $data, $matches) !== 1) {
            throw new RateLimitStorageException('Could not read rate limit bucket.');
        }
        $expires = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $count = filter_var($matches[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($expires === false || $count === false) {
            throw new RateLimitStorageException('Invalid rate limit bucket values.');
        }
        return new RateLimitBucket($expires, $count);
    }

    private function write(string $file, RateLimitBucket $bucket): void
    {
        $directory = dirname($file);
        $temp = @tempnam($directory, '.rl-');
        if ($temp === false) {
            throw new RateLimitStorageException('Could not create temporary rate limit bucket.');
        }
        try {
            if (realpath(dirname($temp)) !== realpath($directory)) {
                throw new RateLimitStorageException('Temporary rate limit file must be in the bucket directory.');
            }
            $data = $bucket->expires . ':' . $bucket->count;
            if (@file_put_contents($temp, $data) !== strlen($data) || !@rename($temp, $file)) {
                throw new RateLimitStorageException('Could not persist rate limit bucket.');
            }
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    /**
     * Collect expired buckets and abandoned temporary writes outside the HTTP
     * hot path. Invoke periodically from a maintenance process. Busy buckets
     * are skipped and retried on a later collection. Returns the number of
     * expired bucket files removed (temporary files are not counted).
     */
    public function collectExpired(): int
    {
        $removed = 0;
        for ($i = 0; $i < 256; ++$i) {
            $directory = rtrim($this->directory, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . 'rl-v2-' . sprintf('%02x', $i);
            if (!is_dir($directory) || is_link($directory)) {
                continue;
            }
            $lock = @fopen($directory . DIRECTORY_SEPARATOR . '.lock', 'c+b');
            if ($lock === false) {
                throw new RateLimitStorageException('Could not open rate limit maintenance lock.');
            }
            try {
                // Iterate outside the lock; lock and re-check only one entry
                // at a time so maintenance cannot block an entire hot shard.
                foreach (new \DirectoryIterator($directory) as $entry) {
                    $name = $entry->getFilename();
                    $temporary = str_starts_with($name, '.rl-');
                    if (!$temporary && preg_match('/^[0-9a-f]{64}\.bucket$/D', $name) !== 1) {
                        continue;
                    }
                    if (!flock($lock, LOCK_EX | LOCK_NB)) {
                        continue;
                    }
                    try {
                        $file = $entry->getPathname();
                        clearstatcache(true, $file);
                        if (!is_file($file) || is_link($file)) {
                            continue;
                        }
                        if ($temporary) {
                            // Cooperating writers create temporary files only
                            // while holding this same stable shard lock.
                            @unlink($file);
                            continue;
                        }
                        try {
                            $expired = $this->read($file)->expires <= $this->now();
                        } catch (RateLimitStorageException) {
                            // Do not reset a potentially active corrupted quota.
                            continue;
                        }
                        if ($expired && @unlink($file)) {
                            ++$removed;
                        }
                    } finally {
                        flock($lock, LOCK_UN);
                    }
                }
            } finally {
                fclose($lock);
            }
        }
        return $removed;
    }
}
