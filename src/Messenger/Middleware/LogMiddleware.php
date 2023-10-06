<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Middleware;

use Etrias\PhpToolkit\Messenger\Stamp\OriginTransportMessageIdStamp;
use Etrias\PhpToolkit\Messenger\Stamp\SecurityStamp;
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

        $messengerContext = [
            'id' => $this->currentEnvelope->last(TransportMessageIdStamp::class)?->getId(),
            'origin' => $this->currentEnvelope->last(OriginTransportMessageIdStamp::class)?->id,
            'user' => $this->currentEnvelope->last(SecurityStamp::class)?->token->getUserIdentifier(),
        ];

        if (!$this->loggedPayload) {
            $messengerContext['payload'] = $this->currentEnvelope->getMessage();
            $this->loggedPayload = true;
        }

        return $record->with(context: ['messenger' => $messengerContext] + $record->context);
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
