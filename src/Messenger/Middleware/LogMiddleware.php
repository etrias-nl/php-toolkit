<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Middleware;

use Etrias\PhpToolkit\Messenger\Stamp\OriginTransportMessageIdStamp;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

final class LogMiddleware implements MiddlewareInterface, ProcessorInterface
{
    private ?Envelope $currentEnvelope = null;
    private bool $loggedPayload = false;

    public function __invoke(LogRecord $record): LogRecord
    {
        if (null === $this->currentEnvelope) {
            return $record;
        }

        $context = $record->context;
        $context['messenger']['id'] = $this->currentEnvelope->last(TransportMessageIdStamp::class)?->getId();
        $context['messenger']['origin'] = $this->currentEnvelope->last(OriginTransportMessageIdStamp::class)?->id;

        if (!$this->loggedPayload && $record->level->isHigherThan(Level::Debug)) {
            $context['messenger']['payload'] = $this->currentEnvelope->getMessage();
            $this->loggedPayload = true;
        }

        return $record->with(context: $context);
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $prevEnvelope = $this->currentEnvelope;

        if (null !== $prevEnvelope) {
            $envelope = $envelope
                ->withoutAll(OriginTransportMessageIdStamp::class)
                ->with(new OriginTransportMessageIdStamp($prevEnvelope->last(TransportMessageIdStamp::class)?->getId()))
            ;
        }

        $this->currentEnvelope = $envelope;
        $this->loggedPayload = false;

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->currentEnvelope = $prevEnvelope;
            $this->loggedPayload = false;
        }
    }
}
