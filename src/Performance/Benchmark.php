<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Performance;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Stopwatch\Stopwatch;

final class Benchmark
{
    private ?Trace $currentTrace = null;

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
        $this->start($name, $context);

        try {
            return $callback();
        } finally {
            $this->stop();
        }
    }

    public function start(string $name, array $context = []): void
    {
        $this->currentTrace = new Trace($this->stopwatch, $name, $context, $this->currentTrace);
    }

    public function stop(array $context = []): void
    {
        if (null === $trace = $this->currentTrace) {
            throw new \LogicException('No benchmark started');
        }

        $trace->event->stop();

        $memory = (float) ($trace->event->getMemory() / 1024 / 1024);
        $duration = (float) ($trace->event->getDuration() / 1000);

        $this->logger->info(sprintf('%s (%.2F MiB - %d s)', $trace->name, $memory, $duration), [
            'benchmark' => $trace->id,
            'benchmark_group' => $trace->getRootId(),
            'memory' => $memory,
            'memory_peak' => memory_get_peak_usage() / 1024 / 1024,
            'duration' => $duration,
        ] + $context + $trace->context);

        $this->currentTrace = $trace->previousTrace;
    }
}
