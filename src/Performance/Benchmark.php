<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Performance;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Stopwatch\Stopwatch;

final class Benchmark
{
    private ?string $runId = null;

    public function __construct(
        private readonly Stopwatch $stopwatch,
        #[Target(name: 'benchmark.logger')]
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @template T
     *
     * @param \Closure(): T $callback
     *
     * @return T
     */
    public function run(string $name, \Closure $callback, array $context = []): mixed
    {
        $prevRunId = $this->runId;
        $this->runId ??= uniqid('bench_', true);
        $event = $this->stopwatch->start($this->runId.uniqid($name, true));

        try {
            return $callback();
        } finally {
            $event->stop();
            $memory = $event->getMemory() / 1024 / 1024;
            $duration = $event->getDuration() / 1000;
            $this->logger->info(sprintf('%s (%.2F MiB - %d s)', $name, $memory, $duration), [
                'benchmark' => $this->runId,
                'memory' => $memory,
                'memory_peak' => memory_get_peak_usage() / 1024 / 1024,
                'duration' => $duration,
            ] + $context);
            $this->runId = $prevRunId;
        }
    }
}
