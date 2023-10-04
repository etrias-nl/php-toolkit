<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Middleware;

use Etrias\PhpToolkit\Messenger\Stamp\OriginTransportMessageIdStamp;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;

final class LogMiddleware implements MiddlewareInterface, ProcessorInterface
{
    /**
     * @var Envelope[]
     */
    private array $envelopes = [];
    private bool $loggedPayload = false;

    public function __invoke(LogRecord $record): LogRecord
    {
        if (!$this->envelopes) {
            return $record;
        }

        $envelope = reset($this->envelopes);
        $messageIds = array_map(
            static fn (Envelope $envelope) => $envelope->last(TransportMessageIdStamp::class)?->getId(),
            $this->envelopes
        );
        $messengerContext = [
            'id' => reset($messageIds),
            'trace' => array_values(array_filter($messageIds)),
            'origin' => $envelope->last(OriginTransportMessageIdStamp::class)?->id,
        ];

        if (!$this->loggedPayload) {
            $messengerContext['payload'] = (new ObjectNormalizer())->normalize($envelope->getMessage());
            $this->loggedPayload = true;
        }

        return $record->with(context: ['messenger' => $messengerContext] + $record->context);
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $currentMessageId = $this->envelopes ? reset($this->envelopes)->last(TransportMessageIdStamp::class)?->getId() : null;

        if ($currentMessageId) {
            $envelope = $envelope
                ->withoutAll(OriginTransportMessageIdStamp::class)
                ->with(new OriginTransportMessageIdStamp($currentMessageId))
            ;
        }

        array_unshift($this->envelopes, $envelope);
        $this->loggedPayload = false;

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            array_shift($this->envelopes);
            $this->loggedPayload = false;
        }
    }
}
