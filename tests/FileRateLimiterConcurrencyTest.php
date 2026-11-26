<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Throttle\Tests;

use Componenta\Http\Middleware\Throttle\FileRateLimiter;
use PHPUnit\Framework\TestCase;

final class FileRateLimiterConcurrencyTest extends TestCase
{
    public function testProcessesCannotConsumeMoreThanTheSharedQuota(): void
    {
        if (!function_exists('proc_open') || PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Requires process creation and a local POSIX filesystem.');
        }
        $directory = sys_get_temp_dir() . '/componenta-rate-concurrent-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $processes = [];
        try {
            $autoload = dirname(__DIR__) . '/vendor/autoload.php';
            $program = <<<'PHP'
require $argv[1];
$directory = $argv[2];
$limiter = new \Componenta\Http\Middleware\Throttle\FileRateLimiter($directory, static fn(): int => 100);
$until = microtime(true) + 10;
while (!is_file($directory . '/start')) {
    if (microtime(true) > $until) { exit(3); }
    usleep(1000);
}
$allowed = 0;
for ($i = 0; $i < 20; ++$i) {
    $allowed += (int) $limiter->hit('client', 31, 60)->allowed;
}
echo $allowed;
PHP;
            for ($i = 0; $i < 6; ++$i) {
                $process = proc_open([PHP_BINARY, '-r', $program, $autoload, $directory], [
                    0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
                ], $pipes);
                self::assertIsResource($process);
                fclose($pipes[0]);
                $processes[] = [$process, $pipes[1], $pipes[2]];
            }
            touch($directory . '/start');
            $allowed = 0;
            while ($processes !== []) {
                [$process, $out, $err] = array_pop($processes);
                $output = stream_get_contents($out);
                $errors = stream_get_contents($err);
                fclose($out);
                fclose($err);
                self::assertSame(0, proc_close($process), (string) $errors);
                self::assertMatchesRegularExpression('/^[0-9]+$/D', (string) $output);
                $allowed += (int) $output;
            }
            self::assertSame(31, $allowed);
        } finally {
            foreach ($processes as [$process, $out, $err]) {
                proc_terminate($process);
                fclose($out);
                fclose($err);
                proc_close($process);
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($directory);
        }
    }
}
