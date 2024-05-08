<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Tests\Performance;

use Etrias\PhpToolkit\Performance\Benchmark;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * @internal
 */
final class BenchmarkTest extends TestCase
{
    public function testBench(): void
    {
        $stopwatch = new Stopwatch(true);
        $logger = new Logger('test', [$logHandler = new TestHandler()]);
        $benchmark = new Benchmark($stopwatch, $logger);

        self::assertSame('bench_result', $benchmark->run('outer_bench', static function () use ($benchmark, $logger): mixed {
            $logger->info('run start');
            $result = $benchmark->run('inner_bench', static fn (): string => 'bench_result', ['nested' => true]);
            $logger->info('run end');

            return $result;
        }));

        self::assertStringMatchesFormat(
            <<<'TXT'
                [%s] test.INFO: run start [] []
                [%s] test.INFO: inner_bench (%f MiB - %f s) {"benchmark":"%s","benchmark_group":"%s","memory":%f,"memory_peak":%f,"duration":%f,"nested":true} []
                [%s] test.INFO: run end [] []
                [%s] test.INFO: outer_bench (%f MiB - %f s) {"benchmark":"%s","benchmark_group":"%s","memory":%f,"memory_peak":%f,"duration":%f} []
                TXT,
            implode("\n", array_map(static fn (LogRecord $record): string => trim((string) $record->formatted), $logHandler->getRecords()))
        );
    }
}
