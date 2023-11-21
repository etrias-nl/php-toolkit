<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Middleware;

use Etrias\PhpToolkit\Messenger\Stamp\OriginTransportMessageIdStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class LogMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly LogProcessor $processor,
        private readonly NormalizerInterface $normalizer,
    ) {
        $this->processor->normalizer = $this->normalizer;
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $prevEnvelope = $this->processor->currentEnvelope;

        if (null !== $prevEnvelope) {
            $envelope = $envelope
                ->withoutAll(OriginTransportMessageIdStamp::class)
                ->with(new OriginTransportMessageIdStamp($prevEnvelope->last(TransportMessageIdStamp::class)?->getId()))
            ;
        }

        $this->processor->currentEnvelope = $envelope;
        $this->processor->loggedPayload = false;

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->processor->currentEnvelope = $prevEnvelope;
            $this->processor->loggedPayload = false;
        }
    }
}
