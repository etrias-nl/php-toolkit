<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Middleware;

use Etrias\PhpToolkit\Messenger\EnvelopeRegistry;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class CountMiddleware implements MiddlewareInterface, EventSubscriberInterface
{
    public function __construct(
        private readonly EnvelopeRegistry $envelopeRegistry,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            SendMessageToTransportsEvent::class => 'onSendTransports',
        ];
    }

    public function onSendTransports(SendMessageToTransportsEvent $event): void
    {
        foreach ($event->getSenders() as $senderName => $_) {
            $this->envelopeRegistry->delta($senderName, $event->getEnvelope(), 1);
        }
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (null !== $receivedStamp = $envelope->last(ReceivedStamp::class)) {
            $this->envelopeRegistry->delta($receivedStamp->getTransportName(), $envelope, -1);
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
