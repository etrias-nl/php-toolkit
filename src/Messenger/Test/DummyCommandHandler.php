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
        $this->logger->info('HANDLING MESSAGE: '.json_encode($message->payload));

        if ($message->sleep > 0) {
            $this->logger->info('Sleeping for '.$message->sleep.'s');
            usleep((int) ($message->sleep * 1_000_000));
        }

        if ($message->nest) {
            $this->logger->info('Dispatching nested message');
            $this->messageBus->dispatch(
                new DummyCommandMessage(['NESTED' => $message->payload], $message->sleep, $message->nestFailure),
                $message->nestSync ? [new TransportNamesStamp('sync')] : []
            );
        }

        if ($message->failure) {
            throw new \RuntimeException(json_encode($message->payload));
        }

        $this->logger->info('DONE');
    }
}
