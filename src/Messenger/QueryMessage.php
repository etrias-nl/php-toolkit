<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

final class QueryMessage
{
    private function __construct() {}

    /**
     * @param StampInterface[] $stamps
     */
    public static function handle(MessageBusInterface $messageBus, object $message, array $stamps = []): mixed
    {
        try {
            $envelope = $messageBus->dispatch($message, $stamps);
        } catch (HandlerFailedException $e) {
            $wrappedExceptions = $e->getWrappedExceptions();
            if (1 === \count($wrappedExceptions)) {
                throw reset($wrappedExceptions);
            }

            throw $e;
        }

        return self::fromEnvelope($envelope);
    }

    public static function fromEnvelope(Envelope $envelope): mixed
    {
        $handledStamps = $envelope->all(HandledStamp::class);

        if (!$handledStamps) {
            throw new \LogicException(sprintf('Message of type "%s" was handled zero times. Exactly one handler is expected when using "%s()".', get_debug_type($envelope->getMessage()), __METHOD__));
        }

        if (\count($handledStamps) > 1) {
            $handlers = implode(', ', array_map(static fn (HandledStamp $stamp): string => sprintf('"%s"', $stamp->getHandlerName()), $handledStamps));

            throw new \LogicException(sprintf('Message of type "%s" was handled multiple times. Only one handler is expected when using "%s()", got %d: %s.', get_debug_type($envelope->getMessage()), __METHOD__, \count($handledStamps), $handlers));
        }

        return $handledStamps[0]->getResult();
    }
}
