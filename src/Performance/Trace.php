<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Performance;

use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Component\Stopwatch\StopwatchEvent;

/**
 * @internal
 */
final class Trace
{
    public readonly string $id;
    public readonly StopwatchEvent $event;

    public function __construct(
        Stopwatch $stopwatch,
        public readonly string $name,
        public readonly array $context = [],
        public readonly ?self $previousTrace = null,
    ) {
        $this->id = uniqid('bench_', true);
        $this->event = $stopwatch->start($this->id.uniqid($this->name, true));
    }

    public function getRootId(): string
    {
        return $this->previousTrace?->getRootId() ?? $this->id;
    }
}
