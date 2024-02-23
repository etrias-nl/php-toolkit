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
        $this->logger->notice('HANDLING MESSAGE', ['payload' => $message->payload]);

        if ($message->sleep) {
            $this->logger->notice('Calling external service to simulate slow process for '.$message->sleep.'s');
            $this->logger->notice(file_get_contents('https://httpstat.us/200?sleep='.($message->sleep * 1000), false, stream_context_create(['http' => ['timeout' => $message->sleep + 1]])));
        }

        if ($message->nest) {
            $this->logger->notice('Dispatching nested message');
            $this->messageBus->dispatch(
                new DummyCommandMessage(['NESTED' => $message->payload], 0, $message->nestFailure),
                $message->nestSync ? [new TransportNamesStamp('sync')] : []
            );
        }

        if ($message->failure) {
            throw new \RuntimeException($message->payload);
        }
    }
}
