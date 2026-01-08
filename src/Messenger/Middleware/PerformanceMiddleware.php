<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Middleware;

use Etrias\PhpToolkit\Performance\Benchmark;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;

final class PerformanceMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Benchmark $benchmark,
        #[Autowire(service: 'messenger.senders_locator')]
        private readonly SendersLocatorInterface $sendersLocator,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (null === $envelope->last(ReceivedStamp::class)) {
            $hasSender = false;
            foreach ($this->sendersLocator->getSenders($envelope) as $_) {
                $hasSender = true;

                break;
            }

            if ($hasSender) {
                return $stack->next()->handle($envelope, $stack);
            }
        }

        return $this->benchmark->run($envelope->getMessage()::class, static fn (): Envelope => $stack->next()->handle($envelope, $stack));
    }
}
