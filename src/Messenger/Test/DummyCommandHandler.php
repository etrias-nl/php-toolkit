<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Test;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

#[AsMessageHandler]
class DummyCommandHandler
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(DummyCommandMessage $message): void
    {
        if ($message->nest) {
            $this->messageBus->dispatch(
                new DummyCommandMessage(['NESTED' => $message->payload], $message->nestFailure),
                $message->nestSync ? [new TransportNamesStamp('sync')] : []
            );
        }

        if ($message->failure) {
            throw new \RuntimeException($message->payload);
        }

        $this->logger->notice('HANDLING MESSAGE', ['payload' => $message->payload]);
    }
}
