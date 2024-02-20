<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Middleware;

use Etrias\PhpToolkit\Messenger\Stamp\OriginTransportMessageIdStamp;
use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

#[AsMonologProcessor]
final class LogMiddleware implements MiddlewareInterface
{
    private ?Envelope $currentEnvelope = null;

    public function __invoke(LogRecord $record): LogRecord
    {
        if (null === $this->currentEnvelope) {
            return $record;
        }

        $record->extra['messenger']['id'] = $this->currentEnvelope->last(TransportMessageIdStamp::class)?->getId();
        $record->extra['messenger']['origin'] = $this->currentEnvelope->last(OriginTransportMessageIdStamp::class)?->id;
        $record->extra['messenger']['message'] = $this->currentEnvelope->getMessage()::class;

        return $record;
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $prevEnvelope = $this->currentEnvelope;

        if (null !== $prevEnvelopeId = $prevEnvelope?->last(TransportMessageIdStamp::class)?->getId()) {
            $envelope = $envelope
                ->withoutAll(OriginTransportMessageIdStamp::class)
                ->with(new OriginTransportMessageIdStamp($prevEnvelopeId))
            ;
        }

        $this->currentEnvelope = $envelope;

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->currentEnvelope = $prevEnvelope;
        }
    }
}
