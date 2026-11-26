<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Throttle;

/**
 * Atomic fixed-window limiter for cooperating processes on a local filesystem.
 * Stable per-shard locks are never removed; expired data files are pruned only
 * while holding the corresponding lock. The directory must be private.
 */
final readonly class FileRateLimiter implements RateLimiterInterface
{
    public function __construct(
        private string $directory,
        private ?\Closure $clock = null,
    ) {
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new \InvalidArgumentException('Rate limit directory must exist and be writable.');
        }
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
            $bucket = is_file($file) ? $this->read($file) : RateLimitBucket::fresh($now, $window);
            if ($bucket->expires <= $now) {
                $bucket = RateLimitBucket::fresh($now, $window);
            }
            [$next, $result] = $bucket->consume($limit, $now);
            if ($result->allowed || !is_file($file)) {
                $this->write($file, $next);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        // Amortize maintenance across shards without scanning all active keys.
        if (random_int(1, 100) === 1) {
            $this->pruneShard(sprintf('%02x', random_int(0, 255)));
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

    private function pruneShard(string $id): void
    {
        $directory = rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'rl-v2-' . $id;
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }
        $lock = @fopen($directory . DIRECTORY_SEPARATOR . '.lock', 'c+b');
        if ($lock === false) {
            return;
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return;
            }
            $now = $this->now();
            foreach (new \DirectoryIterator($directory) as $entry) {
                if (!$entry->isFile() || $entry->isLink()
                    || preg_match('/^[0-9a-f]{64}\.bucket$/D', $entry->getFilename()) !== 1) {
                    continue;
                }
                try {
                    $bucket = $this->read($entry->getPathname());
                    if ($bucket->expires <= $now) {
                        @unlink($entry->getPathname());
                    }
                } catch (RateLimitStorageException) {
                    // Corruption must not reset a potentially active quota.
                }
            }
        } catch (\UnexpectedValueException) {
            // Maintenance is best-effort; enforcement already completed.
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
