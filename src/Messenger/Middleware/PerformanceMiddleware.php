<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Middleware;

use Etrias\PhpToolkit\Performance\Benchmark;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class PerformanceMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Benchmark $benchmark,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (null === $envelope->last(ReceivedStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }

        return $this->benchmark->run($envelope->getMessage()::class, static fn (): Envelope => $stack->next()->handle($envelope, $stack));
    }
}
